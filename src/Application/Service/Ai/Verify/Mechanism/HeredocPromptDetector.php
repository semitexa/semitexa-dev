<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Mechanism;

/**
 * Finds prompt text compiled into PHP, which `prompt.catalog` already holds.
 *
 * The capability names this exact shape in its own `replaces` list — "heredoc
 * prompt strings inside the service that sends them" — and until now nothing
 * looked for it. A prompt living in a constant is absent from `prompt:list`,
 * unreachable by `prompt:override`, and cannot be changed without a deploy;
 * measured in a consumer 2026-09-14, six services in one project kept their
 * prompts this way while the same project used the catalog correctly elsewhere.
 *
 * Everything here is decided from PHP TOKENS, never from raw source text. That
 * is not tidiness: every false positive this rule has had came from reading text
 * PHP does not execute. A comment ending `// return <<<PROMPT` was reported as
 * compiled prompt text; an example inside another heredoc was reported as a
 * second prompt; a docblock mentioning `#[AsPrompt]` exempted a whole service
 * and hid a real finding in it. Tokens answer all three by construction: a
 * comment is a comment, heredoc content is content, and an attribute is only an
 * attribute where PHP attaches one.
 *
 * Precision then comes from requiring the word "prompt" in the assignment target
 * or the heredoc label. A heredoc alone says nothing — the same syntax carries
 * SQL, HTML, fixtures and help text — and a one-line string is explicitly what
 * the capability says to leave alone ("one short fixed instruction used in
 * exactly one place"), so the multi-line form is the signal, not a proxy for it.
 *
 * Measured when written: 0 occurrences in this repository's `src/modules`, so
 * like the inline-handler rule it ships silent here and exists for the consumer
 * projects that are its audience.
 */
final class HeredocPromptDetector implements MechanismDetectorInterface
{
    /**
     * Heredoc labels that name a language rather than an intent.
     *
     * Only consulted when the signal came from the TARGET: `$sqlPrompt = <<<SQL`
     * is a variable whose name collides with the word, not a prompt, and firing
     * there is the expensive kind of wrong. A label that says PROMPT is believed
     * whatever it is assigned to.
     */
    private const LANGUAGE_LABELS = ['SQL', 'HTML', 'XML', 'JSON', 'CSS', 'JS', 'JAVASCRIPT', 'YAML', 'YML', 'CSV'];

    /**
     * Names that mark a file as talking to a model.
     *
     * The word "prompt" means two things in English, and only one of them is
     * this capability's: `CONFIRMATION_PROMPT = <<<TXT` holding a CLI question
     * is not a model prompt, and failing verification over it recommends a
     * catalog the code has no use for. Asking for actual proof — that the text
     * reaches an LLM call — needs dataflow this rule does not do, so the
     * corroboration is at file level: does anything here face a model at all?
     *
     * Deliberately framework-anchored rather than a vocabulary of generic verbs
     * like `chat` or `complete`, which would drift straight back into guessing.
     * A consumer wiring a model through none of these names loses the finding;
     * that is the quieter error, and it is the same trade this rule makes
     * everywhere else.
     */
    private const MODEL_FACING = ['llm', 'promptrenderer', 'promptrepository', 'asaiskill', 'aipersona', 'ollama', 'openai', 'anthropic', 'gemini'];

    /** Assignment operators that still carry the target's intent — `.=` appends a prompt. */
    private const ASSIGNMENTS = ['=', '.=', '??=', '=>', ':'];

    /** @return non-empty-list<string> */
    public function extensions(): array
    {
        return ['php'];
    }

    /**
     * @param list<string> $lines
     * @return list<MechanismFinding>
     */
    public function detect(string $file, array $lines): array
    {
        if (self::isTestFile($file)) {
            return [];
        }

        $lineOffset = 0;
        $tokens = self::tokenize($lines, $lineOffset);
        if ($tokens === []) {
            // Nothing parseable to judge. Reporting from raw text here is how
            // every false positive this rule has had was born.
            return [];
        }

        // What a name means in this file is its own question, and the one that
        // grew under review — see CatalogPromptDeclarations.
        $exempt = CatalogPromptDeclarations::classRanges($tokens);

        if (!self::facesAModel($tokens)) {
            return [];
        }

        $findings = [];
        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || $token[0] !== T_START_HEREDOC) {
                continue;
            }

            if (CatalogPromptDeclarations::within($exempt, $i)) {
                continue;
            }

            $label = self::labelOf($token[1]);
            $target = self::targetBefore($tokens, $i);

            $labelSaysPrompt = stripos($label, 'prompt') !== false;
            $targetSaysPrompt = $target !== '' && stripos($target, 'prompt') !== false;

            // Either name may carry the intent: someone writing
            // `const SYSTEM = <<<PROMPT` said it in the label rather than the
            // constant. Requiring both would miss half of them; requiring
            // neither would report every heredoc in the project.
            if (!$labelSaysPrompt && !$targetSaysPrompt) {
                continue;
            }

            if (!$labelSaysPrompt && \in_array(strtoupper($label), self::LANGUAGE_LABELS, true)) {
                continue;
            }

            $line = $token[2] + $lineOffset;
            $findings[] = new MechanismFinding(
                file: $file,
                line: $line,
                capabilityId: 'prompt.catalog',
                evidence: sprintf(
                    '%s heredoc at line %d — prompt text compiled into PHP, so it is absent from prompt:list and cannot be overridden without a deploy',
                    $target !== '' ? $target : '<<<' . $label,
                    $line,
                ),
            );
        }

        return $findings;
    }

    /**
     * Does anything in this file face a model?
     *
     * Read from code tokens only — names, strings and variables — so a comment
     * mentioning an LLM does not qualify a file whose code never talks to one.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function facesAModel(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (!\is_array($token)) {
                continue;
            }

            if (\in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                continue;
            }

            $text = strtolower($token[1]);
            foreach (self::MODEL_FACING as $needle) {
                if (str_contains($text, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string> $lines
     * @return list<array{0: int, 1: string, 2: int}>|list<array{0: int, 1: string, 2: int}|string>
     */
    private static function tokenize(array $lines, int &$lineOffset): array
    {
        $source = implode("\n", $lines);
        $lineOffset = 0;
        if (!str_contains($source, '<?php')) {
            // The lint feeds whole files, but the seam takes a line list, so a
            // fragment has to be made tokenizable. The opener costs one line.
            $source = "<?php\n" . $source;
            $lineOffset = -1;
        }

        try {
            /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
            $tokens = @token_get_all($source);
        } catch (\Throwable) {
            return [];
        }

        return $tokens;
    }

    /** `<<<TXT`, `<<<'TXT'` and `<<<"TXT"` all name TXT. */
    private static function labelOf(string $startHeredoc): string
    {
        return preg_match('/<<<\s*[\'"]?([A-Za-z_][A-Za-z0-9_]*)/', $startHeredoc, $m) === 1 ? $m[1] : '';
    }

    /**
     * The name a heredoc is being assigned to, or '' for a bare opener such as
     * `return <<<PROMPT` or `$llm->complete(<<<PROMPT`.
     *
     * Collects EVERY assignment target between the opener and the start of the
     * statement, innermost first, and prefers one that carries the word. A
     * heredoc can sit inside a nested target — `$systemPrompt = ['content' =>
     * <<<TXT` — where the innermost name is `content` and the signal is in the
     * outer one; stopping at the first assignment found read the wrong half and
     * missed the prompt whenever the label was generic.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function targetBefore(array $tokens, int $index): string
    {
        $candidates = self::assignmentTargets($tokens, $index);

        foreach ($candidates as $candidate) {
            if (stripos($candidate, 'prompt') !== false) {
                return $candidate;
            }
        }

        return $candidates[0] ?? '';
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<string>
     */
    private static function assignmentTargets(array $tokens, int $index): array
    {
        $targets = [];
        $cursor = $index;

        // Bounded: a statement with more than a handful of nested assignments is
        // not a shape this rule needs to understand.
        for ($depth = 0; $depth < 8; $depth++) {
            $operator = self::assignmentBefore($tokens, $cursor);
            if ($operator === null) {
                break;
            }

            $name = self::previousMeaningful($tokens, $operator);
            if ($name === null) {
                break;
            }

            while ($name !== null && self::text($tokens[$name]) === ']') {
                $name = self::beforeSubscript($tokens, $name);
            }

            if ($name === null) {
                break;
            }

            $targets[] = trim(self::text($tokens[$name]), "'\"$");
            $cursor = $name;
        }

        return $targets;
    }

    /**
     * The assignment operator this heredoc is being written through, if any.
     *
     * Scans back to the start of the statement rather than looking at the single
     * token before the opener, because a heredoc is often part of a larger
     * expression: `$systemPrompt = $prefix . <<<TXT` puts a `.` immediately
     * before it, and requiring adjacency silently missed the prompt whenever the
     * label was generic. A statement boundary stops the walk, so a bare
     * `return <<<PROMPT` still reports no target rather than borrowing one from
     * the line above.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function assignmentBefore(array $tokens, int $index): ?int
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (\is_array($token)) {
                if (\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (\in_array($token[1], self::ASSIGNMENTS, true)) {
                    return $i;
                }
                continue;
            }

            $text = self::text($token);
            if (\in_array($text, self::ASSIGNMENTS, true)) {
                return $i;
            }
            if ($text === ';' || $text === '{' || $text === '}') {
                return null;
            }
        }

        return null;
    }

    /**
     * The token holding the name a subscript belongs to: from the closing `]`,
     * back over its balanced contents and past the opening `[`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function beforeSubscript(array $tokens, int $closing): ?int
    {
        $depth = 0;
        for ($i = $closing; $i >= 0; $i--) {
            $text = self::text($tokens[$i]);
            if ($text === ']') {
                $depth++;
                continue;
            }
            if ($text === '[') {
                $depth--;
                if ($depth === 0) {
                    return self::previousMeaningful($tokens, $i);
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function previousMeaningful(array $tokens, int $index): ?int
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @param array{0: int, 1: string, 2: int}|string $token */
    private static function text(array|string $token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }

    /**
     * Prompt-shaped text in a test is a fixture: it is not sent anywhere, and
     * moving it to the catalog would only make the test depend on discovery.
     *
     * Anchored on a path SEGMENT: `--path=tests` reports names like
     * `tests/Fixtures/PromptFixture.php`, which contain no `/tests/` and need not
     * end in Test.php, so a substring test missed exactly the files it is for.
     * `contests/` must still not match, hence the boundary.
     */
    private static function isTestFile(string $file): bool
    {
        return preg_match('#(^|/)tests/#', $file) === 1 || str_ends_with($file, 'Test.php');
    }
}

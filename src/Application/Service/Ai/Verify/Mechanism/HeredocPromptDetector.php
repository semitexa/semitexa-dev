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
 * Precision comes from requiring TWO things on one line: a heredoc or nowdoc
 * opener, and the word "prompt" in either the assignment target or the heredoc
 * label. A heredoc alone says nothing — the same syntax carries SQL, HTML,
 * fixtures and CLI help — and a one-line string is explicitly what the
 * capability says to leave alone ("one short fixed instruction used in exactly
 * one place"), so the multi-line form is the signal, not a proxy for it.
 *
 * Measured when written: 0 occurrences in this repository's `src/modules`, so
 * like the inline-handler rule it ships silent here and exists for the consumer
 * projects that are its audience.
 */
final class HeredocPromptDetector implements MechanismDetectorInterface
{
    /**
     * A heredoc/nowdoc opener with an assignment target, e.g.
     * `const SYSTEM_PROMPT = <<<TXT`, `$prompt = <<<'EOT'`, `system: <<<PROMPT`.
     *
     * Compound forms count: `$systemPrompt .= <<<TXT` appends prompt text and
     * carries the same target signal as the simple assignment. Without them the
     * BARE branch took over, saw only a generic label, and the target was lost.
     */
    private const ASSIGNED = '/(?:const\s+|\$|->|::|[\'"]|\b)([A-Za-z_][A-Za-z0-9_]*)[\'"]?\s*(?:\.=|\?\?=|=>|=|:)\s*<<<([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\2\s*$/';

    /**
     * A heredoc/nowdoc opener with NO assignment target — `return <<<PROMPT`,
     * `$this->llm->complete(<<<PROMPT`, `[<<<PROMPT`.
     *
     * Its own branch because these are the two commonest ways a service inlines
     * a prompt and the assignment form cannot see either. The first version of
     * this rule promised in its docblock to match on the label alone and then
     * required an assignment anyway: it read as a passing check over exactly the
     * code it was written to find, which is the failure the planner comment
     * beside KIND_SERVICE warns about.
     */
    private const BARE = '/(?:^|[\s(\[,=.]|=>)<<<([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\1\s*$/';

    /**
     * Heredoc labels that name a language rather than an intent.
     *
     * Only consulted when the signal came from the TARGET: `$sqlPrompt = <<<SQL`
     * is a variable whose name collides with the word, not a prompt, and firing
     * there is the expensive kind of wrong. A label that says PROMPT is believed
     * whatever it is assigned to.
     */
    private const LANGUAGE_LABELS = ['SQL', 'HTML', 'XML', 'JSON', 'CSS', 'JS', 'JAVASCRIPT', 'YAML', 'YML', 'CSV'];

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
        if (self::isCatalogDefinition($lines) || self::isTestFile($file)) {
            return [];
        }

        $commentAt = self::commentColumns($lines);
        $findings = [];

        foreach ($lines as $index => $line) {
            $trimmed = rtrim($line);

            // A heredoc opener written INSIDE a comment is documentation, not
            // compiled prompt text — `// example: $prompt = <<<TXT` is somebody
            // explaining the mistake, and failing verification over it is the
            // rule punishing the person writing it down. Position matters, not
            // just the line: code with a trailing comment is still code.
            $opener = strpos($trimmed, '<<<');
            if ($opener !== false && isset($commentAt[$index]) && $commentAt[$index] <= $opener) {
                continue;
            }

            if (preg_match(self::ASSIGNED, $trimmed, $m) === 1) {
                $target = $m[1];
                $label = $m[3];
            } elseif (preg_match(self::BARE, $trimmed, $m) === 1) {
                $target = '';
                $label = $m[2];
            } else {
                continue;
            }

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

            $findings[] = new MechanismFinding(
                file: $file,
                line: $index + 1,
                capabilityId: 'prompt.catalog',
                evidence: sprintf(
                    '%s heredoc at line %d — prompt text compiled into PHP, so it is absent from prompt:list and cannot be overridden without a deploy',
                    $target !== '' ? $target : '<<<' . $label,
                    $index + 1,
                ),
            );
        }

        return $findings;
    }

    /**
     * A file that DECLARES a catalog prompt is the mechanism, not a duplicate of
     * it — including one still on the legacy `PromptDefinitionInterface::system()`
     * path, where a heredoc body is the supported migration shape. Firing there
     * would point at the correct solution and call it the mistake.
     *
     * Matched against the JOINED source, not line by line: a declaration wrapped
     * as `class Foo implements` / `PromptDefinitionInterface` is the same
     * declaration, and a per-line test read it as a service hand-rolling the
     * mechanism it actually implements. The character class admits only an
     * identifier list, so the match cannot wander into a class body.
     *
     * The attribute must be APPLIED and the interface IMPLEMENTED — not merely
     * named. A substring test exempted a whole file on a `use` import, a docblock
     * {@see}, or a variable called $formattedAsPrompt.
     *
     * @param list<string> $lines
     */
    private static function isCatalogDefinition(array $lines): bool
    {
        $source = implode("\n", $lines);

        // Short name, fully qualified (`#[\Semitexa\Prompt\Attribute\AsPrompt]`)
        // or imported under an alias — all three are the same declaration, and a
        // literal short-name test reported a real prompt class as a hand-rolled
        // copy of the mechanism it implements.
        $attributeNames = ['AsPrompt', ...self::aliasesOf($source, 'AsPrompt')];
        foreach ($attributeNames as $name) {
            $pattern = '/#\[\s*\\\\?(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*' . preg_quote($name, '/') . '\s*[(\]]/';
            if (preg_match($pattern, $source) === 1) {
                return true;
            }
        }

        $interfaceNames = ['PromptDefinitionInterface', ...self::aliasesOf($source, 'PromptDefinitionInterface')];
        foreach ($interfaceNames as $name) {
            $pattern = '/\bimplements\s[\sA-Za-z0-9_\\\\,]*\b' . preg_quote($name, '/') . '\b/';
            if (preg_match($pattern, $source) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Local aliases a `use ... as X;` statement gives a symbol.
     *
     * @return list<string>
     */
    private static function aliasesOf(string $source, string $symbol): array
    {
        $pattern = '/\buse\s+[A-Za-z0-9_\\\\]*\b' . preg_quote($symbol, '/') . '\s+as\s+([A-Za-z_][A-Za-z0-9_]*)\s*;/';
        if (preg_match_all($pattern, $source, $matches) < 1) {
            return [];
        }

        /** @var list<string> $aliases */
        $aliases = array_values(array_unique($matches[1]));

        return $aliases;
    }

    /**
     * Where a comment begins on each line, by line index — the offset a match
     * must sit before to count as code.
     *
     * Tokenized rather than pattern-matched: `//` inside a string literal does
     * not start a comment, and a `/* *\/` block spans lines. A source that will
     * not tokenize (a fragment, a parse error) yields no spans, so the rule
     * behaves exactly as it did before rather than silently going quiet.
     *
     * @param list<string> $lines
     * @return array<int, int>
     */
    private static function commentColumns(array $lines): array
    {
        $source = implode("\n", $lines);
        if (!str_contains($source, '<?php')) {
            $source = "<?php\n" . $source;
            $lineOffset = -1;
        } else {
            $lineOffset = 0;
        }

        try {
            $tokens = @token_get_all($source);
        } catch (\Throwable) {
            return [];
        }

        $sourceLines = explode("\n", $source);
        $columns = [];
        foreach ($tokens as $token) {
            if (!\is_array($token) || !\in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $text = $token[1];
            $startLine = $token[2];
            $column = 0;
            if (isset($sourceLines[$startLine - 1])) {
                $found = strpos($sourceLines[$startLine - 1], strtok($text, "\n") ?: $text);
                $column = $found === false ? 0 : $found;
            }

            $lineCount = substr_count($text, "\n");
            for ($i = 0; $i <= $lineCount; $i++) {
                $index = $startLine + $i + $lineOffset - 1;
                // A multi-line comment covers its later lines entirely.
                $at = $i === 0 ? $column : 0;
                if (!isset($columns[$index]) || $at < $columns[$index]) {
                    $columns[$index] = $at;
                }
            }
        }

        return $columns;
    }

    /**
     * Prompt-shaped text in a test is a fixture: it is not sent anywhere, and
     * moving it to the catalog would only make the test depend on discovery.
     */
    private static function isTestFile(string $file): bool
    {
        // Anchored on a path SEGMENT, not a substring with slashes on both sides:
        // `--path=tests` makes the lint report names like
        // `tests/Fixtures/PromptFixture.php`, which contain no `/tests/` and need
        // not end in Test.php, so the exemption missed exactly the files it is
        // for. `contests/` must still not match, hence the segment boundary.
        return preg_match('#(^|/)tests/#', $file) === 1 || str_ends_with($file, 'Test.php');
    }
}

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

    /** The symbols that mark a file as the mechanism, fully qualified. */
    private const ATTRIBUTE_FQCN = 'Semitexa\\Prompt\\Attribute\\AsPrompt';
    private const INTERFACE_FQCN = 'Semitexa\\Prompt\\Domain\\Contract\\PromptDefinitionInterface';

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

        if (self::declaresACatalogPrompt($tokens)) {
            return [];
        }

        $findings = [];
        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || $token[0] !== T_START_HEREDOC) {
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
     * @param list<string> $lines
     * @return list<array{0: int, 1: string, 2: int}|string>
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
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function targetBefore(array $tokens, int $index): string
    {
        $operator = self::previousMeaningful($tokens, $index);
        if ($operator === null || !\in_array(self::text($tokens[$operator]), self::ASSIGNMENTS, true)) {
            return '';
        }

        $name = self::previousMeaningful($tokens, $operator);
        if ($name === null) {
            return '';
        }

        // An indexed target — `$systemPrompts[] = <<<TXT`, `$prompts['system'] =
        // <<<TXT` — ends in `]`, so the token before the operator is a bracket
        // and the prompt-bearing name sits before the subscript. Walk the
        // balanced brackets back to it.
        while (self::text($tokens[$name]) === ']') {
            $name = self::beforeSubscript($tokens, $name);
            if ($name === null) {
                return '';
            }
        }

        $text = self::text($tokens[$name]);

        return trim($text, "'\"$");
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
     * Does this file DECLARE a catalog prompt? Then it is the mechanism, not a
     * duplicate of it — including one still on the legacy
     * `PromptDefinitionInterface::system()` path, where a heredoc body is the
     * supported migration shape. Firing there would point at the correct
     * solution and call it the mistake.
     *
     * Read from tokens: an `#[AsPrompt]` written in a docblock to document a
     * migration is prose, and exempting a whole service for it silently hid the
     * real findings that service had. `T_ATTRIBUTE` is emitted only where PHP
     * attaches an attribute. Qualified names, aliased imports and declarations
     * wrapped across lines all fall out of this for free.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function declaresACatalogPrompt(array $tokens): bool
    {
        $aliases = self::aliasMap($tokens);

        foreach ($tokens as $i => $token) {
            if (!\is_array($token)) {
                continue;
            }

            if ($token[0] === T_ATTRIBUTE) {
                foreach (self::attributeNames($tokens, $i) as $name) {
                    if (self::resolves($name, self::ATTRIBUTE_FQCN, $aliases)) {
                        return true;
                    }
                }
                continue;
            }

            if ($token[0] !== T_IMPLEMENTS) {
                continue;
            }

            for ($j = $i + 1; $j < \count($tokens); $j++) {
                $candidate = $tokens[$j];
                if (!\is_array($candidate)) {
                    if (self::text($candidate) === '{') {
                        break;
                    }
                    continue;
                }
                if (\in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (self::resolves($candidate[1], self::INTERFACE_FQCN, $aliases)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every attribute name in one `#[...]` group.
     *
     * PHP allows several in a group — `#[Other, AsPrompt(id: 'x')]` — so reading
     * only the first token missed the declaration and reported a real prompt
     * class for the body it is supposed to have. Argument lists are skipped by
     * depth, since a name inside `Other(AsPrompt::class)` is an argument, not an
     * applied attribute.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<string>
     */
    private static function attributeNames(array $tokens, int $index): array
    {
        $names = [];
        $depth = 0;
        $expectName = true;
        $count = \count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!\is_array($token)) {
                $literal = self::text($token);
                if ($literal === '(' || $literal === '[') {
                    $depth++;
                    continue;
                }
                if ($literal === ')') {
                    $depth--;
                    continue;
                }
                if ($literal === ']') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                    continue;
                }
                if ($literal === ',' && $depth === 0) {
                    $expectName = true;
                }
                continue;
            }

            if (\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($depth === 0 && $expectName) {
                $names[] = $token[1];
                $expectName = false;
            }
        }

        return $names;
    }

    /**
     * Does $name, as written in this file, refer to $fqcn?
     *
     * Resolved through the file's own imports rather than by short name, because
     * a short name is not an identity: `use Vendor\Ui\AsPrompt as CatalogPrompt;`
     * is a DIFFERENT attribute, and exempting a file for it would hide every real
     * prompt heredoc in that service. Imports carry the full namespace for
     * exactly this comparison.
     *
     * The fallback is the one case no import can settle: a bare short name with
     * no matching import resolves against the file's own namespace, which needs
     * resolution this rule does not do. Erring toward exemption there costs a
     * missed finding; erring the other way reports a genuine prompt class for
     * the body it is supposed to have, which is the louder mistake.
     *
     * @param array<string, string> $imports local name => fully-qualified symbol
     */
    private static function resolves(string $name, string $fqcn, array $imports): bool
    {
        $name = ltrim($name, '\\');

        if (isset($imports[$name])) {
            return $imports[$name] === $fqcn;
        }

        if (str_contains($name, '\\')) {
            // Fully qualified as written, or qualified through an imported
            // prefix (`use Semitexa\Prompt; ... #[Prompt\Attribute\AsPrompt]`).
            $segments = explode('\\', $name);
            $first = array_shift($segments);
            $resolved = isset($imports[$first])
                ? $imports[$first] . '\\' . implode('\\', $segments)
                : $name;

            return $resolved === $fqcn;
        }

        return $name === self::shortNameOf($fqcn);
    }

    private static function shortNameOf(string $fqcn): string
    {
        $short = strrchr($fqcn, '\\');

        return $short === false ? $fqcn : substr($short, 1);
    }

    /**
     * Local names introduced by `use`, mapped to the FULLY QUALIFIED symbol.
     *
     * One statement can introduce several names —
     * `use A\B\{AsPrompt as CatalogPrompt, Other};` and
     * `use A\B as X, C\D as Y;` — so this walks each statement to its `;`
     * instead of stopping at the first alias it finds. A grouped import that
     * aliased the attribute previously yielded no alias at all, and the class
     * using it was reported for hand-rolling the mechanism it implements.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * Plain imports are recorded too, not only aliased ones: `use Vendor\Ui\AsPrompt;`
     * makes the short name mean something other than the catalog attribute, and
     * only the full namespace can tell the two apart.
     *
     * @return array<string, string> local name => fully-qualified symbol
     */
    private static function aliasMap(array $tokens): array
    {
        $aliases = [];
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            // One entry at a time: the last name seen is what an `as` renames,
            // and a comma or a brace ends the entry without ending the statement.
            $lastName = null;
            $expectAlias = false;
            $prefix = '';

            $record = static function (?string $symbol, ?string $local) use (&$aliases): void {
                if ($symbol === null) {
                    return;
                }
                $aliases[$local ?? self::shortNameOf($symbol)] = $symbol;
            };

            for ($j = $i + 1; $j < $count; $j++) {
                $candidate = $tokens[$j];

                if (!\is_array($candidate)) {
                    $literal = self::text($candidate);
                    if ($literal === ';') {
                        if (!$expectAlias) {
                            $record($lastName === null ? null : self::join($prefix, $lastName), null);
                        }
                        $i = $j;
                        break;
                    }
                    if ($literal === '{') {
                        // Everything before the brace was the group prefix.
                        $prefix = $lastName ?? '';
                        $lastName = null;
                        $expectAlias = false;
                        continue;
                    }
                    if ($literal === ',' || $literal === '}') {
                        if (!$expectAlias) {
                            $record($lastName === null ? null : self::join($prefix, $lastName), null);
                        }
                        if ($literal === '}') {
                            $prefix = '';
                        }
                        $lastName = null;
                        $expectAlias = false;
                    }
                    continue;
                }

                if (\in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_NS_SEPARATOR], true)) {
                    continue;
                }

                if ($candidate[0] === T_AS) {
                    $expectAlias = true;
                    continue;
                }

                if ($expectAlias && $lastName !== null) {
                    $record(self::join($prefix, $lastName), $candidate[1]);
                    $lastName = null;
                    $expectAlias = false;
                    continue;
                }

                // A grouped import's prefix and its entries arrive as separate
                // name tokens; the entry is the one an `as` can rename, so the
                // most recent name wins.
                $lastName = $candidate[1];
            }
        }

        return $aliases;
    }

    private static function join(string $prefix, string $name): string
    {
        $prefix = trim($prefix, '\\');

        return $prefix === '' ? ltrim($name, '\\') : $prefix . '\\' . ltrim($name, '\\');
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

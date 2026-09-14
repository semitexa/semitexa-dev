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
     */
    private const ASSIGNED = '/(?:const\s+|\$|->|::|[\'"]|\b)([A-Za-z_][A-Za-z0-9_]*)[\'"]?\s*(?:=>|=|:)\s*<<<([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\2\s*$/';

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

        $findings = [];

        foreach ($lines as $index => $line) {
            $trimmed = rtrim($line);

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
     * @param list<string> $lines
     */
    private static function isCatalogDefinition(array $lines): bool
    {
        foreach ($lines as $line) {
            // The attribute APPLIED and the interface IMPLEMENTED — not merely
            // named. A substring test exempted a whole file on a `use` import, a
            // docblock {@see}, or a variable called $formattedAsPrompt, so a
            // service that mentioned the mechanism in a comment became
            // permanently invisible to the rule that looks for its absence.
            if (preg_match('/#\[\s*AsPrompt\s*[(\]]/', $line) === 1) {
                return true;
            }
            if (preg_match('/\bimplements\b[^{]*\bPromptDefinitionInterface\b/', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prompt-shaped text in a test is a fixture: it is not sent anywhere, and
     * moving it to the catalog would only make the test depend on discovery.
     */
    private static function isTestFile(string $file): bool
    {
        return str_contains($file, '/tests/') || str_ends_with($file, 'Test.php');
    }
}

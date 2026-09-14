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
     * A heredoc/nowdoc opener with its assignment target, e.g.
     * `const SYSTEM_PROMPT = <<<TXT`, `$prompt = <<<'EOT'`, `system: <<<PROMPT`.
     */
    private const OPENER = '/(?:const\s+|\$|->|::|[\'"]|\b)([A-Za-z_][A-Za-z0-9_]*)[\'"]?\s*(?:=>|=|:)\s*<<<([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\2\s*$/';

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
            if (preg_match(self::OPENER, rtrim($line), $m) !== 1) {
                continue;
            }

            $target = $m[1];
            $label = $m[3];

            // Either name may carry the intent: someone writing
            // `const SYSTEM = <<<PROMPT` said it in the label rather than the
            // constant. Requiring both would miss half of them; requiring
            // neither would report every heredoc in the project.
            if (stripos($target, 'prompt') === false && stripos($label, 'prompt') === false) {
                continue;
            }

            $findings[] = new MechanismFinding(
                file: $file,
                line: $index + 1,
                capabilityId: 'prompt.catalog',
                evidence: sprintf(
                    '%s heredoc at line %d — prompt text compiled into PHP, so it is absent from prompt:list and cannot be overridden without a deploy',
                    $target,
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
            if (str_contains($line, 'AsPrompt') || str_contains($line, 'PromptDefinitionInterface')) {
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

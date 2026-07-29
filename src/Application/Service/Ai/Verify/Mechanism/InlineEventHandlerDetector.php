<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Mechanism;

/**
 * Finds interactions wired through inline `on*` attributes in module templates.
 *
 * `#[AsUiBehavior]` binds the same interaction declaratively through a
 * `ui-<alias>` attribute, with the script collected as an asset. The inline form
 * is not merely less tidy: the Content-Security-Policy this framework ships
 * blocks inline handlers, so the interaction silently does nothing in
 * production while working locally for whoever disabled CSP.
 *
 * Matches an HTML event attribute specifically — `onclick="…"` — and not
 * `data-on-click`, `ui-on`, or the word appearing in text. Measured when
 * written: 0 occurrences anywhere in this repository, so it ships silent here
 * and exists for the consumer projects that are its actual audience.
 */
final class InlineEventHandlerDetector implements MechanismDetectorInterface
{
    public function extension(): string
    {
        return 'twig';
    }

    /**
     * @param list<string> $lines
     * @return list<MechanismFinding>
     */
    public function detect(string $file, array $lines): array
    {
        $findings = [];

        foreach ($lines as $index => $line) {
            // Require the attribute to be preceded by whitespace and followed by
            // `=` and a quote: that is an HTML event attribute, and it excludes
            // `data-onclick`, `ui-on:click` and prose.
            // Any `on*` event attribute, not an enumerated list. The first
            // version listed nine names and promptly missed `onmouseout` sitting
            // one line below an `onmouseover` it did catch — an enumeration of a
            // growing vocabulary is always one entry behind.
            //
            // Requiring whitespace before `on` keeps `data-onclick` and the
            // framework's own `ui-on:click` out, and requiring three more letters
            // keeps short lookalikes (`once=`) out.
            if (preg_match('/\son([a-z]{3,})\s*=\s*["\']/i', $line, $m) !== 1) {
                continue;
            }

            $findings[] = new MechanismFinding(
                file: $file,
                line: $index + 1,
                capabilityId: 'ui.behavior',
                evidence: sprintf(
                    'inline on%s= handler at line %d — blocked by the shipped Content-Security-Policy',
                    strtolower($m[1]),
                    $index + 1,
                ),
            );
        }

        return $findings;
    }
}

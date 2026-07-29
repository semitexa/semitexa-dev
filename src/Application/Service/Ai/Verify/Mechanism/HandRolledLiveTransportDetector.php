<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Mechanism;

/**
 * Finds live updates wired by hand in application JavaScript.
 *
 * Two shapes, both of which `#[WithTransport]` already provides — with cross
 * worker delivery, reconnection and per-tenant isolation that a hand-rolled
 * client does not get:
 *
 *  - a raw `new EventSource(...)` or `new WebSocket(...)`, which needs a bespoke
 *    endpoint on the other end;
 *  - `setInterval` around a `fetch`, which is polling: the same answer
 *    re-requested on a timer, paying for every viewer whether anything changed
 *    or not.
 *
 * `EventSource` is the highest-precision signal in this whole rule set — the
 * class exists for exactly one purpose, so its presence in application code is
 * not open to interpretation. Measured when written: 0 occurrences in
 * `src/modules`, 6 inside framework packages, which are the implementations of
 * the mechanism and correctly out of scope.
 */
final class HandRolledLiveTransportDetector implements MechanismDetectorInterface
{
    /**
     * Lines allowed between the timer and the request it repeats.
     *
     * A `setInterval` far away from any `fetch` is a timer, not polling —
     * an animation tick or a countdown, and reporting it would be the rule
     * inventing an intent it cannot see.
     */
    private const POLL_PROXIMITY_LINES = 15;

    public function extension(): string
    {
        return 'js';
    }

    /**
     * @param list<string> $lines
     * @return list<MechanismFinding>
     */
    public function detect(string $file, array $lines): array
    {
        $findings = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/\bnew\s+(EventSource|WebSocket)\s*\(/', $line, $m) === 1) {
                $findings[] = new MechanismFinding(
                    file: $file,
                    line: $index + 1,
                    capabilityId: 'ssr.transport',
                    evidence: sprintf('new %s(...) at line %d — a live channel opened by hand', $m[1], $index + 1),
                );
                continue;
            }

            if (preg_match('/\bsetInterval\s*\(/', $line) !== 1) {
                continue;
            }

            $fetchLine = self::fetchWithin($lines, $index, self::POLL_PROXIMITY_LINES);
            if ($fetchLine === null) {
                continue;
            }

            $findings[] = new MechanismFinding(
                file: $file,
                line: $index + 1,
                capabilityId: 'ssr.transport',
                evidence: sprintf(
                    'setInterval at line %d repeating a fetch at line %d — polling',
                    $index + 1,
                    $fetchLine,
                ),
            );
        }

        return $findings;
    }

    /**
     * @param list<string> $lines
     * @return int|null 1-based line of the repeated request
     */
    private static function fetchWithin(array $lines, int $from, int $window): ?int
    {
        $last = min(count($lines) - 1, $from + $window);
        for ($i = $from; $i <= $last; $i++) {
            if (preg_match('/\bfetch\s*\(|\bXMLHttpRequest\b/', $lines[$i]) === 1) {
                return $i + 1;
            }
        }

        return null;
    }
}

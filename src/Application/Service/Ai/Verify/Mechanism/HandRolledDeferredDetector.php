<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Mechanism;

/**
 * Finds a deferred region assembled by hand in application JavaScript.
 *
 * The shape it looks for is narrow on purpose: a `fetch()` at a same-origin
 * path whose result is written into `innerHTML` shortly afterwards. That is
 * server-rendered content being fetched and injected by the client — precisely
 * what `#[AsDeferred]` already does, with a skeleton, a cache TTL and no bespoke
 * endpoint.
 *
 * Three deliberate limits, because a rule that fires on plausible code gets the
 * whole channel muted and then the real findings go unread too:
 *
 *  1. **Application code only.** Framework packages implement these mechanisms;
 *     `fetch` + `innerHTML` inside a transport runtime is the implementation,
 *     not a duplicate of it. The audience is someone building a UI in a module.
 *  2. **Both signals, near each other.** A file that merely contains a `fetch`
 *     somewhere and an `innerHTML` somewhere else is not evidence. They must
 *     fall within a short window, which is what suggests the response feeds the
 *     markup.
 *  3. **Same-origin literal paths only.** A call to a third-party API is not a
 *     region the framework could have rendered, and must never be reported.
 *
 * Measured against this repository when written: zero hits in `src/modules`,
 * which is the correct answer — nothing there hand-rolls deferred rendering
 * today. The detector is proven by a fixture instead, because a rule that has
 * never fired is a rule nobody has tested.
 */
final class HandRolledDeferredDetector implements MechanismDetectorInterface
{
    public function extension(): string
    {
        return 'js';
    }

    /**
     * Lines allowed between the fetch and the markup write.
     *
     * Wide enough for a `then`/`await` plus response handling, narrow enough
     * that two unrelated features in one file do not get joined into a finding.
     */
    private const PROXIMITY_LINES = 25;

    /**
     * @param list<string> $lines
     * @return list<MechanismFinding>
     */
    public function detect(string $file, array $lines): array
    {
        $findings = [];

        foreach ($lines as $index => $line) {
            $path = self::sameOriginFetchPath($line);
            if ($path === null) {
                continue;
            }

            $markupLine = self::markupWriteWithin($lines, $index, self::PROXIMITY_LINES);
            if ($markupLine === null) {
                continue;
            }

            $findings[] = new MechanismFinding(
                file: $file,
                line: $index + 1,
                capabilityId: 'ssr.deferred',
                evidence: sprintf(
                    'fetch("%s") at line %d, response written into markup at line %d',
                    $path,
                    $index + 1,
                    $markupLine,
                ),
            );
        }

        return $findings;
    }

    /**
     * The fetched path when it is a same-origin string literal, else null.
     *
     * A variable target is not reported: it cannot be shown to be same-origin,
     * and guessing is how a rule starts crying wolf.
     */
    private static function sameOriginFetchPath(string $line): ?string
    {
        if (!preg_match('/\bfetch\s*\(\s*([\'"`])(\/[^\'"`]*)\1/', $line, $m)) {
            return null;
        }

        // `//host` is protocol-relative and therefore off-origin despite the
        // leading slash.
        if (str_starts_with($m[2], '//')) {
            return null;
        }

        return $m[2];
    }

    /**
     * @param list<string> $lines
     * @return int|null 1-based line of the markup write, if one is close enough
     */
    private static function markupWriteWithin(array $lines, int $from, int $window): ?int
    {
        $last = min(count($lines) - 1, $from + $window);
        for ($i = $from; $i <= $last; $i++) {
            if (preg_match('/\.innerHTML\s*(=|\+=)/', $lines[$i]) === 1) {
                return $i + 1;
            }
            if (preg_match('/insertAdjacentHTML\s*\(/', $lines[$i]) === 1) {
                return $i + 1;
            }
        }

        return null;
    }
}

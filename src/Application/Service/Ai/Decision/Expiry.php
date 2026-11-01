<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Decision;

/**
 * An expiry as an agent writes it on the command line: a bare YYYY-MM-DD
 * holds through that day (UTC), anything else is a full ISO-8601 time with
 * its offset. Nothing relative: "tomorrow" or "+1 week" would mean a
 * different moment to every agent reading it. Shared by ai:decision and
 * ai:state.
 */
final class Expiry
{
    public static function parse(string $text): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $text, new \DateTimeZone('UTC'));

            // 2026-02-30 is not a day, whatever PHP rolls it into.
            return $day !== false && $day->format('Y-m-d') === $text ? $day->modify('+1 day') : null;
        }

        return Decision::isInstant($text) ? new \DateTimeImmutable((string) preg_replace('/Z\z/', '+00:00', $text)) : null;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * The one-line `signal` every verification result carries: whitespace folded,
 * cut to a length a terminal line and an NDJSON reader both survive.
 */
final class SignalText
{
    private const MAX = 240;

    public static function compress(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return strlen($value) > self::MAX ? substr($value, 0, self::MAX - 3) . '...' : $value;
    }

    /** The last non-empty line of a command's output, console tags removed. */
    public static function lastLine(string $output): string
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim(preg_replace('/\[[a-zA-Z]+\]/', '', $lines[$i]) ?? '');
            if ($line === '') {
                continue;
            }

            return self::compress($line);
        }

        return '';
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Intake;

/**
 * What makes two logged errors one signal (tk-intake-from-runtime): the
 * channel, the logger's own message, the exception class and the file it was
 * thrown from. Whatever varies from one occurrence to the next (ids, times,
 * line numbers, quoted values, hosts, tokens) is normalised away, and the
 * project root is cut off the file, so one error is one signal on any
 * checkout and after the line it sits on moves.
 *
 * The exception's own message is left out on purpose: it is where a request's
 * values, and so its secrets, end up.
 */
final class SignalFingerprint
{
    /** Applied in order: the wider shapes first, bare digits last. */
    private const NORMALISE = [
        '~\b[a-z][a-z0-9+.-]*://\S+~i' => 'URL',
        '~[\w.+-]+@[\w-]+(?:\.[\w-]+)+~' => 'EMAIL',
        '~"[^"]*"|\'[^\']*\'~' => 'Q',
        '~\b([A-Za-z_][\w-]*)=\S+~' => '$1=V',
        '~\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b~i' => 'ID',
        '~\b(?:[a-z0-9-]+\.)*[a-z0-9-]+:\d{2,5}\b~i' => 'HOST',
        '~\b(?=[A-Za-z0-9_-]*\d)[A-Za-z0-9_-]{16,}\b~' => 'T',
        '~\b[0-9a-f]{8,}\b~i' => 'H',
        '~\d+~' => 'N',
    ];

    public static function of(string $channel, string $message, ?string $exception, ?string $site): string
    {
        return sha1(implode("\n", [$channel, self::normalise($message), $exception ?? '', $site ?? '']));
    }

    public static function normalise(string $text): string
    {
        $text = (string) preg_replace(array_keys(self::NORMALISE), array_values(self::NORMALISE), $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /** The file an error was thrown from, relative to the project and without its line. */
    public static function site(?string $file, string $root): ?string
    {
        $file = trim((string) $file);
        if ($file === '') {
            return null;
        }
        $file = (string) preg_replace('/:\d+$/', '', $file);
        $prefix = rtrim($root, '/') . '/';

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
    }
}

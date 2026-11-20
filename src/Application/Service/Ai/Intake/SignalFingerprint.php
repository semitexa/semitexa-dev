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
 * values, and so its secrets, end up. So is the logger's message once the
 * class and the file say where the error is: a logger that writes
 * $e->getMessage() there would split one defect into one idea per user.
 */
final class SignalFingerprint
{
    /** Only the head of a message is read: the title shows less, and a long one made the patterns slow. */
    public const MAX_MESSAGE = 500;

    /** Applied in order: the wider shapes first, bare digits last. */
    private const WIDE = [
        // A URL ends at a quote too: "http://a/b" must leave its closing quote to the quote rule.
        '~\b[a-z][a-z0-9+.-]*://[^\s"\']+~i' => 'URL',
        '~[\w.+-]+@[\w-]+(?:\.[\w-]+)+~' => 'EMAIL',
        // Grouping only (a title never shows the message past its plain words): an apostrophe after two
        // letters ("don't", "users'") opens no quote; a prefixed quote (b'...') still does.
        '~"[^"]*"|(?<![A-Za-z]{2})\'[^\']*\'~' => 'Q',
    ];

    /** A quote the cut left open: its value ran past the head, so all that is left of it goes. */
    private const CUT_QUOTE = '~["\'].*\z~s';

    /** A word the cut fell inside is the head of a key or an address no pattern knows any more. */
    private const CUT_WORD = '~\s+\S*\z~';

    private const NORMALISE = [
        '~\b([A-Za-z_][\w-]*)=\S+~' => '$1=V',
        // A credential after its name, written with a colon or a space: "Bearer <token>", "password: <it>".
        '~\b(bearer|basic|token|secret|password|passwd|pwd|api[_-]?key|authorization|cookie)(\s*:\s*|\s+)(?!V\b)\S+~i' => '$1 V',
        '~\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b~i' => 'ID',
        '~\b(?:[a-z0-9-]+\.)*[a-z0-9-]+:\d{2,5}\b~i' => 'HOST',
        '~\b(?=[A-Za-z0-9_-]*\d)[A-Za-z0-9_-]{16,}\b~' => 'T',
        // A long key with no digit in it, joined by underscores or hyphens: sk_live_..., session ids.
        '~\b(?=[A-Za-z0-9_-]*[_-])[A-Za-z0-9_-]{24,}\b~' => 'T',
        '~\b[0-9a-f]{8,}\b~i' => 'H',
        '~\d+~' => 'N',
    ];

    public static function of(string $channel, string $message, ?string $exception, ?string $site): string
    {
        $message = $exception !== null && $site !== null ? '' : self::normalise($message);

        return sha1(implode("\n", [$channel, $message, $exception ?? '', $site ?? '']));
    }

    public static function normalise(string $text): string
    {
        $cut = strlen($text) > self::MAX_MESSAGE;
        $text = mb_strcut($text, 0, self::MAX_MESSAGE, 'UTF-8');
        if ($cut) {
            $text = (string) preg_replace(self::CUT_WORD, '', $text);
        }
        $text = (string) preg_replace(array_keys(self::WIDE), array_values(self::WIDE), $text);
        if ($cut) {
            // After the closed quotes, before the patterns that would take only its first word.
            $text = (string) preg_replace(self::CUT_QUOTE, 'Q', $text);
        }
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

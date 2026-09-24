<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * The first failure in a PHPUnit run, in one line, for a verification signal.
 *
 * The signal used to be PHPUnit's summary alone — "Tests: 20, Assertions: 67,
 * Failures: 1." — which says that something broke and nothing about what. An
 * agent then re-ran the suite by hand to read the message the report had
 * already thrown away. The whole-tree ratchets made that the common case: their
 * failure message IS the finding ("HtmlResponse.php: 766 lines, budget 765").
 */
final class PhpunitFailureHeadline
{
    private const MAX = 320;

    /**
     * "Class::method — first message line · " or '' when nothing failed.
     */
    public static function of(string $output): string
    {
        $lines = preg_split('/\R/', $output) ?: [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^1\) (\S+)$/', $line, $m) !== 1) {
                continue;
            }
            $test = preg_replace('/^.*\\\\/', '', $m[1]) ?? $m[1];
            // The message runs until PHPUnit's own framing starts: a blank
            // line, the assertion boilerplate, or the diff.
            $parts = [];
            foreach (array_slice($lines, $i + 1, 12) as $next) {
                $next = trim($next);
                if ($next === '' || str_starts_with($next, 'Failed asserting') || str_starts_with($next, '--- Expected')) {
                    break;
                }
                $parts[] = $next;
            }
            $message = implode(' ', $parts);
            $headline = $test . ($message !== '' ? ' — ' . $message : '');

            return (mb_strlen($headline) > self::MAX ? mb_substr($headline, 0, self::MAX - 1) . '…' : $headline) . ' · ';
        }

        return '';
    }
}

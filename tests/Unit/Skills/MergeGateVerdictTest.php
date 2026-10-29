<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The codereview merge gate. It waited for Greptile, which stopped reviewing
 * on 2026-10-14, so every PR read WAIT:greptile-not-started and the night's
 * merges were decided by hand; and it counted unresolved threads only, so
 * "Outside diff range" findings in a review body — eight of them on
 * 2026-10-28 — passed as clean.
 */
final class MergeGateVerdictTest extends TestCase
{
    /** @return iterable<string, array{list<string>, string}> */
    public static function cases(): iterable
    {
        // unresolved threads, unanswered outside-diff reviews, check status, conclusion, title, merge state
        yield 'reviewed, nothing open, clean' => [['0', '0', 'completed', 'success', 'Finished', 'CLEAN'], 'READY'];
        yield 'an open thread' => [['2', '0', 'completed', 'success', 'Finished', 'CLEAN'], 'FIX:2'];
        yield 'a finding outside the diff' => [['0', '1', 'completed', 'success', 'Finished', 'CLEAN'], 'FIX:outside-1'];
        yield 'rate limited is a skipped review' => [['0', '0', 'completed', 'neutral', 'Review rate limited', 'CLEAN'], 'WAIT:coderabbit-rate-limited'];
        yield 'still reviewing' => [['0', '0', 'in_progress', '', 'Review in progress', 'CLEAN'], 'WAIT:coderabbit-in-progress'];
        yield 'no review on this head' => [['0', '0', 'none', '', '', 'CLEAN'], 'WAIT:coderabbit-none'];
        yield 'the old commit status still counts' => [['0', '0', 'completed', 'success', 'Review completed', 'CLEAN'], 'READY'];
        yield 'reviewed but blocked' => [['0', '0', 'completed', 'success', 'Finished', 'UNSTABLE'], 'WAIT:merge-state-UNSTABLE'];
    }

    /** @param list<string> $args */
    #[Test]
    #[DataProvider('cases')]
    public function the_verdict(array $args, string $verdict): void
    {
        $lib = dirname(__DIR__, 3) . '/resources/skills/codereview/scripts/merge-gate.sh';
        exec(sprintf(
            'env -i PATH=/usr/bin:/bin bash -c %s 2>&1',
            escapeshellarg('. ' . escapeshellarg($lib) . ' && merge_verdict ' . implode(' ', array_map('escapeshellarg', $args))),
        ), $out, $status);

        self::assertSame([0, [$verdict]], [$status, $out]);
    }
}

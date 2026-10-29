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
        yield 'a review that failed is no pass' => [['0', '0', 'completed', 'failure', 'Finished', 'CLEAN'], 'WAIT:coderabbit-failure'];
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

    /**
     * The count of "Outside diff range" findings nobody answered, read to the
     * end of both lists: it read the last 40 reviews and 100 comments, so a
     * finding on an earlier page, or its answer, was not seen.
     */
    #[Test]
    public function an_unanswered_finding_outside_the_diff_is_counted_across_pages(): void
    {
        // Review 101 is answered on the second page of comments, 202 and 303 nowhere.
        [$status, $out, $calls] = $this->outside(reviews: [['101'], ['202', '303']], comments: [['555'], ['101']]);

        self::assertSame([0, ['2']], [$status, $out]);
        self::assertSame([
            'api --paginate repos/semitexa/semitexa-dev/pulls/163/reviews?per_page=100',
            'api --paginate repos/semitexa/semitexa-dev/issues/163/comments?per_page=100',
        ], $calls, 'every page of both lists, CodeRabbit\'s reviews and everyone else\'s comments');
    }

    #[Test]
    public function every_finding_answered_counts_none(): void
    {
        [$status, $out] = $this->outside(reviews: [['101', '202']], comments: [['202', '101']]);

        self::assertSame([0, ['0']], [$status, $out]);
    }

    /** "Nothing unanswered" read from a failed request would merge past a finding. */
    #[Test]
    public function a_failed_read_fails_instead_of_counting_none(): void
    {
        self::assertSame(1, $this->outside(reviews: null, comments: [[]])[0], 'the reviews');
        self::assertSame(1, $this->outside(reviews: [['101']], comments: null)[0], 'the comments');
    }

    /**
     * CodeRabbit writes body findings under "Outside diff range", and since
     * 2026-10-29 also as "Follow-up findings" or "Follow-up Comments":
     * semitexa-dev#163 merged past two of those, because only the first was
     * matched.
     */
    #[Test]
    public function both_kinds_of_review_body_finding_are_read(): void
    {
        $this->outside(reviews: [[]], comments: [[]]);

        self::assertSame(
            '.[] | select(.user.login == "coderabbitai[bot]" and ((.body // "") | test("Outside diff range|Follow-up (findings|comments)"; "i"))) | .id',
            (string) file_get_contents($this->dir . '/reviews-filter'),
        );
    }

    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    /**
     * outside_unanswered against a fake gh that prints, page after page, what
     * the real one prints after --jq: the ids of CodeRabbit's reviews with
     * such a finding, and the ids named in other people's comments; the filter
     * it was given for the reviews is kept in reviews-filter. null fails.
     *
     * @param list<list<string>>|null $reviews
     * @param list<list<string>>|null $comments
     * @return array{int, list<string>, list<string>}
     */
    private function outside(?array $reviews, ?array $comments): array
    {
        $this->dir = sys_get_temp_dir() . '/merge-gate-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0o755, true);
        file_put_contents($this->dir . '/bin/gh', "#!/bin/bash\necho \"\$1 \$2 \$3\" >> " . escapeshellarg($this->dir . '/calls') . "\ncase \"\$3\" in\n  */reviews*) printf %s \"\$5\" > " . escapeshellarg($this->dir . '/reviews-filter') . "; " . self::pages($reviews) . " ;;\n  */comments*) " . self::pages($comments) . " ;;\n  *) exit 1 ;;\nesac\n");
        chmod($this->dir . '/bin/gh', 0o755);

        $lib = dirname(__DIR__, 3) . '/resources/skills/codereview/scripts/merge-gate.sh';
        exec(sprintf(
            'env -i PATH=%s:/usr/bin:/bin bash -c %s 2>&1',
            escapeshellarg($this->dir . '/bin'),
            escapeshellarg('. ' . escapeshellarg($lib) . ' && outside_unanswered semitexa/semitexa-dev 163'),
        ), $out, $status);
        $calls = is_file($this->dir . '/calls') ? file($this->dir . '/calls', FILE_IGNORE_NEW_LINES) : [];

        return [$status, $out, $calls ?: []];
    }

    /**
     * The shell that prints the pages one after another, or fails for null.
     *
     * @param list<list<string>>|null $pages
     */
    private static function pages(?array $pages): string
    {
        if ($pages === null) {
            return 'exit 1';
        }
        $out = '';
        foreach ($pages as $page) {
            foreach ($page as $line) {
                $out .= $line . "\n";
            }
        }

        return 'printf %s ' . escapeshellarg($out);
    }
}

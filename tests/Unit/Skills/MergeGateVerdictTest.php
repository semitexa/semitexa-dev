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
        // ... and, last, the threads CodeRabbit's last reply calls not addressed
        yield 'a thread the bot calls not addressed' => [['0', '0', 'completed', 'success', 'Finished', 'CLEAN', '1'], 'FIX:bot-unaddressed-1'];
        yield 'no such thread' => [['0', '0', 'completed', 'success', 'Finished', 'CLEAN', '0'], 'READY'];
        yield 'an open thread is named first' => [['2', '0', 'completed', 'success', 'Finished', 'CLEAN', '1'], 'FIX:2'];
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
            '.[] | select(.user.login == "coderabbitai[bot]" and ((.body // "") | test("Outside diff range|Follow-up (findings|comments)|(Minor|Major|Critical) comments:? \\\\(?[0-9]"; "i"))) | .id',
            (string) file_get_contents($this->dir . '/reviews-filter'),
        );
    }

    /**
     * CodeRabbit also writes findings in a review body under "Minor comments:
     * <n>" (and Major, Critical), in no thread: semitexa-ssr#192 was merged on
     * 2026-11-21 with the Minor of review 6002171521 never answered. Such a
     * review counts until a comment of someone else names its id. The reviews
     * and comments are the ones GitHub recorded for these pull requests.
     */
    #[Test]
    public function a_finding_in_a_severity_section_of_a_review_body_nobody_answered_is_counted(): void
    {
        self::assertSame([0, ['FIX:outside-1']], $this->recordedReviews('ssr-192'));
    }

    /** semitexa-ssr#193: review 6002907626 holds a Minor, comment 7057583054 names it. */
    #[Test]
    public function the_same_finding_counts_until_a_comment_names_the_review(): void
    {
        self::assertSame([0, ['FIX:outside-1']], $this->recordedReviews('ssr-193', withoutComment: 7057583054));
        self::assertSame([0, ['READY']], $this->recordedReviews('ssr-193'));
    }

    /**
     * "Nitpick comments" is CodeRabbit's word for optional, and none of the
     * five reviews of November holding only those was answered before the
     * merge: semitexa-ssr#178's review 5794092128 is one, and it holds no gate.
     */
    #[Test]
    public function a_review_body_with_nitpicks_only_is_no_finding(): void
    {
        self::assertSame([0, ['READY']], $this->recordedReviews('ssr-178'));
    }

    /**
     * The verdict over the recorded reviews and issue comments of a pull
     * request. The fake gh answers as gh does after --jq, with the pattern
     * taken from the filter it was given: the ids of CodeRabbit's reviews
     * whose body matches it, and the ids named in other people's comments.
     *
     * @return array{int, list<string>}
     */
    private function recordedReviews(string $pr, int $withoutComment = 0): array
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
        $this->dir = sys_get_temp_dir() . '/merge-gate-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0o755, true);
        file_put_contents($this->dir . '/bin/gh', '#!' . PHP_BINARY . "\n<?php\n" . strtr(<<<'PHP'
            [, , , $path, , $filter] = $argv;
            $bot = static fn(array $row): bool => $row['user']['login'] === 'coderabbitai[bot]';
            if (str_contains($path, '/reviews')) {
                preg_match('/test\("(.*)"; "i"\)/', $filter, $m) || exit(1);
                $pattern = '~' . json_decode('"' . $m[1] . '"', flags: JSON_THROW_ON_ERROR) . '~i';
                foreach (json_decode((string) file_get_contents('{fixtures}-reviews.json'), true, flags: JSON_THROW_ON_ERROR) as $review) {
                    if ($bot($review) && preg_match($pattern, (string) $review['body']) === 1) {
                        echo $review['id'], "\n";
                    }
                }
                exit(0);
            }
            foreach (json_decode((string) file_get_contents('{fixtures}-issue-comments.json'), true, flags: JSON_THROW_ON_ERROR) as $comment) {
                if (!$bot($comment) && $comment['id'] !== {without} && preg_match_all('/[0-9]{6,}/', (string) $comment['body'], $ids) > 0) {
                    echo implode("\n", $ids[0]), "\n";
                }
            }
            PHP, [
                '{fixtures}' => dirname(__DIR__, 2) . '/Fixtures/Skills/merge-gate/' . $pr,
                '{without}' => (string) $withoutComment,
            ]));
        chmod($this->dir . '/bin/gh', 0o755);

        $lib = dirname(__DIR__, 3) . '/resources/skills/codereview/scripts/merge-gate.sh';
        exec(sprintf(
            'env -i PATH=%s:/usr/bin:/bin bash -c %s 2>&1',
            escapeshellarg($this->dir . '/bin'),
            escapeshellarg('. ' . escapeshellarg($lib) . ' && n="$(outside_unanswered semitexa/semitexa-' . $pr . ')" && merge_verdict 0 "$n" completed success Finished CLEAN 0'),
        ), $out, $status);

        return [$status, $out];
    }

    /**
     * Review threads as GitHub recorded them (the gate's own query, the
     * response cut down to the one thread named). semitexa-ssr#192 was merged
     * on READY on 2026-11-21 with a Major open: the author had replied on
     * thread 4649964866 and CodeRabbit had resolved it, as it does when the
     * commented lines change, while its last reply there said "I'm not marking
     * the finding as addressed yet".
     *
     * @return iterable<string, array{string, string}>
     */
    public static function recordedThreads(): iterable
    {
        yield 'resolved by the bot, its last reply says not addressed' => ['ssr-192-resolved-by-bot-not-addressed', 'FIX:bot-unaddressed-1'];
        yield 'not resolved, its last reply says one case remains' => ['ssr-190-unresolved-not-addressed', 'FIX:bot-unaddressed-1'];
        yield 'resolved, its last reply withdraws the finding' => ['platform-ui-85-resolved-withdrawn', 'READY'];
        yield 'resolved by a human, the bot never replied' => ['platform-ui-93-resolved-by-human-no-bot-reply', 'READY'];
    }

    #[Test]
    #[DataProvider('recordedThreads')]
    public function what_the_bot_last_said_on_a_thread_decides(string $payload, string $verdict): void
    {
        [$status, $out] = $this->threads(
            $payload,
            'n="$(bot_unaddressed semitexa/semitexa-ssr 192)" && merge_verdict 0 0 completed success Finished CLEAN "$n"',
        );

        self::assertSame([0, [$verdict]], [$status, $out]);
    }

    /** "No such thread" read from a failed request would merge past the finding. */
    #[Test]
    public function a_failed_read_of_the_threads_fails_instead_of_counting_none(): void
    {
        [$status, $out] = $this->threads(null, 'bot_unaddressed semitexa/semitexa-ssr 192');

        self::assertSame([1, []], [$status, $out]);
    }

    /**
     * One request a page of a hundred threads, ten comments from the end of
     * each, every page: the whole conversation of every thread is not needed
     * to know how it ended.
     */
    #[Test]
    public function the_threads_are_read_to_the_end_ten_comments_each(): void
    {
        [, , $calls] = $this->threads('ssr-192-resolved-by-bot-not-addressed', 'bot_unaddressed semitexa/semitexa-ssr 192');

        self::assertCount(1, $calls);
        self::assertStringStartsWith('api graphql --paginate -F owner=semitexa -F name=semitexa-ssr -F number=192 -f query=', $calls[0]);
        self::assertStringContainsString('reviewThreads(first: 100, after: $endCursor) { pageInfo { hasNextPage endCursor }', $calls[0]);
        self::assertStringContainsString('comments(last: 10)', $calls[0]);
        // The stand-in gh answers as this filter does; a change to it is a change to both.
        self::assertSame(
            '.data.repository.pullRequest.reviewThreads.nodes[] | [.comments.nodes[] | select(.replyTo != null and ((.author.login // "") | test("^coderabbitai")))] | last | select(. != null) | (.body // "") | gsub("(?s)<details>.*?</details>"; " ") | gsub("(?s)<!--.*?-->"; " ") | gsub("\\\\s+"; " ")',
            (string) file_get_contents($this->dir . '/threads-filter'),
        );
    }

    /**
     * CodeRabbit's own sentences, from its replies on Semitexa pull requests.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function replies(): iterable
    {
        yield 'not marking, yet' => ['Please add a regression test for it. I’m not marking the finding as addressed yet.', 1];
        yield 'not marking, plain apostrophe' => ["I'm not marking the finding as addressed.", 1];
        yield 'am not marking' => ['I am not marking the finding as addressed.', 1];
        yield 'not fully addressed' => ['The shared-set race is fixed, but the finding is not fully addressed.', 1];
        yield 'cannot confirm it closed' => ['The usage example is fixed, but I can’t confirm that the Major is closed yet.', 1];
        yield 'remains open' => ['Until then, the finding remains open.', 1];
        yield 'two remain open' => ['Two findings in this thread remain open: the validation guidance does not list them.', 1];
        yield 'left open' => ['I’ll leave this finding open because it is not fixed in this PR.', 1];
        yield 'one case remains' => ['Thanks for the update. One case remains: if `develop` has no upstream, nothing is reported.', 1];
        yield 'a part remains' => ['The usage text now describes the fallback. One part of the Major remains: props can still appear.', 1];
        yield 'a path remains' => ['The fix covers the reported `join()` path. A related data-loss path remains: the record is replaced.', 1];
        yield 'the risk remains' => ['The risk remains for this PR: two workers can submit the same token.', 1];
        yield 'one remaining case' => ['I found one remaining failure case in ef57e3c.', 1];
        yield 'inconclusive' => ['Verification is inconclusive: the revision I checked still calls only `router_stale_projects`.', 1];
        yield 'both kinds in one reply count' => ['I withdraw the first finding. The version check remains open.', 1];
        yield 'one per thread' => ["I’m not marking the finding as addressed.\nThis addresses the finding.\nOne gap remains.", 2];

        yield 'addresses' => ['The exact argv assertions address the finding. This addresses the finding.', 0];
        yield 'withdrawn' => ['My example did not describe the current code, so I withdraw the finding. ✅ Review thread resolved.', 0];
        yield 'marking as addressed' => ['You’re right: my earlier advice should have said to recreate the container. I’m marking the finding as addressed.', 0];
        yield 'confirmed' => ['Confirmed by source inspection at `81db236`: this addresses the Major finding. I did not run the tests.', 0];
        yield 'another pull request is still open' => ['I withdraw the finding. `#214` is still open. Please confirm that both changes ship together.', 0];
        yield 'remains withdrawn' => ['I accept the source-only check. The rolling-deployment finding remains withdrawn for the deployment model you described.', 0];
        yield 'nothing left' => ['Your reported level-max result shows no remaining errors for the file.', 0];
        yield 'no reply at all' => ['', 0];
    }

    #[Test]
    #[DataProvider('replies')]
    public function the_replies_that_say_not_addressed_are_counted(string $replies, int $count): void
    {
        $lib = dirname(__DIR__, 3) . '/resources/skills/codereview/scripts/merge-gate.sh';
        exec(sprintf(
            'env -i PATH=/usr/bin:/bin bash -c %s 2>&1',
            escapeshellarg('. ' . escapeshellarg($lib) . ' && unaddressed_count ' . escapeshellarg($replies)),
        ), $out, $status);

        self::assertSame([0, [(string) $count]], [$status, $out]);
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
     * A command of merge-gate.sh against a fake gh that answers the review
     * threads request from a recorded response, as gh does after --jq: a line
     * per thread the bot replied on, its last reply without the details blocks
     * and HTML comments. The filter gh was given is kept in threads-filter.
     * null fails the request.
     *
     * @return array{int, list<string>, list<string>}
     */
    private function threads(?string $payload, string $command): array
    {
        $this->dir = sys_get_temp_dir() . '/merge-gate-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0o755, true);
        $fixture = dirname(__DIR__, 2) . '/Fixtures/Skills/merge-gate/' . $payload . '.json';
        file_put_contents($this->dir . '/bin/gh', '#!' . PHP_BINARY . "\n<?php\n" . strtr(<<<'PHP'
            $args = array_slice($argv, 1);
            file_put_contents('{dir}/calls', implode(' ', array_slice($args, 0, -2)) . "\n", FILE_APPEND);
            file_put_contents('{dir}/threads-filter', (string) end($args));
            if ('{payload}' === '' || ($args[1] ?? '') !== 'graphql') {
                exit(1);
            }
            $response = json_decode((string) file_get_contents('{fixture}'), true, flags: JSON_THROW_ON_ERROR);
            foreach ($response['data']['repository']['pullRequest']['reviewThreads']['nodes'] as $thread) {
                $last = null;
                foreach ($thread['comments']['nodes'] as $comment) {
                    if ($comment['replyTo'] !== null && str_starts_with($comment['author']['login'] ?? '', 'coderabbitai')) {
                        $last = $comment;
                    }
                }
                if ($last !== null) {
                    echo preg_replace(['~<details>.*?</details>~s', '~<!--.*?-->~s', '~\s+~'], ' ', $last['body']), "\n";
                }
            }
            PHP, ['{dir}' => $this->dir, '{payload}' => (string) $payload, '{fixture}' => $fixture]));
        chmod($this->dir . '/bin/gh', 0o755);

        $lib = dirname(__DIR__, 3) . '/resources/skills/codereview/scripts/merge-gate.sh';
        exec(sprintf(
            'env -i PATH=%s:/usr/bin:/bin bash -c %s 2>&1',
            escapeshellarg($this->dir . '/bin'),
            escapeshellarg('. ' . escapeshellarg($lib) . ' && ' . $command),
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

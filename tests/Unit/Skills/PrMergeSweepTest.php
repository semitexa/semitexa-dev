<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * pr-merge-sweep.sh --merge on a pull request it reads READY. On 2026-11-19
 * the sweep read semitexa-dev#287 READY, and a minute later --merge printed
 * "unexpected end of JSON input" and left it open: what `gh api --jq` says
 * when GitHub answers a request with an empty body. The sweep stopped on that
 * one failed read, before the row of the PR. #287 had no review of CodeRabbit,
 * only its summary comment, and the pull request here has none either.
 */
final class PrMergeSweepTest extends TestCase
{
    private const HEAD = '240034e1c0ffee240034e1c0ffee240034e1c0ff';
    private const MERGE = 'pr merge 287 -R semitexa/semitexa-dev --merge --match-head-commit ' . self::HEAD;

    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function reads(): iterable
    {
        yield 'the list of open pull requests (pr-review.sh)' => ['pr list'];
        yield 'the head and the merge state' => ['pr view'];
        yield 'the check runs of the head' => ['check-runs'];
        yield 'the reviews' => ['pulls/287/reviews'];
        yield 'the comments' => ['issues/287/comments'];
        yield 'the review threads' => ['api graphql'];
    }

    /**
     * Each failed attempt also prints a review id before it fails, as a list
     * that breaks on its second page does: read as an answer it would be a
     * finding nobody answered (FIX:outside-1).
     */
    #[Test]
    #[DataProvider('reads')]
    public function a_ready_pr_is_merged_though_one_read_failed_once(string $read): void
    {
        [$status, $out, $calls] = $this->sweep('--merge', fail: [$read, 1]);

        self::assertSame(0, $status, implode("\n", $out));
        self::assertMatchesRegularExpression('~^semitexa/semitexa-dev#287\s+240034e\s+CLEAN\s+READY$~m', implode("\n", $out));
        self::assertContains('  merged semitexa/semitexa-dev#287', $out);
        self::assertContains('ready=1 not-ready=0 (ready ones merged)', $out);
        self::assertSame([self::MERGE], self::merges($calls), 'merged once, on the head the verdict was read for');
    }

    #[Test]
    public function without_merge_the_same_pr_reads_ready_and_nothing_is_merged(): void
    {
        [$status, $out, $calls] = $this->sweep('', fail: ['pulls/287/reviews', 1]);

        self::assertSame(0, $status, implode("\n", $out));
        self::assertMatchesRegularExpression('~^semitexa/semitexa-dev#287\s+240034e\s+CLEAN\s+READY$~m', implode("\n", $out));
        self::assertContains('ready=1 not-ready=0', $out);
        self::assertSame([], self::merges($calls));
    }

    /** Fails closed: no verdict is made up for a request GitHub never answered. */
    #[Test]
    public function a_read_that_keeps_failing_stops_the_sweep_and_names_the_call(): void
    {
        [$status, $out, $calls] = $this->sweep('--merge', fail: ['pulls/287/reviews', 99]);

        self::assertNotSame(0, $status);
        self::assertContains('unexpected end of JSON input', $out);
        self::assertContains('pr-merge-sweep: gh api --paginate repos/semitexa/semitexa-dev/pulls/287/reviews?per_page=100 failed 3 time(s)', $out);
        self::assertCount(3, array_filter($calls, static fn (string $call): bool => str_contains($call, 'pulls/287/reviews')));
        self::assertSame([], self::merges($calls));
        self::assertSame([], preg_grep('~READY|merged~', $out));
    }

    /** --match-head-commit refuses a head pushed after the verdict; the refusal is not asked again. */
    #[Test]
    public function a_head_that_moved_is_refused_once_and_stays_unmerged(): void
    {
        [$status, $out, $calls] = $this->sweep('--merge', fail: ['pr merge', 99]);

        self::assertNotSame(0, $status);
        self::assertSame([self::MERGE], self::merges($calls));
        self::assertSame([], preg_grep('~merged semitexa~', $out));
    }

    /**
     * @param list<string> $calls
     * @return list<string>
     */
    private static function merges(array $calls): array
    {
        return array_values(array_filter($calls, static fn (string $call): bool => str_starts_with($call, 'pr merge')));
    }

    /**
     * The real pr-merge-sweep.sh and merge-gate.sh beside a pr-review.sh that
     * lists the open pull requests through gh and prints a queue of one, clean
     * PR. gh is a fake: the head is reviewed ("Finished"), the merge state is
     * CLEAN, and there is no review, no comment naming one and no thread. The
     * calls whose text holds $fail[0] fail $fail[1] times, as gh fails on an
     * empty body. jq, which the image does not have, is a stand-in for the two
     * programs the sweep runs.
     *
     * @param array{string, int} $fail
     * @return array{int, list<string>, list<string>}
     */
    private function sweep(string $option, array $fail): array
    {
        $this->dir = sys_get_temp_dir() . '/pr-merge-sweep-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0o755, true);
        mkdir($this->dir . '/scripts');
        $scripts = dirname(__DIR__, 3) . '/resources/skills/codereview/scripts';
        copy($scripts . '/pr-merge-sweep.sh', $this->dir . '/scripts/pr-merge-sweep.sh');
        copy($scripts . '/merge-gate.sh', $this->dir . '/scripts/merge-gate.sh');
        file_put_contents($this->dir . '/scripts/pr-review.sh', "#!/bin/bash\nset -euo pipefail\ngh pr list --repo semitexa/semitexa-dev > /dev/null\necho '" . json_encode(['repos' => [['slug' => 'semitexa/semitexa-dev', 'prs' => [['number' => 287, 'summary' => ['unresolvedActionableComments' => 0]]]]]], JSON_THROW_ON_ERROR) . "'\n");
        file_put_contents($this->dir . '/fail', $fail[0] . "\n" . $fail[1]);

        file_put_contents($this->dir . '/bin/gh', '#!' . PHP_BINARY . "\n<?php\n" . strtr(<<<'PHP'
            $args = array_slice($argv, 1);
            $call = implode(' ', $args[0] === 'api' ? array_slice($args, 0, $args[1] === 'graphql' ? 2 : -2) : $args);
            if ($args[0] === 'pr' && $args[1] === 'view') {
                $call = 'pr view ' . $args[2];
            }
            file_put_contents('{dir}/calls', $call . "\n", FILE_APPEND);
            [$failing, $times] = explode("\n", (string) file_get_contents('{dir}/fail'));
            $failed = is_file('{dir}/failed') ? (int) file_get_contents('{dir}/failed') : 0;
            if (str_contains($call, $failing) && $failed < (int) $times) {
                file_put_contents('{dir}/failed', (string) ($failed + 1));
                echo "4242424242\n";
                fwrite(STDERR, "unexpected end of JSON input\n");
                exit(1);
            }
            echo match (true) {
                $args[0] === 'pr' && $args[1] === 'list' => "[]\n",
                $args[0] === 'pr' && $args[1] === 'view' => "{head} CLEAN\n",
                $args[0] === 'pr' && $args[1] === 'merge' => '',
                str_contains($call, '/check-runs') => "completed|success|Finished\n",
                str_contains($call, '/reviews'), str_contains($call, '/comments'), $args[1] === 'graphql' => '',
                default => exit(2),
            };
            PHP, ['{dir}' => $this->dir, '{head}' => self::HEAD]));
        file_put_contents($this->dir . '/bin/jq', '#!' . PHP_BINARY . "\n<?php\n" . <<<'PHP'
            $program = (string) end($argv);
            /** @var array{repos: list<array{slug: string, prs: list<array{number: int, summary: array{unresolvedActionableComments?: int}}>}>} $queue */
            $queue = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
            $sum = 0;
            foreach ($queue['repos'] as $repo) {
                foreach ($repo['prs'] as $pr) {
                    $open = $pr['summary']['unresolvedActionableComments'] ?? 0;
                    $sum += $open;
                    if (str_starts_with($program, '.repos[] | .slug as $s')) {
                        echo $repo['slug'], ' ', $pr['number'], ' ', $open, "\n";
                    }
                }
            }
            if (str_starts_with($program, '[.repos[].prs[].summary')) {
                echo $sum, "\n";
            } elseif (!str_starts_with($program, '.repos[] | .slug as $s')) {
                exit(2);
            }
            PHP);
        chmod($this->dir . '/bin/gh', 0o755);
        chmod($this->dir . '/bin/jq', 0o755);
        chmod($this->dir . '/scripts/pr-review.sh', 0o755);

        exec(sprintf(
            'env -i PATH=%s:/usr/bin:/bin PR_SWEEP_GH_RETRY_SLEEP=0 bash %s %s 2>&1',
            escapeshellarg($this->dir . '/bin'),
            escapeshellarg($this->dir . '/scripts/pr-merge-sweep.sh'),
            $option,
        ), $out, $status);
        $calls = is_file($this->dir . '/calls') ? file($this->dir . '/calls', FILE_IGNORE_NEW_LINES) : [];

        return [$status, $out, $calls ?: []];
    }
}

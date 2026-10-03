<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity;

/**
 * Compares each changed test file with its committed version and reports the
 * changes that make a suite greener without making the code more correct:
 * a test file or test removed, assertions removed, a value check weakened to
 * a shape check, a skip added.
 *
 * Every one of those is sometimes right — a test for deleted code goes with
 * it. So a finding is not a verdict on the change, it is a demand that the
 * change say so: an added line carrying `verify:accept-test-change <reason>`
 * in the same file (for a deleted file: in any file of the same change) turns
 * the findings into accepted ones. The reason then sits in the diff, where a
 * reviewer reads it, instead of in an agent's summary that nobody can check.
 */
final class TestChangeScan
{
    public const MARKER = 'verify:accept-test-change';

    private const MIN_REASON = 20;

    /**
     * @param \Closure(string $path): ?string $committed the committed content of a workspace path, null when it is new
     * @param \Closure(string $path): ?string $current   the working-tree content, null when it is deleted
     */
    public function __construct(
        private readonly \Closure $committed,
        private readonly \Closure $current,
    ) {}

    /**
     * @param list<string> $paths workspace-relative paths of the change (tests and production alike)
     * @return array{findings: list<TestChangeFinding>, accepted: list<TestChangeFinding>}
     */
    public function scan(array $paths): array
    {
        $findings = [];
        $accepted = [];
        // Lazily: the lines a path gained are needed for the test files with
        // findings, and for every path only when a deleted test file looks for
        // its reason. Reading all of them up front is a `git show` per
        // production file on every run.
        $added = [];
        /** @var \Closure(string): list<string> $addedTo */
        $addedTo = function (string $path) use (&$added): array {
            return $added[$path] ??= self::addedLines(($this->committed)($path), ($this->current)($path));
        };

        foreach ($paths as $path) {
            if (!str_ends_with($path, 'Test.php')) {
                continue;
            }
            $before = ($this->committed)($path);
            if ($before === null) {
                continue; // a new test file weakens nothing
            }
            $after = ($this->current)($path);
            $found = self::compare($path, AssertionInventory::of($before), $after === null ? null : AssertionInventory::of($after));
            if ($found === []) {
                continue;
            }
            // A deleted file cannot carry its own reason: any file of the change may.
            $reason = $after === null
                ? self::reasonIn(array_merge(...array_map($addedTo, $paths)))
                : self::reasonIn($addedTo($path));
            foreach ($found as $finding) {
                if ($reason === null) {
                    $findings[] = $finding;
                } else {
                    $accepted[] = $finding->acceptedBecause($reason);
                }
            }
        }

        return ['findings' => $findings, 'accepted' => $accepted];
    }

    /**
     * @param array<string, array{strong: int, total: int, skips: int}>      $before
     * @param array<string, array{strong: int, total: int, skips: int}>|null $after  null: the file is gone
     * @return list<TestChangeFinding>
     */
    public static function compare(string $path, array $before, ?array $after): array
    {
        if ($after === null) {
            $total = array_sum(array_column($before, 'total'));

            return $total === 0 ? [] : [new TestChangeFinding(
                TestChangeFinding::FILE_REMOVED,
                $path,
                null,
                sprintf('test file removed with %d assertion(s) in %d method(s)', $total, count(array_filter($before, static fn (array $c): bool => $c['total'] > 0))),
            )];
        }

        // The file is the unit of loss. Checks moved between methods, three
        // loose ones folded into one exact assertSame, a test renamed: none of
        // those lowers what the file checks. MEASURED 2026-10-03 over two
        // months of this workspace (955 modified test files): judged per method
        // this fired on 55, nearly all such reshuffles from review rounds;
        // judged per file, on 23 — every one a real net loss.
        $sum = static fn (array $inventory, string $key): int => array_sum(array_column($inventory, $key));
        if ($sum($after, 'total') >= $sum($before, 'total')
            && $sum($after, 'strong') >= $sum($before, 'strong')
            && $sum($after, 'skips') <= $sum($before, 'skips')
        ) {
            return [];
        }

        $findings = [];
        // A method that vanished while a new one with exactly its checks
        // appeared was renamed, not removed.
        $newcomers = array_diff_key($after, $before);
        foreach ($before as $method => $was) {
            if (!array_key_exists($method, $after)) {
                if ($was['total'] === 0) {
                    continue;
                }
                $twin = array_search($was, $newcomers, true);
                if ($twin !== false) {
                    unset($newcomers[$twin]);
                    continue;
                }
                $findings[] = new TestChangeFinding(TestChangeFinding::TEST_REMOVED, $path, $method, sprintf('%s() removed with %d assertion(s)', $method, $was['total']));
                continue;
            }
            $now = $after[$method];
            if ($now['total'] < $was['total']) {
                $findings[] = new TestChangeFinding(TestChangeFinding::ASSERTIONS_REMOVED, $path, $method, sprintf('%s(): %d of %d assertion(s) removed', $method, $was['total'] - $now['total'], $was['total']));
            } elseif ($now['strong'] < $was['strong']) {
                $findings[] = new TestChangeFinding(TestChangeFinding::ASSERTION_WEAKENED, $path, $method, sprintf('%s(): %d value check(s) replaced by weaker ones (value checks %d → %d, assertions still %d)', $method, $was['strong'] - $now['strong'], $was['strong'], $now['strong'], $now['total']));
            }
            if ($now['skips'] > $was['skips']) {
                $findings[] = new TestChangeFinding(TestChangeFinding::SKIP_ADDED, $path, $method, sprintf('%s(): now skips (markTestSkipped/markTestIncomplete added)', $method));
            }
        }
        foreach ($newcomers as $method => $now) {
            if ($now['skips'] > 0 && $now['total'] === 0) {
                $findings[] = new TestChangeFinding(TestChangeFinding::SKIP_ADDED, $path, (string) $method, sprintf('%s(): added already skipped, checking nothing', $method));
            }
        }
        // The file lost checks no single method accounts for: say so at file level.
        if ($findings === []) {
            $findings[] = new TestChangeFinding(TestChangeFinding::ASSERTIONS_REMOVED, $path, null, sprintf(
                'the file checks less: assertions %d → %d, value checks %d → %d',
                $sum($before, 'total'),
                $sum($after, 'total'),
                $sum($before, 'strong'),
                $sum($after, 'strong'),
            ));
        }

        return $findings;
    }

    /**
     * Lines of $after that $before does not have, as a multiset: cheap, and
     * exactly the question "did this change write the marker".
     *
     * @return list<string>
     */
    public static function addedLines(?string $before, ?string $after): array
    {
        if ($after === null) {
            return [];
        }
        $remaining = [];
        foreach (preg_split('/\R/', (string) $before) ?: [] as $line) {
            $remaining[$line] = ($remaining[$line] ?? 0) + 1;
        }
        $added = [];
        foreach (preg_split('/\R/', $after) ?: [] as $line) {
            if (($remaining[$line] ?? 0) > 0) {
                $remaining[$line]--;
                continue;
            }
            $added[] = $line;
        }

        return $added;
    }

    /** @param list<string> $lines */
    private static function reasonIn(array $lines): ?string
    {
        foreach ($lines as $line) {
            $at = strpos($line, self::MARKER);
            if ($at === false) {
                continue;
            }
            $reason = trim(substr($line, $at + strlen(self::MARKER)), " \t:—-*/#");
            if (mb_strlen($reason) >= self::MIN_REASON) {
                return $reason;
            }
        }

        return null;
    }
}

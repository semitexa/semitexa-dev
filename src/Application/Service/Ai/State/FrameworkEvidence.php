<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

use Semitexa\Dev\Application\Service\Ai\Work\JsonFile;
use Semitexa\Dev\Application\Service\Quality\Metric\PackagesWithoutTestsMetric;
use Semitexa\Dev\Application\Service\Quality\Metric\TestSkipCallsMetric;
use Semitexa\Dev\Application\Service\Quality\QualityLedger;
use Semitexa\Dev\Application\Service\Quality\QualityMetricInterface;

/**
 * The evidence of FrameworkRubric's computed criteria, read on every run of
 * ai:state (tk-state-computed-criteria). Points stay a judgement over the
 * evidence: the scorecard has scored by deltas since 2026-10-19, and a formula
 * that turned a count into points would move every number at once. What
 * changes is that the evidence is no longer the text someone typed: it is
 * measured, shown beside the points, and compared with what it was when they
 * were given.
 *
 *   - right.test-quality: tests.skip-calls and packages.without-tests,
 *     measured in the tree as `ai:quality check` does.
 *   - fast.speed-ratchet: the requests.* metrics the ledger holds. They are
 *     measured by running requests, which the release preflight does; the
 *     ratchet is what the ledger has recorded.
 *   - work.known-bugs: the backlog's open bug tasks (recipe fix_bug or bugfix, status neither done nor discarded).
 *   - right.static-analysis: the errors phpstan-baseline.neon holds and the
 *     ceiling the release gate allows above it. Running phpstan takes
 *     minutes and is the gate's job; this is what the gate is measured from.
 *   - work.installs: the last release report's Published install verdict
 *     (1 PASS, 0 anything else) and the version it installed, as digits.
 *
 * The other computed criteria (release tests, users get fixes,
 * audits, the rest of the performance audit) have no reader yet: their
 * evidence is a release report or an epic's progress, each a provider of its
 * own.
 */
final class FrameworkEvidence
{
    /** Recipes a task fixing a defect is filed under. */
    // The recipes defects are filed under; the classifier files a bug report
    // ("fix the 500 on /login") as debug_investigate. Research is filed as
    // `research`, so it is not counted.
    public const BUG_RECIPES = ['fix_bug', 'bugfix', 'debug_investigate'];

    public const PHPSTAN_BASELINE = 'packages/semitexa-dev/resources/phpstan/phpstan-baseline.neon';
    public const PHPSTAN_CEILING = 'packages/semitexa-dev/resources/phpstan/phpstan-ceiling.json';
    /** Where release-readiness leaves one report per run. */
    public const RELEASE_REPORTS = 'var/docs/release';

    /**
     * @param array<string, array<string, QualityMetricInterface>> $measured criterion => metric id => metric
     * @param array<string, list<string>> $recorded criterion => ledger metric ids
     * @param array<string, \Closure(string): array<string, int>> $counted criterion => a count read from the project's files
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $measured,
        private readonly array $recorded,
        private readonly array $counted = [],
    ) {}

    public static function forProject(string $projectRoot): self
    {
        return new self(
            $projectRoot,
            ['right.test-quality' => ['tests.skip-calls' => new TestSkipCallsMetric(), 'packages.without-tests' => new PackagesWithoutTestsMetric(), 'tests.files' => new TestFilesCount()]],
            ['fast.speed-ratchet' => ['requests.query-shapes', 'requests.duplicate-queries', 'requests.repeated-statements']],
            [
                'work.known-bugs' => self::openBugTasks(...),
                'right.static-analysis' => self::phpstanBaseline(...),
                'work.installs' => self::lastPublishedInstall(...),
            ],
        );
    }

    /** @return array<string, int> */
    public static function openBugTasks(string $projectRoot): array
    {
        $open = 0;
        $dir = $projectRoot . '/var/ai-work/tasks';
        // No backlog is no open bug; a backlog that cannot be listed is no reading.
        if ((is_dir($dir) && !is_readable($dir)) || (is_link($dir) && !is_dir($dir))) {
            throw new \RuntimeException('var/ai-work/tasks cannot be listed');
        }
        $files = is_dir($dir) ? glob($dir . '/*.json') : [];
        if ($files === false) {
            throw new \RuntimeException('var/ai-work/tasks cannot be listed');
        }
        foreach ($files as $file) {
            // A task file that does not read is a finding, never a closed bug.
            $task = JsonFile::read($file);
            if (in_array($task['recipe'] ?? null, self::BUG_RECIPES, true) && !in_array($task['status'] ?? null, ['done', 'discarded'], true)) {
                $open++;
            }
        }

        return ['bugs.open' => $open];
    }

    /**
     * The newest release report (named <date>-<codename>-<version>.md, the
     * version written 2026.11.01.0448 or 2026-11-01-0448; the UPGRADE notes
     * beside them are not reports): its version and whether its
     * Published install passed. A report without the verdict is no reading.
     *
     * @return array<string, int>
     */
    public static function lastPublishedInstall(string $projectRoot): array
    {
        $reports = array_values(array_filter(
            glob($projectRoot . '/' . self::RELEASE_REPORTS . '/*.md') ?: [],
            static fn (string $f): bool => str_starts_with(basename($f), 'UPGRADE-') === false && self::versionOf($f) !== '',
        ));
        if ($reports === []) {
            throw new \RuntimeException('no release report in ' . self::RELEASE_REPORTS);
        }
        // Newest version first; two runs for one version, the later date first.
        usort($reports, static fn (string $a, string $b): int => [self::versionOf($b), basename($b)] <=> [self::versionOf($a), basename($a)]);
        $report = (string) @file_get_contents($reports[0]);
        if (preg_match('/^## Published install\s.*?^- Verdict: \*\*([A-Z]+)\*\*/ms', $report, $m) !== 1) {
            throw new \RuntimeException(basename($reports[0]) . ' records no Published install verdict');
        }

        return ['release.version' => (int) self::versionOf($reports[0]), 'release.published-install-pass' => $m[1] === 'PASS' ? 1 : 0];
    }

    /** The report's version as twelve digits (YYYYMMDDhhmm), '' when its name carries none. */
    private static function versionOf(string $report): string
    {
        return preg_match('/\A\d{4}-\d{2}-\d{2}-.+-(\d{4})[.-](\d{2})[.-](\d{2})[.-](\d{4})\.md\z/', basename($report), $m) === 1 ? $m[1] . $m[2] . $m[3] . $m[4] : '';
    }

    /** @return array<string, int> */
    public static function phpstanBaseline(string $projectRoot): array
    {
        $baseline = @file_get_contents($projectRoot . '/' . self::PHPSTAN_BASELINE);
        if ($baseline === false) {
            throw new \RuntimeException(self::PHPSTAN_BASELINE . ' is missing');
        }
        // A count may carry a trailing NEON comment (`count: 3 # why`).
        preg_match_all('/^\s+count:\s*(\d+)\s*(?:#.*)?$/m', $baseline, $counts);
        $ceiling = JsonFile::read($projectRoot . '/' . self::PHPSTAN_CEILING)['ceiling'] ?? null;
        if (!is_int($ceiling)) {
            throw new \RuntimeException(self::PHPSTAN_CEILING . ' holds no ceiling');
        }

        return ['phpstan.baseline-errors' => array_sum(array_map('intval', $counts[1])), 'phpstan.ceiling' => $ceiling];
    }

    /** @return array<string, Reading> by criterion id */
    public function read(): array
    {
        $readings = [];
        foreach ($this->measured as $criterion => $metrics) {
            try {
                $values = [];
                foreach ($metrics as $id => $metric) {
                    $values[$id] = $metric->measure($this->projectRoot)->total();
                }
                $readings[$criterion] = new Reading($criterion, $values);
            } catch (\RuntimeException $e) {
                // A metric that cannot read the tree is reported, never read as 0.
                $readings[$criterion] = Reading::failed($criterion, $e->getMessage());
            }
        }
        foreach ($this->recorded as $criterion => $ids) {
            $readings[$criterion] = $this->fromLedger($criterion, $ids);
        }
        foreach ($this->counted as $criterion => $count) {
            try {
                $readings[$criterion] = new Reading($criterion, $count($this->projectRoot));
            } catch (\RuntimeException $e) {
                $readings[$criterion] = Reading::failed($criterion, $e->getMessage());
            }
        }

        return $readings;
    }

    /** @param list<string> $ids */
    private function fromLedger(string $criterion, array $ids): Reading
    {
        $path = $this->projectRoot . '/' . QualityLedger::BASELINE;
        if (!is_file($path)) {
            return Reading::failed($criterion, QualityLedger::BASELINE . ' is missing');
        }
        try {
            $metrics = JsonFile::read($path)['metrics'] ?? null;
        } catch (\RuntimeException $e) {
            return Reading::failed($criterion, $e->getMessage());
        }
        $values = [];
        foreach ($ids as $id) {
            $total = is_array($metrics) && is_array($metrics[$id] ?? null) ? ($metrics[$id]['total'] ?? null) : null;
            if (!is_int($total)) {
                // A ratchet the ledger does not hold is the finding, not a zero.
                return Reading::failed($criterion, "the ledger holds no {$id}");
            }
            $values[$id] = $total;
        }

        return new Reading($criterion, $values);
    }
}

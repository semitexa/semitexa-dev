<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\State;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\State\FrameworkEvidence;
use Semitexa\Dev\Application\Service\Ai\State\FrameworkRubric;
use Semitexa\Dev\Application\Service\Ai\State\Reading;
use Semitexa\Dev\Application\Service\Ai\State\ScoreRecord;
use Semitexa\Dev\Application\Service\Ai\State\StateReport;
use Semitexa\Dev\Application\Service\Quality\QualityLedger;
use Semitexa\Dev\Tests\Unit\Ai\State\Fixture\FixedMetric;

/**
 * Computed criteria read their evidence on every run (tk-state-computed-criteria):
 * measured, shown beside the points, and compared with what it read when they
 * were given.
 */
final class FrameworkEvidenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-evidence-' . uniqid('', true);
        mkdir(dirname($this->root . '/' . QualityLedger::BASELINE), 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @param array<string, int> $totals */
    private function ledger(array $totals): void
    {
        $metrics = [];
        foreach ($totals as $id => $total) {
            $metrics[$id] = ['total' => $total, 'breakdown' => []];
        }
        file_put_contents($this->root . '/' . QualityLedger::BASELINE, json_encode(['metrics' => $metrics]));
    }

    #[Test]
    public function measured_metrics_are_read_as_totals_and_the_ledger_as_recorded(): void
    {
        $this->ledger(['requests.query-shapes' => 4, 'requests.duplicate-queries' => 0]);
        $readings = (new FrameworkEvidence(
            $this->root,
            ['right.test-quality' => ['tests.skip-calls' => new FixedMetric(['orm' => 11, 'core' => 5]), 'packages.without-tests' => new FixedMetric([])]],
            ['fast.speed-ratchet' => ['requests.query-shapes', 'requests.duplicate-queries']],
        ))->read();

        self::assertSame('tests.skip-calls 16, packages.without-tests 0', $readings['right.test-quality']->text());
        self::assertSame(['requests.query-shapes' => 4, 'requests.duplicate-queries' => 0], $readings['fast.speed-ratchet']->values);
    }

    /** A reading that cannot be taken says so; it never reads as 0, which would look like the best score. */
    #[Test]
    public function what_cannot_be_read_is_reported_never_taken_as_zero(): void
    {
        $this->ledger(['requests.query-shapes' => 4]);
        $readings = (new FrameworkEvidence(
            $this->root,
            ['right.test-quality' => ['tests.skip-calls' => new FixedMetric([], 'cannot read tests/FooTest.php')]],
            ['fast.speed-ratchet' => ['requests.query-shapes', 'requests.duplicate-queries']],
        ))->read();

        self::assertSame('could not read: cannot read tests/FooTest.php', $readings['right.test-quality']->text());
        self::assertSame('could not read: the ledger holds no requests.duplicate-queries', $readings['fast.speed-ratchet']->text());
        self::assertNull($readings['right.test-quality']->changedSince(['tests.skip-calls' => 0]), 'a failed reading compares with nothing');

        unlink($this->root . '/' . QualityLedger::BASELINE);
        $missing = (new FrameworkEvidence($this->root, [], ['fast.speed-ratchet' => ['requests.query-shapes']]))->read();
        self::assertSame('could not read: ' . QualityLedger::BASELINE . ' is missing', $missing['fast.speed-ratchet']->text());
    }

    #[Test]
    public function the_report_shows_the_reading_and_whether_it_moved_since_the_points_were_given(): void
    {
        $scored = static fn (?array $reading): ScoreRecord => new ScoreRecord('right.test-quality', 7, 'ai:quality check', 'agent', '2026-11-01T10:00:00+00:00', '2026-12-01T00:00:00+00:00', $reading);
        $now = new \DateTimeImmutable('2026-11-02T10:00:00+00:00');
        $reading = ['right.test-quality' => new Reading('right.test-quality', ['tests.skip-calls' => 87, 'packages.without-tests' => 0])];
        $row = static fn (array $report): array => array_values(array_filter($report['phases']['right']['criteria'], static fn (array $c): bool => $c['criterion'] === 'right.test-quality'))[0];

        $same = $row(StateReport::build(FrameworkRubric::rubric(), ['right.test-quality' => $scored(['packages.without-tests' => 0, 'tests.skip-calls' => 87])], $now, $reading));
        self::assertSame(['tests.skip-calls 87, packages.without-tests 0', false, 7], [$same['reading'], $same['reading_changed'], $same['points']]);

        $moved = $row(StateReport::build(FrameworkRubric::rubric(), ['right.test-quality' => $scored(['tests.skip-calls' => 91, 'packages.without-tests' => 0])], $now, $reading));
        self::assertTrue($moved['reading_changed']);
        self::assertSame(7, $moved['points'], 'a moved reading asks for a look; it does not rescore');

        $before = $row(StateReport::build(FrameworkRubric::rubric(), ['right.test-quality' => $scored(null)], $now, $reading));
        self::assertNull($before['reading_changed'], 'a score from before readings has nothing to compare');
    }

    #[Test]
    public function a_score_keeps_its_reading_through_the_file_and_refuses_a_malformed_one(): void
    {
        $record = new ScoreRecord('right.test-quality', 7, 'ai:quality check', 'agent', '2026-11-01T10:00:00+00:00', '2026-12-01T00:00:00+00:00', ['tests.skip-calls' => 87]);
        self::assertSame(['tests.skip-calls' => 87], ScoreRecord::fromArray($record->toArray())->reading);
        self::assertArrayNotHasKey('reading', (new ScoreRecord('work.installs', 20, 'x', 'agent', '2026-11-01T10:00:00+00:00', '2026-12-01T00:00:00+00:00'))->toArray());

        $this->expectException(\RuntimeException::class);
        ScoreRecord::fromArray(['reading' => ['tests.skip-calls' => '87']] + $record->toArray());
    }

    /** Open bug tasks: a bug recipe, not done or discarded. A task file that does not read fails the reading. */
    #[Test]
    public function known_bugs_count_the_open_bug_tasks(): void
    {
        mkdir($this->root . '/var/ai-work/tasks', 0777, true);
        $task = fn (string $id, string $recipe, string $status) => file_put_contents($this->root . "/var/ai-work/tasks/{$id}.json", json_encode(['id' => $id, 'recipe' => $recipe, 'status' => $status]));
        $task('a', 'fix_bug', 'new');
        $task('b', 'bugfix', 'in_progress');
        $task('c', 'debug_investigate', 'blocked');
        $task('d', 'fix_bug', 'done');
        $task('e', 'fix_bug', 'discarded');
        $task('f', 'add_feature', 'new');

        self::assertSame(['bugs.open' => 3], FrameworkEvidence::openBugTasks($this->root));

        file_put_contents($this->root . '/var/ai-work/tasks/g.json', '{not json');
        $this->expectException(\RuntimeException::class);
        FrameworkEvidence::openBugTasks($this->root);
    }

    #[Test]
    public function static_analysis_reads_the_baseline_errors_and_the_ceiling(): void
    {
        mkdir(dirname($this->root . '/' . FrameworkEvidence::PHPSTAN_BASELINE), 0777, true);
        file_put_contents($this->root . '/' . FrameworkEvidence::PHPSTAN_BASELINE, "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: '#^a$#'\n\t\t\tcount: 3\n\t\t\tpath: x.php\n\n\t\t-\n\t\t\tmessage: '#^b$#'\n\t\t\tcount: 4\n\t\t\tpath: y.php\n");
        file_put_contents($this->root . '/' . FrameworkEvidence::PHPSTAN_CEILING, json_encode(['ceiling' => 1]));

        self::assertSame(['phpstan.baseline-errors' => 7, 'phpstan.ceiling' => 1], FrameworkEvidence::phpstanBaseline($this->root));
    }

    /** The framework's own readers: one per criterion that has one, every one a criterion of the rubric. */
    #[Test]
    public function the_framework_reads_test_quality_and_the_speed_ratchet(): void
    {
        $this->ledger(['requests.query-shapes' => 1, 'requests.duplicate-queries' => 0, 'requests.repeated-statements' => 0]);
        $readings = FrameworkEvidence::forProject($this->root)->read();

        self::assertSame(['right.test-quality', 'fast.speed-ratchet', 'work.known-bugs', 'right.static-analysis'], array_keys($readings));
        self::assertSame('could not read: ' . FrameworkEvidence::PHPSTAN_BASELINE . ' is missing', $readings['right.static-analysis']->text(), 'no baseline is no reading, never 0 errors');
        foreach (array_keys($readings) as $id) {
            self::assertNotNull(FrameworkRubric::rubric()->criterion($id), $id);
        }
        self::assertSame(['tests.skip-calls' => 0, 'packages.without-tests' => 0], $readings['right.test-quality']->values, 'an empty tree has nothing to skip');

        // A tree with one skip and one package without tests reads as such:
        // the readers measure the tree, they do not answer 0.
        mkdir($this->root . '/packages/semitexa-probe/tests', 0777, true);
        // Spelled in two halves: the call written whole would be a skip of this repository's own.
        file_put_contents($this->root . '/packages/semitexa-probe/tests/ProbeTest.php', "<?php\n\$this->mark" . "TestSkipped('probe');\n");
        mkdir($this->root . '/packages/semitexa-bare/src', 0777, true);
        file_put_contents($this->root . '/packages/semitexa-bare/src/Bare.php', "<?php\n");
        self::assertSame(['tests.skip-calls' => 1, 'packages.without-tests' => 1], FrameworkEvidence::forProject($this->root)->read()['right.test-quality']->values);
    }
}

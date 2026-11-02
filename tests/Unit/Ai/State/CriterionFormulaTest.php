<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\State;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\State\FrameworkRubric;
use Semitexa\Dev\Application\Service\Ai\State\Reading;
use Semitexa\Dev\Application\Service\Ai\State\ScoreRecord;
use Semitexa\Dev\Application\Service\Ai\State\StateReport;

/**
 * Semitexa scores its computed criteria from what it measures, not from what
 * someone typed: on 2026-11-02 a hand-kept board moved down at a found defect
 * and stayed put while the phpstan baseline fell from 502 to 317 entries.
 */
final class CriterionFormulaTest extends TestCase
{
    private const NOW = '2026-11-02T18:00:00+00:00';

    /** @return array<string, mixed> */
    private static function row(array $report, string $id): array
    {
        foreach ($report['phases'] as $phase) {
            foreach ($phase['criteria'] as $c) {
                if ($c['criterion'] === $id) {
                    return $c;
                }
            }
        }
        self::fail("no {$id}");
    }

    #[Test]
    public function the_calibration_reproduces_the_board_it_replaced(): void
    {
        $f = FrameworkRubric::formulas();

        self::assertSame(20, $f['work.installs']->points(['release.published-install-pass' => 1], 20));
        self::assertSame(0, $f['work.installs']->points(['release.published-install-pass' => 0], 20));
        self::assertSame(13, $f['right.static-analysis']->points(['phpstan.baseline-errors' => 369, 'phpstan.ceiling' => 1], 20));
        self::assertSame(20, $f['right.static-analysis']->points(['phpstan.baseline-errors' => 0, 'phpstan.ceiling' => 1], 20));
        self::assertSame(0, $f['right.static-analysis']->points(['phpstan.baseline-errors' => 0, 'phpstan.ceiling' => 5], 20), 'a ceiling above 1 lets errors in past the baseline');
        self::assertSame(0, $f['right.static-analysis']->points(['phpstan.baseline-errors' => 1500, 'phpstan.ceiling' => 1], 20), 'clamped at 0');
        self::assertSame(7, $f['right.test-quality']->points(['tests.skip-calls' => 87, 'packages.without-tests' => 0], 10));
        self::assertSame(10, $f['right.test-quality']->points(['tests.skip-calls' => 0, 'packages.without-tests' => 0], 10));
        self::assertNull($f['right.test-quality']->points(['tests.skip-calls' => 87], 10), 'a reading without what the formula needs says nothing');
    }

    #[Test]
    public function a_computed_criterion_counts_what_was_measured_and_not_the_record(): void
    {
        $records = ['right.static-analysis' => new ScoreRecord('right.static-analysis', 10, 'typed by hand 30.10', 'agent', '2026-10-30T10:00:00+00:00', '2026-12-01T00:00:00+00:00')];
        $readings = ['right.static-analysis' => new Reading('right.static-analysis', ['phpstan.baseline-errors' => 369, 'phpstan.ceiling' => 1])];

        $report = StateReport::build(FrameworkRubric::rubric(), $records, new \DateTimeImmutable(self::NOW), $readings, FrameworkRubric::formulas());
        $row = self::row($report, 'right.static-analysis');

        self::assertSame([13, 'computed', 'semitexa'], [$row['points'], $row['status'], $row['by']]);
        self::assertStringContainsString('phpstan.baseline-errors 369', (string) $row['evidence']);
        self::assertSame(13, $report['phases']['right']['percent'], 'the phase counts the computed 13, not the recorded 10');
    }

    #[Test]
    public function a_failed_reading_falls_back_to_the_record_and_never_to_zero(): void
    {
        $records = ['work.installs' => new ScoreRecord('work.installs', 20, 'release report 01.11', 'agent', '2026-11-01T10:00:00+00:00', '2026-12-01T00:00:00+00:00')];
        $readings = ['work.installs' => Reading::failed('work.installs', 'no release report')];

        $row = self::row(StateReport::build(FrameworkRubric::rubric(), $records, new \DateTimeImmutable(self::NOW), $readings, FrameworkRubric::formulas()), 'work.installs');

        self::assertSame([20, 'current'], [$row['points'], $row['status']]);
    }
}

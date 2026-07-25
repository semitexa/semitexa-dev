<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Impact;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Impact\FileImpact;
use Semitexa\Dev\Application\Service\Ai\Verify\Impact\ImpactProbe;
use Semitexa\Dev\Application\Service\Ai\Verify\Impact\ImpactReport;

final class ImpactReportTest extends TestCase
{
    #[Test]
    public function max_band_is_the_highest_file_band(): void
    {
        $report = ImpactReport::of([
            new FileImpact('a.php', true, 1, 1, 'low'),
            new FileImpact('b.php', true, 30, 4, 'high'),
            new FileImpact('c.php', true, 6, 2, 'medium'),
        ]);

        self::assertSame('high', $report->maxBand());
        self::assertFalse($report->stale);
        self::assertSame('b.php', $report->hottest()?->path);
    }

    #[Test]
    public function all_unresolved_files_do_not_read_as_low_impact(): void
    {
        // Review finding on PR #39: seeding the search with 'low' meant a set of
        // files that have no graph node at all reported max='low' — "safe" —
        // when impact was never computed. Fail-closed means saying so.
        $report = ImpactReport::of([
            FileImpact::unresolved('config/app.php'),
            FileImpact::unresolved('docs/readme.md'),
        ]);

        self::assertSame('unresolved', $report->maxBand());
        self::assertFalse($report->stale);
    }

    #[Test]
    public function an_empty_change_set_is_still_low(): void
    {
        // Nothing changed is genuinely low impact, not an unresolved lookup.
        self::assertSame('low', ImpactReport::of([])->maxBand());
    }

    #[Test]
    public function stale_report_is_fail_closed_to_unknown(): void
    {
        $report = ImpactReport::stale(['a.php', 'b.php'], 'graph never generated');

        self::assertTrue($report->stale);
        self::assertSame('unknown', $report->maxBand());
        foreach ($report->files as $file) {
            self::assertSame('unknown', $file->band);
        }
        self::assertSame('graph never generated', $report->toSummary()['note']);
    }

    #[Test]
    public function graph_behind_a_changed_file_is_also_stale(): void
    {
        $report = ImpactReport::behind([
            new FileImpact('a.php', true, 50, 5, 'high'),
        ]);

        // Numbers exist but must not read as trustworthy while the graph lags the diff.
        self::assertTrue($report->stale);
        self::assertSame('unknown', $report->maxBand());
    }

    #[Test]
    public function unresolved_file_is_not_treated_as_safe(): void
    {
        $file = FileImpact::unresolved('config/app.php');

        self::assertFalse($file->resolved);
        self::assertSame('unresolved', $file->band);
        self::assertSame(0, $file->dependents);
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public static function bandCases(): array
    {
        return [
            'isolated leaf'        => [0, 1, 'low'],
            'few dependents'       => [5, 1, 'medium'],
            'two modules'          => [1, 2, 'medium'],
            'many dependents'      => [20, 1, 'high'],
            'three modules'        => [2, 3, 'high'],
            'just below medium'    => [4, 1, 'low'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('bandCases')]
    public function band_thresholds_map_dependents_and_modules(int $dependents, int $modules, string $expected): void
    {
        $band = new \ReflectionMethod(ImpactProbe::class, 'band');
        $band->setAccessible(true);

        self::assertSame($expected, $band->invoke(null, $dependents, $modules));
    }
}

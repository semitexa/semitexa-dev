<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\State;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\State\FrameworkRubric;
use Semitexa\Dev\Application\Service\Ai\State\ScoreRecord;
use Semitexa\Dev\Application\Service\Ai\State\StateReport;

/** What ai:state shows: current scores count, stale and unscored ones are unknown, the biggest gaps first. */
final class StateReportTest extends TestCase
{
    private const NOW = '2026-11-01T15:00:00+00:00';

    /** @param array<string, int> $points @return array<string, ScoreRecord> */
    private static function records(array $points, string $expires = '2026-12-01T00:00:00+00:00'): array
    {
        $records = [];
        foreach ($points as $id => $p) {
            $records[$id] = new ScoreRecord($id, $p, 'scorecard 2026-11-01', 'agent', '2026-10-30T15:00:00+00:00', $expires);
        }

        return $records;
    }

    #[Test]
    public function current_scores_give_the_scorecard_s_line(): void
    {
        $records = self::records([
            'work.installs' => 20, 'work.release-tests' => 20, 'work.known-bugs' => 14, 'work.capabilities' => 20, 'work.real-load' => 13, 'work.users-get-fixes' => 10,
            'right.reliability-audits' => 19, 'right.static-analysis' => 10, 'right.architecture' => 8, 'right.ratchets' => 15, 'right.test-quality' => 7, 'right.stability-contract' => 19,
            'fast.hot-paths' => 12, 'fast.audit-rest' => 0, 'fast.concurrency' => 4, 'fast.measured-performance' => 3, 'fast.platform' => 6, 'fast.speed-ratchet' => 8,
        ]);
        $report = StateReport::build(FrameworkRubric::rubric(), $records, new \DateTimeImmutable(self::NOW));

        self::assertSame('work 97% · right 78% · fast 33%', StateReport::line($report));
        self::assertSame(
            [['criterion' => 'fast.measured-performance', 'title' => 'Measured performance', 'gap' => 22], ['criterion' => 'fast.concurrency', 'title' => 'Behaviour under concurrency', 'gap' => 16], ['criterion' => 'fast.hot-paths', 'title' => 'Hot paths optimised', 'gap' => 13]],
            $report['phases']['fast']['raise'],
        );
        self::assertSame(['current', 2, 'scorecard 2026-11-01'], [$report['phases']['work']['criteria'][0]['status'], $report['phases']['work']['criteria'][0]['age_days'], $report['phases']['work']['criteria'][0]['evidence']]);
    }

    #[Test]
    public function a_stale_score_is_unknown_and_counts_nothing(): void
    {
        $records = self::records(['work.installs' => 20], '2026-11-01T14:59:59+00:00') + self::records(['work.release-tests' => 20]);
        $report = StateReport::build(FrameworkRubric::rubric(), $records, new \DateTimeImmutable(self::NOW));

        $installs = $report['phases']['work']['criteria'][0];
        self::assertSame(['stale', null, 'scorecard 2026-11-01'], [$installs['status'], $installs['points'], $installs['evidence']]);
        self::assertSame(20, $report['phases']['work']['percent'], 'only the current 20 counts');
        // The unknown counts its whole weight as the gap.
        self::assertSame(['work.capabilities', 'work.installs', 'work.known-bugs'], array_column($report['phases']['work']['raise'], 'criterion'));
        self::assertSame('work 20% (5 unknown) · right ? · fast ?', StateReport::line($report));
    }

    #[Test]
    public function a_score_is_stale_at_its_very_expiry_and_an_orphan_is_shown_apart(): void
    {
        $records = self::records(['work.installs' => 20], self::NOW) + self::records(['work.retired' => 5, 'right.test-quality' => 11]);
        $report = StateReport::build(FrameworkRubric::rubric(), $records, new \DateTimeImmutable(self::NOW));

        self::assertSame('stale', $report['phases']['work']['criteria'][0]['status'], 'expires_at == now is past');
        self::assertSame([
            ['criterion' => 'work.retired', 'points' => 5, 'why' => 'no criterion of this rubric'],
            ['criterion' => 'right.test-quality', 'points' => 11, 'why' => 'above its weight 10'],
        ], $report['orphans']);
        self::assertSame('work ? · right ? · fast ?', StateReport::line($report));
    }
}

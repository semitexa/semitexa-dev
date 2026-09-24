<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Quality\QualityAdvisor;
use Semitexa\Dev\Application\Service\Quality\QualityLedger;

/**
 * The order `ai:quality next` and `ai:orient` point in: the metric nobody has
 * moved for longest first, its biggest key first, and no single metric allowed
 * to fill the list.
 */
final class QualityAdvisorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-advisor-' . uniqid();
        mkdir(dirname($this->root . '/' . QualityLedger::BASELINE), 0o755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/' . QualityLedger::BASELINE);
        @unlink($this->root . '/' . QualityLedger::HISTORY);
        foreach (['packages/semitexa-dev/resources/quality', 'packages/semitexa-dev/resources', 'packages/semitexa-dev', 'packages', ''] as $d) {
            @rmdir(rtrim($this->root . '/' . $d, '/'));
        }
    }

    #[Test]
    public function the_stalest_metric_leads_and_the_list_alternates(): void
    {
        $this->ledger([
            'fresh.metric' => ['a' => 1, 'b' => 9],
            'stale.metric' => ['x' => 2, 'y' => 5, 'z' => 1],
        ], [
            ['at' => '2026-09-01T00:00:00+00:00', 'metric' => 'stale.metric', 'event' => 'new'],
            ['at' => '2026-09-01T00:00:00+00:00', 'metric' => 'fresh.metric', 'event' => 'new'],
            // fresh.metric improved recently; stale.metric never has.
            ['at' => '2026-09-20T00:00:00+00:00', 'metric' => 'fresh.metric', 'event' => 'record'],
            // A raise is not an improvement and must not make a metric look fresh.
            ['at' => '2026-09-23T00:00:00+00:00', 'metric' => 'stale.metric', 'event' => 'accept'],
        ]);

        $targets = (new QualityAdvisor($this->root))->targets(4);

        self::assertSame(
            ['stale.metric:y', 'fresh.metric:b', 'stale.metric:x', 'fresh.metric:a'],
            array_map(static fn (array $t): string => $t['metric'] . ':' . $t['key'], $targets),
        );
        self::assertSame(5, $targets[0]['count']);
    }

    #[Test]
    public function an_empty_ledger_points_nowhere(): void
    {
        self::assertSame([], (new QualityAdvisor($this->root))->targets());
    }

    /**
     * @param array<string, array<string, int>> $metrics
     * @param list<array<string, string>>        $history
     */
    private function ledger(array $metrics, array $history): void
    {
        $data = ['metrics' => []];
        foreach ($metrics as $id => $breakdown) {
            $data['metrics'][$id] = ['total' => array_sum($breakdown), 'breakdown' => $breakdown, 'sees' => 's', 'blind' => 'b'];
        }
        file_put_contents($this->root . '/' . QualityLedger::BASELINE, json_encode($data));
        file_put_contents($this->root . '/' . QualityLedger::HISTORY, implode("\n", array_map('json_encode', $history)) . "\n");
    }
}

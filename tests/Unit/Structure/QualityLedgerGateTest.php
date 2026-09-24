<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Dev\Application\Service\Quality\QualityLedger;
use Semitexa\Dev\Application\Service\Quality\QualityMetricCatalog;
use Semitexa\Dev\Application\Service\Quality\Verdict;

/**
 * The quality ledger, held on every verification that runs this directory.
 *
 * ai:verify schedules tests/Unit/Structure for any PHP change (see
 * ProjectGuardTargets), and the release runs it in the full suite, so this is
 * where `ai:quality check` becomes a gate rather than a command someone has to
 * remember. Same rule as the command: a regression fails, and so does an
 * improvement that has not been recorded.
 */
final class QualityLedgerGateTest extends TestCase
{
    #[Test]
    public function every_metric_matches_its_recorded_baseline(): void
    {
        $root = dirname(__DIR__, 5);
        $ledger = new QualityLedger($root, QualityMetricCatalog::discover(new ClassDiscovery()));
        if (!$ledger->exists()) {
            self::markTestSkipped('no quality ledger outside the workspace');
        }

        $failing = array_filter($ledger->check(), static fn (Verdict $v): bool => !$v->passes());

        self::assertSame([], array_map(static function (Verdict $v): string {
            $moved = implode(', ', array_map(
                static fn (string $k, array $m): string => "{$k} {$m['from']}->{$m['to']}",
                array_keys($v->moved),
                $v->moved,
            ));

            return "{$v->metric} {$v->status} {$v->from} -> {$v->to}" . ($moved === '' ? '' : " ({$moved})")
                . ($v->status === Verdict::WORSE
                    ? ' — fix it, or: bin/semitexa ai:quality accept --metric=' . $v->metric . ' --reason="..."'
                    : ' — lock it in: bin/semitexa ai:quality record');
        }, array_values($failing)));
    }

    #[Test]
    public function the_ledger_sees_the_metrics_this_package_declares(): void
    {
        // A discovery that finds nothing would pass the gate above vacuously.
        $ids = array_keys(QualityMetricCatalog::discover(new ClassDiscovery()));

        self::assertContains('packages.without-tests', $ids);
        self::assertContains('tests.skip-calls', $ids);
    }
}

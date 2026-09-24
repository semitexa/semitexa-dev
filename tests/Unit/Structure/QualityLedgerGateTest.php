<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Dev\Application\Service\Quality\QualityLedger;
use Semitexa\Dev\Application\Service\Quality\QualityMetricCatalog;
use Semitexa\Dev\Application\Service\Quality\QualityScope;
use Semitexa\Dev\Application\Service\Quality\Verdict;

/**
 * The quality ledger, held on every verification that runs this directory.
 *
 * ai:verify schedules tests/Unit/Structure for any PHP change (see
 * ProjectGuardTargets), and the release runs it in the full suite. It fails on
 * a regression and on a metric nobody recorded. It does NOT fail on an
 * improvement: that stays the job of `ai:quality check`, because here it would
 * fail a release whose clone differs from the workspace, and fail an agent for
 * a fix someone else made.
 *
 * Scope: ai:verify passes SEMITEXA_QUALITY_SCOPE, the repos the change
 * touched. Only a rise in one of those fails the run — several agents share
 * one tree, and another agent's uncommitted regression elsewhere must not turn
 * this agent's verification red. Unset (the full suite, the release), every
 * rise counts.
 */
final class QualityLedgerGateTest extends TestCase
{
    #[Test]
    public function no_metric_rose_in_the_repos_this_change_touched(): void
    {
        $root = dirname(__DIR__, 5);
        $ledger = new QualityLedger($root, QualityMetricCatalog::discover(new ClassDiscovery()));
        if (!$ledger->exists()) {
            self::markTestSkipped('no quality ledger outside the workspace');
        }

        $scope = QualityScope::fromEnv();
        $failures = [];
        foreach ($ledger->check() as $v) {
            if ($v->status === Verdict::NEW) {
                $failures[] = "{$v->metric} is not recorded yet — bin/semitexa ai:quality record";
                continue;
            }
            $rose = $scope->rises($v);
            if ($rose === []) {
                continue;
            }
            $failures[] = sprintf(
                '%s rose: %s — fix it, or: bin/semitexa ai:quality accept --metric=%s --reason="..."',
                $v->metric,
                implode(', ', array_map(static fn (string $k, array $m): string => "{$k} {$m['from']}->{$m['to']}", array_keys($rose), $rose)),
                $v->metric,
            );
        }

        self::assertSame([], $failures, implode("\n", $failures));
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

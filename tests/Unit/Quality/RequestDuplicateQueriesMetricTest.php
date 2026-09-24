<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Quality\Metric\RequestDuplicateQueriesMetric;
use Semitexa\Dev\Application\Service\Quality\RequestCostProbe;

/**
 * What the request-cost metric makes of a traced request. The probe against a
 * live server is exercised by `ai:quality check --all`; this pins the counting.
 */
final class RequestDuplicateQueriesMetricTest extends TestCase
{
    /** @var array{cache: ?array<string, list<array<string, mixed>>>, unmeasured: list<string>} */
    private array $probeState;

    protected function setUp(): void
    {
        // The probe is process-wide: put back what an earlier test left, not an empty cache.
        $this->probeState = RequestCostProbe::snapshot();
    }

    protected function tearDown(): void
    {
        RequestCostProbe::restore($this->probeState);
    }

    #[Test]
    public function the_same_statement_with_the_same_bindings_is_a_duplicate(): void
    {
        RequestCostProbe::prime([
            'GET /feed' => [
                ['sql' => 'SELECT COUNT(*) FROM t', 'bindings' => []],
                ['sql' => "SELECT COUNT(*)\n  FROM t", 'bindings' => []],
                ['sql' => 'SELECT * FROM t WHERE id = ?', 'bindings' => [1]],
                // Same statement, different id: an N+1 shape, not a duplicate.
                ['sql' => 'SELECT * FROM t WHERE id = ?', 'bindings' => [2]],
            ],
            'GET /clean' => [['sql' => 'SELECT 1', 'bindings' => []]],
        ]);

        $m = (new RequestDuplicateQueriesMetric())->measure('/nowhere');

        self::assertSame(['GET /feed' => 1], $m->breakdown);
    }

    #[Test]
    public function a_route_the_probe_could_not_read_is_counted_not_dropped(): void
    {
        // Dropped, its duplicates would vanish with it and read as a fix.
        RequestCostProbe::prime(['GET /a' => []], ['GET /timed-out', 'GET /no-trace']);

        $m = (new RequestDuplicateQueriesMetric())->measure('/nowhere');

        self::assertSame(['(unmeasured routes)' => 2], $m->breakdown);
    }
}

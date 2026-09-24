<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Quality\Metric\RequestDuplicateQueriesMetric;
use Semitexa\Dev\Application\Service\Quality\Metric\RequestQueryShapesMetric;
use Semitexa\Dev\Application\Service\Quality\Metric\RequestRepeatedStatementsMetric;
use Semitexa\Dev\Application\Service\Quality\RequestCostProbe;
use Semitexa\Dev\Application\Service\Quality\SqlShape;

/**
 * The three request-cost metrics read the same traces and must split the waste
 * between them without counting any of it twice: an exact repeat is a
 * duplicate, a repeat with other values is an N+1, and the number of kinds is
 * the page's footprint.
 */
final class RequestShapeMetricsTest extends TestCase
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
    public function an_in_list_of_any_length_is_one_shape(): void
    {
        self::assertSame(
            SqlShape::of('SELECT * FROM t WHERE id IN (:in0, :in1)'),
            SqlShape::of("SELECT *\n FROM t WHERE id IN (:in0,:in1,:in2, :in3)"),
        );
    }

    #[Test]
    public function in_without_a_space_folds_too(): void
    {
        self::assertSame(
            SqlShape::of('SELECT * FROM t WHERE id IN (:in0, :in1)'),
            SqlShape::of('SELECT * FROM t WHERE id IN(:in0,:in1,:in2)'),
        );
    }

    #[Test]
    public function whitespace_inside_a_literal_is_part_of_the_value(): void
    {
        // 'a  b' and 'a b' are different strings; formatting outside them is not.
        self::assertNotSame(SqlShape::of("SELECT 1 WHERE x = 'a  b'"), SqlShape::of("SELECT 1 WHERE x = 'a b'"));
        self::assertSame(SqlShape::of("SELECT  1\nWHERE x = 'a  b'"), SqlShape::of("SELECT 1 WHERE x = 'a  b'"));
    }

    #[Test]
    public function named_bindings_in_another_order_are_the_same_execution(): void
    {
        self::assertSame(SqlShape::execution('SELECT 1', ['b' => 1, 'a' => 2]), SqlShape::execution('SELECT 1', ['a' => 2, 'b' => 1]));
        self::assertNotSame(SqlShape::execution('SELECT 1', [1, 2]), SqlShape::execution('SELECT 1', [2, 1]));
    }

    #[Test]
    public function each_waste_is_counted_by_exactly_one_metric(): void
    {
        RequestCostProbe::prime(['GET /orm' => [
            ['sql' => 'SELECT * FROM tags', 'bindings' => []],
            // N+1: the same shape per tag.
            ['sql' => 'SELECT COUNT(*) FROM links WHERE tag_id = :w0', 'bindings' => ['w0' => 1]],
            ['sql' => 'SELECT COUNT(*) FROM links WHERE tag_id = :w0', 'bindings' => ['w0' => 2]],
            ['sql' => 'SELECT COUNT(*) FROM links WHERE tag_id = :w0', 'bindings' => ['w0' => 3]],
            // An exact repeat of the first count: a duplicate, not a fourth N+1.
            ['sql' => 'SELECT COUNT(*) FROM links WHERE tag_id = :w0', 'bindings' => ['w0' => 1]],
        ]]);

        self::assertSame(['GET /orm' => 2], (new RequestQueryShapesMetric())->measure('/x')->breakdown);
        self::assertSame(['GET /orm' => 2], (new RequestRepeatedStatementsMetric())->measure('/x')->breakdown);
        self::assertSame(['GET /orm' => 1], (new RequestDuplicateQueriesMetric())->measure('/x')->breakdown);
    }
}

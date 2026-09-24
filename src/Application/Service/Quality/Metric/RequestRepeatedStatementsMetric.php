<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality\Metric;

use Semitexa\Dev\Application\Service\Quality\Measurement;
use Semitexa\Dev\Application\Service\Quality\QualityMetricInterface;
use Semitexa\Dev\Application\Service\Quality\RequestCostProbe;
use Semitexa\Dev\Application\Service\Quality\SqlShape;
use Semitexa\Dev\Attribute\AsQualityMetric;

/**
 * The N+1: one kind of statement run again and again with different values.
 *
 * requests.duplicate-queries cannot see it — every repetition asks about a
 * different id, so no two are identical. /playground/orm ran the same
 * `COUNT(*) ... WHERE tag_id = ?` once per tag, eight times, and the same per
 * category: ten round trips a single GROUP BY answers. Counted here as the
 * executions of a shape beyond its first, less the exact duplicates the other
 * metric already owns, so one waste is never counted twice.
 */
#[AsQualityMetric(
    id: 'requests.repeated-statements',
    sees: 'per GET route without path parameters: re-executions of one SQL shape with different bindings in a single request (the N+1 pattern), read from a real traced request',
    blind: 'which repetitions are necessary; it grows with the rows a page iterates, so it is only comparable where the data is (the demo seeds are fixed, a production database is not)',
    tier: AsQualityMetric::TIER_RELEASE,
)]
final class RequestRepeatedStatementsMetric implements QualityMetricInterface
{
    public function measure(string $projectRoot): Measurement
    {
        $out = [];
        foreach (RequestCostProbe::queriesByRoute($projectRoot) as $route => $queries) {
            $shapes = [];
            $exact = [];
            foreach ($queries as $q) {
                $shape = SqlShape::of((string) ($q['sql'] ?? ''));
                $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
                $exact[$shape . '|' . json_encode($q['bindings'] ?? $q['params'] ?? null)] = true;
            }
            $reExecutions = count($queries) - count($shapes);
            $duplicates = count($queries) - count($exact);
            $out[$route] = $reExecutions - $duplicates;
        }

        return new Measurement($out);
    }
}

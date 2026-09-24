<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality\Metric;

use Semitexa\Dev\Application\Service\Quality\Measurement;
use Semitexa\Dev\Application\Service\Quality\QualityMetricInterface;
use Semitexa\Dev\Application\Service\Quality\RequestCostProbe;
use Semitexa\Dev\Application\Service\Quality\SqlShape;
use Semitexa\Dev\Attribute\AsQualityMetric;

/**
 * How many different kinds of statement a page runs.
 *
 * Unlike the two repetition metrics this depends on the code and not on the
 * rows: measured on /playground/orm it was 7 in the workspace and 6 in the
 * release clone, and the one difference was a code change (count() -> exists()).
 * That is what makes it a budget: a page that starts asking the database a new
 * kind of question has become more expensive, and the ledger asks for that to
 * be a decision — `ai:quality accept --reason` — rather than a side effect.
 */
#[AsQualityMetric(
    id: 'requests.query-shapes',
    sees: 'per GET route without path parameters: distinct SQL shapes (whitespace collapsed, IN lists folded) in one traced request',
    blind: 'how expensive each shape is, and how often it runs — a slow shape counts as one, and so does a shape run a hundred times',
    tier: AsQualityMetric::TIER_RELEASE,
)]
final class RequestQueryShapesMetric implements QualityMetricInterface
{
    public function measure(string $projectRoot): Measurement
    {
        $out = [];
        foreach (RequestCostProbe::queriesByRoute($projectRoot) as $route => $queries) {
            $out[$route] = count(array_unique(array_map(
                static fn (array $q): string => SqlShape::of((string) ($q['sql'] ?? '')),
                $queries,
            )));
        }

        return new Measurement($out);
    }
}

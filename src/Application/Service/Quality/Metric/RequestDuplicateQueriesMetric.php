<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality\Metric;

use Semitexa\Dev\Application\Service\Quality\Measurement;
use Semitexa\Dev\Application\Service\Quality\QualityMetricInterface;
use Semitexa\Dev\Application\Service\Quality\RequestCostProbe;
use Semitexa\Dev\Application\Service\Quality\SqlShape;
use Semitexa\Dev\Attribute\AsQualityMetric;

/**
 * Statements a page runs twice with the same parameters.
 *
 * Every one is waste: the answer is already in hand. It is also the shape the
 * framework's worst measured regression took — GET / issued the same settings
 * read 17 times, 3.8 ms in total, invisible to any duration threshold because
 * each round trip was 0.2 ms. The count is what gives it away, so the count is
 * what is ratcheted.
 */
#[AsQualityMetric(
    id: 'requests.duplicate-queries',
    sees: 'per GET route without path parameters: SQL statements repeated with identical SQL and bindings within one request, read from a real traced request to the running server',
    blind: 'routes with path parameters or behind authentication beyond their refusal, POST flows, repetition that differs only in bindings (an N+1 over distinct ids), and redacted bindings that differ in value but mask the same',
    tier: AsQualityMetric::TIER_RELEASE,
)]
final class RequestDuplicateQueriesMetric implements QualityMetricInterface
{
    public function measure(string $projectRoot): Measurement
    {
        $out = [];
        foreach (RequestCostProbe::queriesByRoute($projectRoot) as $route => $queries) {
            $seen = [];
            $duplicates = 0;
            foreach ($queries as $q) {
                $key = SqlShape::execution((string) ($q['sql'] ?? ''), is_array($q['bindings'] ?? $q['params'] ?? null) ? ($q['bindings'] ?? $q['params']) : null);
                $duplicates += isset($seen[$key]) ? 1 : 0;
                $seen[$key] = true;
            }
            $out[$route] = $duplicates;
        }
        // Counted as a key of its own so a route dropping out of the probe is a
        // regression the ratchet reports, not a quiet disappearance of its
        // duplicates that the ledger would read as a fix.
        $out['(unmeasured routes)'] = count(RequestCostProbe::unmeasured());

        return new Measurement($out);
    }
}

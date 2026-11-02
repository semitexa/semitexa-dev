<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

use Semitexa\Dev\Application\Service\Ai\Work\JsonFile;
use Semitexa\Dev\Application\Service\Quality\Metric\PackagesWithoutTestsMetric;
use Semitexa\Dev\Application\Service\Quality\Metric\TestSkipCallsMetric;
use Semitexa\Dev\Application\Service\Quality\QualityLedger;
use Semitexa\Dev\Application\Service\Quality\QualityMetricInterface;

/**
 * The evidence of FrameworkRubric's computed criteria, read on every run of
 * ai:state (tk-state-computed-criteria). Points stay a judgement over the
 * evidence: the scorecard has scored by deltas since 2026-10-19, and a formula
 * that turned a count into points would move every number at once. What
 * changes is that the evidence is no longer the text someone typed: it is
 * measured, shown beside the points, and compared with what it was when they
 * were given.
 *
 *   - right.test-quality: tests.skip-calls and packages.without-tests,
 *     measured in the tree as `ai:quality check` does.
 *   - fast.speed-ratchet: the requests.* metrics the ledger holds. They are
 *     measured by running requests, which the release preflight does; the
 *     ratchet is what the ledger has recorded.
 *
 * The other computed criteria (installs, release tests, known bugs, users get
 * fixes, audits, static analysis, the rest of the performance audit) have no
 * reader yet: their evidence is a release report, the backlog or a phpstan
 * run, and each is a provider of its own.
 */
final class FrameworkEvidence
{
    /**
     * @param array<string, array<string, QualityMetricInterface>> $measured criterion => metric id => metric
     * @param array<string, list<string>> $recorded criterion => ledger metric ids
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $measured,
        private readonly array $recorded,
    ) {}

    public static function forProject(string $projectRoot): self
    {
        return new self(
            $projectRoot,
            ['right.test-quality' => ['tests.skip-calls' => new TestSkipCallsMetric(), 'packages.without-tests' => new PackagesWithoutTestsMetric()]],
            ['fast.speed-ratchet' => ['requests.query-shapes', 'requests.duplicate-queries', 'requests.repeated-statements']],
        );
    }

    /** @return array<string, Reading> by criterion id */
    public function read(): array
    {
        $readings = [];
        foreach ($this->measured as $criterion => $metrics) {
            try {
                $values = [];
                foreach ($metrics as $id => $metric) {
                    $values[$id] = $metric->measure($this->projectRoot)->total();
                }
                $readings[$criterion] = new Reading($criterion, $values);
            } catch (\RuntimeException $e) {
                // A metric that cannot read the tree is reported, never read as 0.
                $readings[$criterion] = Reading::failed($criterion, $e->getMessage());
            }
        }
        foreach ($this->recorded as $criterion => $ids) {
            $readings[$criterion] = $this->fromLedger($criterion, $ids);
        }

        return $readings;
    }

    /** @param list<string> $ids */
    private function fromLedger(string $criterion, array $ids): Reading
    {
        $path = $this->projectRoot . '/' . QualityLedger::BASELINE;
        if (!is_file($path)) {
            return Reading::failed($criterion, QualityLedger::BASELINE . ' is missing');
        }
        try {
            $metrics = JsonFile::read($path)['metrics'] ?? null;
        } catch (\RuntimeException $e) {
            return Reading::failed($criterion, $e->getMessage());
        }
        $values = [];
        foreach ($ids as $id) {
            $total = is_array($metrics) && is_array($metrics[$id] ?? null) ? ($metrics[$id]['total'] ?? null) : null;
            if (!is_int($total)) {
                // A ratchet the ledger does not hold is the finding, not a zero.
                return Reading::failed($criterion, "the ledger holds no {$id}");
            }
            $values[$id] = $total;
        }

        return new Reading($criterion, $values);
    }
}

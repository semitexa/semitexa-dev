<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Impact;

use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Service\Analysis\ImpactAnalyzer;
use Semitexa\ProjectGraph\Application\Service\Support\UsesProjectGraphConnection;

/**
 * Read-only blast-radius probe for ai:verify.
 *
 * For each changed file it asks the project graph (via ProjectGraph's
 * ImpactAnalyzer) how many nodes transitively depend on it, then maps that to
 * a low|medium|high band. It NEVER mutates the graph and NEVER changes which
 * tests verify runs — it only annotates the verdict so inline-vs-epic and
 * scope decisions rest on real dependents instead of guesses (churn, file
 * size). Impact from diff size is a vanity metric; impact from graph edges is
 * a fact.
 *
 * Fail-closed on staleness: a graph that was never generated, or that predates
 * a changed file, yields band "unknown" rather than a falsely-low reading.
 */
final class ImpactProbe
{
    use UsesProjectGraphConnection;

    /** Thresholds are deliberately coarse and tunable; bands, not scores. */
    private const HIGH_DEPENDENTS   = 20;
    private const MEDIUM_DEPENDENTS = 5;
    private const HIGH_MODULES      = 3;
    private const MEDIUM_MODULES    = 2;
    private const MAX_DEPTH         = 5;

    public function __construct(
        private readonly ConnectionRegistry $connections,
    ) {
    }

    /**
     * @param list<string> $relativePaths repo-relative changed source files
     */
    public function probe(array $relativePaths): ImpactReport
    {
        if ($relativePaths === []) {
            return ImpactReport::empty();
        }

        try {
            $storage = $this->createProjectGraphStorage($this->connections);
        } catch (\Throwable $e) {
            return ImpactReport::stale($relativePaths, 'graph unavailable: ' . $e->getMessage());
        }

        $lastUpdate = $storage->getMeta('last_update');
        if ($lastUpdate === null) {
            return ImpactReport::stale($relativePaths, 'graph never generated — run ai:review-graph:generate');
        }
        $graphTs = (int) $lastUpdate;

        $root     = rtrim($this->getProjectRoot(), '/');
        $analyzer = new ImpactAnalyzer($storage);

        $files         = [];
        $graphIsBehind = false;

        foreach ($relativePaths as $path) {
            $relative = ltrim($path, '/');
            $abs      = $root . '/' . $relative;

            if (is_file($abs) && (int) @filemtime($abs) > $graphTs) {
                $graphIsBehind = true;
            }

            // The graph may record file paths as absolute or repo-relative
            // depending on how it was generated; probe both and union.
            $nodeIds = array_values(array_unique(array_merge(
                $storage->nodes->getNodeIdsByFile($abs),
                $storage->nodes->getNodeIdsByFile($relative),
            )));

            if ($nodeIds === []) {
                $files[] = FileImpact::unresolved($path);
                continue;
            }

            $result     = $analyzer->analyze($nodeIds, self::MAX_DEPTH);
            $dependents = $result->totalImpacted();
            $modules    = count($result->getModulesAffected());

            $files[] = new FileImpact($path, true, $dependents, $modules, self::band($dependents, $modules));
        }

        return $graphIsBehind ? ImpactReport::behind($files) : ImpactReport::of($files);
    }

    private static function band(int $dependents, int $modules): string
    {
        if ($dependents >= self::HIGH_DEPENDENTS || $modules >= self::HIGH_MODULES) {
            return 'high';
        }
        if ($dependents >= self::MEDIUM_DEPENDENTS || $modules >= self::MEDIUM_MODULES) {
            return 'medium';
        }

        return 'low';
    }

    private function getProjectRoot(): string
    {
        return ProjectRoot::get();
    }
}

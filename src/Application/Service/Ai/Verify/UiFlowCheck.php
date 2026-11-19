<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorPipeline;
use Semitexa\ProjectGraph\Application\Service\Findings\TestCode;
use Semitexa\ProjectGraph\Application\Service\Findings\UiFlowGapReport;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphBuilder;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Index\IncrementalEngine;
use Semitexa\ProjectGraph\Application\Service\Index\ModuleOfPath;
use Semitexa\ProjectGraph\Application\Service\Parser\PhpParserAdapter;
use Semitexa\ProjectGraph\Application\Service\Scanner\FileScanner;
use Semitexa\ProjectGraph\Application\Service\Scanner\IgnorePatternLoader;
use Semitexa\ProjectGraph\Application\Service\Support\GraphBuildMemory;
use Semitexa\ProjectGraph\Application\Service\Support\UsesProjectGraphConnection;

/**
 * `lint:ui-flow`: where the UI flow does not close in the modules a change
 * touched (ep-ui-flow-graph). A link or a form to a path no route answers, a
 * POST page no form reaches: {@see UiFlowGapReport}, the same findings as
 * `ai:review-graph:findings --kind=ui`.
 *
 * A warning, never a verdict. Each finding is something to look at and none
 * proves a defect (a script may send that POST; a link may be to another
 * application), so the result always passes, is never required, and carries
 * the findings as warnings. What the project already
 * decided about, with #[UiEntryPoint(reason: …)], goes to `accepted` with its
 * reason. Whatever goes wrong here (no graph, a graph that cannot be read) is
 * a skip with the reason, never a failure of the run.
 *
 * Only the modules of the changed files are reported: the rest of the
 * project's findings are not this change's.
 *
 * The graph is brought up to date first, so a link written a moment ago is
 * read, but only when that is the cheap incremental refresh. Three cases skip
 * instead, each naming what to run: a graph never built; a graph built by
 * other extraction code (any change of semitexa/project-graph's source),
 * which a refresh would rebuild whole, for minutes; and a graph another
 * process is writing, whose lock is tried and not waited for.
 *
 * The findings are the result's `warnings`, with severity "warning": not its
 * diagnostics, which ai:verify lists as the violations of the run.
 */
final class UiFlowCheck
{
    use UsesProjectGraphConnection;

    /** @var \Closure(): GraphStorage */
    private readonly \Closure $open;

    /**
     * @param (\Closure(): GraphStorage)|null $open opens the project graph; null opens the project's own through $connections
     * @param string|null $projectRoot where the changed files live; null: the running project
     */
    public function __construct(
        private readonly ?ConnectionRegistry $connections = null,
        ?\Closure $open = null,
        private readonly ?string $projectRoot = null,
    ) {
        $this->open = $open ?? function (): GraphStorage {
            if ($this->connections === null) {
                throw new \RuntimeException('no database connection bound in this context');
            }

            return $this->createProjectGraphStorage($this->connections);
        };
    }

    /**
     * What the executor calls. Whatever happens inside, the run is not failed
     * by it: the executor turns a throw into a failure, so nothing is let out
     * of here, and a run with no way to open a graph skips.
     */
    public static function run(?self $check, VerificationTarget $target): VerificationResult
    {
        if ($check === null) {
            return self::skipped($target, 'ui flow: no project graph connection in this context');
        }
        try {
            return $check->check($target);
        } catch (\Throwable $e) {
            return self::skipped($target, 'ui flow: could not be read (' . SignalText::compress($e->getMessage()) . ')');
        }
    }

    public function check(VerificationTarget $target): VerificationResult
    {
        try {
            $storage = ($this->open)();
            if ((int) ($storage->getMeta('total_nodes') ?: 0) === 0) {
                return self::skipped($target, 'ui flow: no project graph yet; build one with bin/semitexa ai:review-graph:generate to get these warnings');
            }
            $root = rtrim((string) $storage->getMeta('project_root'), '/');
            // By the graph's own rule for a path, so a deleted file still names
            // its module: removing a route is how a link elsewhere goes dead.
            $modules = [];
            foreach ($target->triggeredBy as $path) {
                $module = ModuleOfPath::of($root, $root . '/' . ltrim($path, '/'));
                if ($module !== '') {
                    $modules[$module] = true;
                }
            }
            ksort($modules);
            if ($modules === []) {
                return self::skipped($target, 'ui flow: the changed files belong to no module the graph names');
            }

            $engine = new IncrementalEngine(
                new FileScanner(new IgnorePatternLoader()),
                new PhpParserAdapter(),
                new ExtractorPipeline(ExtractorPipeline::default()),
                new GraphBuilder($storage),
                $storage,
            );
            // Refreshing a graph other extraction code built is a full build:
            // minutes, inside a check that only warns.
            if (!$engine->isCurrentBuild()) {
                return self::skipped($target, 'ui flow: the project graph was built by an older version of the extractors, and refreshing it here would rebuild it whole; run bin/semitexa ai:review-graph:generate, then verify again for these warnings');
            }
            GraphBuildMemory::ensure();
            if ($engine->updateUnlessBusy($this->getProjectRoot()) === null) {
                return self::skipped($target, 'ui flow: another process is writing the project graph (ai:review-graph:generate, or another refresh); not waited for, verify again for these warnings');
            }

            $report = (new UiFlowGapReport())->collect($storage, null, TestCode::of($storage));
        } catch (\Throwable $e) {
            return self::skipped($target, 'ui flow: could not be read (' . SignalText::compress($e->getMessage()) . ')');
        }

        // Warnings, not diagnostics: a diagnostic is a violation of the run, and this run passes.
        $diagnostics = [];
        foreach ($report['findings'] as $finding) {
            if (!isset($modules[$finding['module']])) {
                continue;
            }
            $diagnostics[] = self::row($finding) + [
                'check' => VerificationTarget::TYPE_UI_FLOW,
                'severity' => 'warning',
                'fix' => $finding['rule'] === UiFlowGapReport::ORPHAN_POST
                    ? 'Point a form at it, or, when a script or another application sends it, say so on the payload: #[UiEntryPoint(reason: \'…\')]. A warning: it does not fail the run.'
                    : 'Correct the path or declare the route it means. A warning: it does not fail the run.',
            ];
        }
        $accepted = [];
        foreach ($report['accepted'] as $hit) {
            if (isset($modules[$hit['module']])) {
                $accepted[] = self::row($hit) + ['reason' => $hit['reason']];
            }
        }

        $in = implode(', ', array_keys($modules));
        $signal = $diagnostics === []
            ? "ui flow: nothing to look at in {$in}"
            : sprintf('ui flow: %d warning(s) in %s, none fails the run; first: %s %s', count($diagnostics), $in, $diagnostics[0]['rule'], $diagnostics[0]['file'] . ':' . $diagnostics[0]['line']);
        if ($accepted !== []) {
            $signal .= sprintf('; %d accepted with a reason', count($accepted));
        }

        return new VerificationResult(
            target: $target,
            status: VerificationResult::STATUS_PASS,
            exitCode: 0,
            signal: $signal,
            required: false,
            accepted: $accepted,
            warnings: $diagnostics,
        );
    }

    /**
     * @param array{id: string, rule: string, at: string, module: string, detail: string, subject: string} $finding
     * @return array{rule: string, identifier: string, id: string, file: string, line: int, module: string, subject: string, message: string}
     */
    private static function row(array $finding): array
    {
        $colon = strrpos($finding['at'], ':');

        return [
            'rule' => $finding['rule'],
            'identifier' => 'ui-flow.' . $finding['rule'],
            'id' => $finding['id'],
            'file' => $colon === false ? $finding['at'] : substr($finding['at'], 0, $colon),
            'line' => $colon === false ? 0 : (int) substr($finding['at'], $colon + 1),
            'module' => $finding['module'],
            'subject' => $finding['subject'],
            'message' => $finding['detail'],
        ];
    }

    private static function skipped(VerificationTarget $target, string $reason): VerificationResult
    {
        return new VerificationResult($target, VerificationResult::STATUS_SKIPPED, 0, $reason, required: false);
    }

    private function getProjectRoot(): string
    {
        return $this->projectRoot ?? ProjectRoot::get();
    }
}

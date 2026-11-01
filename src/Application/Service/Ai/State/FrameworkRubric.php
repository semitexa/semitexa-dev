<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

/**
 * Semitexa's own rubric: the 18 criteria the make-it scorecard has scored the
 * framework on since 2026-10-19 (var/docs/work-right-fast-2026-10-19.md), now
 * declared in code instead of a skill's markdown. Unchanged in title, weight
 * and evidence (FrameworkRubricTest reads the skill's tables and holds the two
 * together), so a number today compares with the ones before it; change one
 * only deliberately, and say so in the state's history.
 *
 * Computed: a command or file already gives the measurement (a release
 * report, the backlog, phpstan, the quality ledger). Judged: it is read by
 * hand today; fast.measured-performance stays judged until the ledger holds a
 * perf.* metric (tk-fast-bench-ledger-metric).
 */
final class FrameworkRubric
{
    public static function rubric(): Rubric
    {
        $c = static fn (string $id, string $title, int $weight, CriterionKind $kind, string $evidence): Criterion
            => new Criterion($id, Phase::forCriterion($id), $title, $weight, $kind, $evidence);
        $computed = CriterionKind::COMPUTED;
        $judged = CriterionKind::JUDGED;

        return new Rubric([
            $c('work.installs', 'Installs from public sources', 20, $computed, '`curl install.sh` → `server:start` → `orm:sync`; the Published install verdict of the last release'),
            $c('work.release-tests', 'Tests green on the release clone', 20, $computed, 'the last release preflight: phpunit, browser smoke, `system:doctor`, `composer audit`'),
            $c('work.known-bugs', 'Known functional bugs', 15, $computed, 'open bug tasks in the backlog; defects found during the run and not yet fixed'),
            $c('work.capabilities', 'Promised capabilities exist', 20, $judged, '(have + ½·partial) / promised × 20, from the capability-parity list'),
            $c('work.real-load', 'Proof under real load', 15, $judged, 'soak runs: hours, error rate, memory per hour, p99 first hour against last'),
            $c('work.users-get-fixes', 'Users get fixes', 10, $computed, 'every changed package reaches Packagist and the installer image'),

            $c('right.reliability-audits', 'Reliability audits closed', 20, $computed, 'the make-it-right audit epics, done against open'),
            $c('right.static-analysis', 'Static analysis', 20, $computed, 'phpstan baseline entries and errors above it; semantic-rule violations'),
            $c('right.architecture', 'Architecture / god classes', 15, $judged, '`StructuralOutlierBudgetTest` budgets; the largest classes by fan-in'),
            $c('right.ratchets', 'Ratchets that stop regressions', 15, $judged, 'quality ledger, phpstan ceiling, semantic-rule snapshot, module structure — all gating in `ai:verify` and the release'),
            $c('right.test-quality', 'Test quality', 10, $computed, '`ai:quality check`: `tests.skip-calls`, `packages.without-tests`'),
            $c('right.stability-contract', 'Stability contract for users', 20, $judged, 'semver and deprecation policy, marked public API, a BC gate, upgrade notes'),

            $c('fast.hot-paths', 'Hot paths optimised', 25, $judged, 'measured speed-ups on the paths the performance audit ranked high'),
            $c('fast.audit-rest', 'Rest of the performance audit', 10, $computed, 'its medium and low findings, done against open'),
            $c('fast.concurrency', 'Behaviour under concurrency', 20, $judged, 'contended locks and races found against fixed'),
            $c('fast.measured-performance', 'Measured performance', 25, $judged, 'a reproducible benchmark (req/s, p95/p99) recorded in the ledger'),
            $c('fast.platform', 'Platform (Swoole)', 10, $judged, 'the Swoole version and the tasks waiting on the next one'),
            $c('fast.speed-ratchet', 'Ratchet on speed', 10, $computed, '`requests.*` metrics in the ledger, failing a release on regression'),
        ]);
    }
}

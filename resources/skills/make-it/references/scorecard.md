# Scorecard: how ready each phase is

Every phase is scored out of 100 on six weighted criteria. The rubric is the
one used for `var/docs/work-right-fast-2026-10-19.md` and its re-scores, kept
unchanged so that a number today can be compared with that one. Change a
criterion or a weight only deliberately, and say so in the history; a score on
a different rubric is a different number.

The rubric is declared in code:
`Semitexa\Dev\Application\Service\Ai\State\FrameworkRubric` (criterion ids,
weights, computed or judged, evidence), and `FrameworkRubricTest` holds it to the
weights below and to the scorecard's numbers. The tables here mirror it; when
the two differ, the code is the rubric.

## The living scorecard

`var/docs/make-it/scorecard.md` in the project: the current table for all
three phases and a history below it, newest first. Create it on the first run
from the latest scores on record (say where they came from). Every run of the
skill keeps it current, **whatever phase it runs**: a phase is the work being
done, not the only number that can move.

## Rules

- **A score cites its evidence.** Each criterion's points name the command,
  file or PR that sets them — `ai:quality check`, the phpstan count, the
  backlog query, a soak report, a release report. A score with no evidence is
  an impression, and impressions were how the 2026-10-19 morning score came out
  six points high.
- **Scores move both ways.** A defect found while working on `fast` lowers
  `work` ("known functional bugs"); a god class found growing lowers `right`; a
  regression a release gate catches lowers the criterion it belongs to. Lower
  the number when the evidence appears, not when the fix lands; the fix raises
  it again.
- **A part rounds down until it holds.** 17.5 counts as 17 until a second
  measurement gives the same half.
- **Every change goes in the history**: date (UTC), phase, criterion, old →
  new, why, and the evidence. One line each.

## The report

- **In every tick report**, one line, before anything else:
  `work 86% · right 60% · fast 47%` — with the change since the last report
  where there is one (`right 60% → 62% ↑ phpstan 512 → 470`).
- **At the end of a run** (the operator stops it, or the phase's source is
  empty): the full table for all three phases, each criterion with its points
  and evidence, then what moved during the run and why, then what would raise
  each number most.

## Make it work

| Criterion | Weight | Evidence |
|---|---|---|
| Installs from public sources | 20 | `curl install.sh` → `server:start` → `orm:sync`; the Published install verdict of the last release |
| Tests green on the release clone | 20 | the last release preflight: phpunit, browser smoke, `system:doctor`, `composer audit` |
| Known functional bugs | 15 | open bug tasks in the backlog; defects found during the run and not yet fixed |
| Promised capabilities exist | 20 | (have + ½·partial) / promised × 20, from the capability-parity list |
| Proof under real load | 15 | soak runs: hours, error rate, memory per hour, p99 first hour against last |
| Users get fixes | 10 | every changed package reaches Packagist and the installer image |

## Make it right

| Criterion | Weight | Evidence |
|---|---|---|
| Reliability audits closed | 20 | the make-it-right audit epics, done against open |
| Static analysis | 20 | phpstan baseline entries and errors above it; semantic-rule violations |
| Architecture / god classes | 15 | `StructuralOutlierBudgetTest` budgets; the largest classes by fan-in |
| Ratchets that stop regressions | 15 | quality ledger, phpstan ceiling, semantic-rule snapshot, module structure — all gating in `ai:verify` and the release |
| Test quality | 10 | `ai:quality check`: `tests.skip-calls`, `packages.without-tests` |
| Stability contract for users | 20 | semver and deprecation policy, marked public API, a BC gate, upgrade notes |

## Make it fast

| Criterion | Weight | Evidence |
|---|---|---|
| Hot paths optimised | 25 | measured speed-ups on the paths the performance audit ranked high |
| Rest of the performance audit | 10 | its medium and low findings, done against open |
| Behaviour under concurrency | 20 | contended locks and races found against fixed |
| Measured performance | 25 | a reproducible benchmark (req/s, p95/p99) recorded in the ledger |
| Platform (Swoole) | 10 | the Swoole version and the tasks waiting on the next one |
| Ratchet on speed | 10 | `requests.*` metrics in the ledger, failing a release on regression |

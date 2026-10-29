# Profile: make it right

Structure, without changing behaviour. The question each change answers: is it
built the way it should be, and can nobody quietly undo that?

## Where the next change comes from

1. `bin/semitexa ai:quality next --limit=10` — the ledger names the largest
   remaining share of each metric that may only go down: `tests.skip-calls`
   (tests that skip instead of asserting), `packages.without-tests`.
2. The structural outliers (`>= 30` methods or `>= 700` lines): their budgets
   are `BUDGETS` in `packages/semitexa-dev/tests/Unit/Structure/StructuralOutlierBudgetTest.php`,
   the god-class epics are in `ai:epic list`. A class that shrinks lowers its
   budget in the same change; otherwise the next regrowth is invisible.
   Static container access the same way: `StaticContainerAccessRatchetTest`.
3. phpstan: the project-wide count above its ceiling
   (`packages/semitexa-dev/resources/phpstan/phpstan-ceiling.json`), one package
   or one rule at a time.
4. Hand-written array shapes that drift (typed records over arrays), static
   facades, constructor injection on container-managed classes.

## Done means

- Behaviour is unchanged: the existing tests pass untouched. A test that has to
  change is a sign the change is not structural; split it out, or move it to `work`.
- The number moved and is locked in: `ai:quality check --json` shows it lower,
  `ai:quality record --json` records it in the same commit. An improvement left
  unrecorded fails `ai:verify` until it is recorded.
- Lowering the phpstan count lowers the ceiling in `phpstan-ceiling.json` in the
  same change.
- One concern per PR: one class split, one metric slice, one rule. A refactor
  that touches twenty files for three reasons comes back in three rounds.

## Not in this phase

New behaviour, and fixes to behaviour found on the way: those are `work`. File
them and keep the PR structural.

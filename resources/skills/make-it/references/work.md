# Profile: make it work

Behaviour that is missing or wrong. The question each change answers: does it do
what it says?

## Where the next change comes from

1. `bin/semitexa ai:work list --status=new --json` under the phase's epic, then
   the other active epics (`ai:epic list --json`), prerequisites first.
2. Defects found while working, filed with `ai:report` when they are the
   framework's.

## Done means

- A test that fails without the change and passes with it. Stash the `src/`
  change, run the test, see it fail, restore. A test that passes on the old code
  protects nothing.
- Assertions pin the exact value, message or list. The reviewer comes back for
  anything an empty or missing value would also satisfy.
- `ai:verify` passes; phpstan no higher than its baseline.
- Docs in `packages/semitexa-docs` say what the code now does, when it changed
  behaviour a reader relies on — in the same change, or in a docs PR opened
  right after it.

## Not in this phase

Restructuring that does not change behaviour (that is `right`), and speed
(that is `fast`). Note them in the backlog and move on.

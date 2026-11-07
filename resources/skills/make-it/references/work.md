# Profile: make it work

Behaviour that is missing or wrong. The question each change answers: does it do
what it says?

## Where the next change comes from

1. `bin/semitexa ai:make-it --with-framework --phase=work --json`: approved tasks
   in Semitexa's order. New work waits for the operator's yes.
2. Defects found while working, filed at once with `ai:work start --recipe=fix_bug`.

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

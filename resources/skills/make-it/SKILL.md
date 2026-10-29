---
name: make-it
description: Run one phase of the Semitexa make it work / make it right / make it fast cycle as a loop of small pull requests, each reviewed and merged before the next change in its repository. The phase is the skill's argument — work, right or fast. Use when the user says "/make-it right", "make it right", "make it fast", "давай make it right", "запусти make it right", "робимо make it fast", "продовжуй make it ..." or asks to work through the quality or performance backlog one PR at a time.
---

# Make it

One skill for three phases, because the loop is the same and only two things
differ: where the next change comes from, and what "done" means. Each phase
keeps those two in a profile:

| Phase | Profile |
|---|---|
| `work`  | [`references/work.md`](references/work.md) — behaviour that is missing or wrong |
| `right` | [`references/right.md`](references/right.md) — structure, without changing behaviour |
| `fast`  | [`references/fast.md`](references/fast.md) — cost, measured before and after |

How far each phase has come is kept in one scorecard for all three —
[`references/scorecard.md`](references/scorecard.md) — and every run reports it.

**The phase is the argument.** In Claude Code it is typed after the name
(`/make-it right`) and arrives as `ARGUMENTS: right`; other runtimes pass it the
same way or in the request ("make it right"). Read the profile it names before
anything else. No phase, or one that is not `work`, `right` or `fast`: name the
three and stop — do not guess one.

Script paths below are relative to **this skill's own directory**. This skill is
a copy: the canonical one is `packages/semitexa-dev/resources/skills/make-it/`,
fanned out with `bin/skills-sync.sh`. Edit there, never here.

## Why small

A batch is what clogs. On 2026-10-27/28 a cut of 37 develop→master PRs took two
days of review: CodeRabbit gives about one review per 30 minutes, every push is a
new head and a new review, and a PR of a hundred files comes back in rounds, each
of which queues again. One change per PR, reviewed and merged while the next is
being written, keeps the reviewer busy instead of the queue full.

## The loop

Run it as a self-paced loop (in Claude Code: `/loop /make-it <phase>`, or a
recurring job every ~10 minutes). A tick that finds work still running only
re-arms. Each tick, in this order:

1. **Look.** `scripts/wip.sh` lists the open Semitexa PRs, the repositories that
   have one, and how many review slots are free. On the first tick of a run,
   read `var/docs/make-it/scorecard.md` (create it as the scorecard reference
   says if it is missing).
2. **Review first.** Run the codereview skill's cycle on the open PRs: answer and
   fix new comments, merge what passes the gate. Merging frees a slot and a
   repository, so it always comes before starting something new.
3. **Pick.** If a slot is free, take the next change from the profile's source
   whose repository has no open PR (`scripts/wip.sh --can-open <repo>` exits 0).
   Prefer a change that lives in one repository.
4. **Make.** `ai:work start` under the phase's epic, then the change itself, held
   to the profile's definition of done. `ai:verify` must pass; phpstan must not
   rise above its baseline.
5. **Ship.** The review-prep skill for that one repository: commit, checks, a PR
   from `develop` to `master` whose title says the one change it carries.
6. **Score.** After a merge, or any finding — in this phase or another — move
   the criterion it belongs to in `var/docs/make-it/scorecard.md`, with the
   evidence, and a line in its history. A bug found while making it fast lowers
   `work`; nothing waits for the end of the run to be counted.
7. **Release, often.** After every few merges (default 5) or once a day, run the
   release-readiness skill — small releases, not one at the end. See the release
   rule below for when a release may start.

Report each tick to the operator in their language, briefly, starting with the
three scores on one line (`work 86% · right 60% · fast 47%`, with what moved since
the last report): then merged, opened, waiting, and why.

## Rules

- **One open PR per repository.** Work happens on `develop`, and a PR from
  `develop` carries everything on it. A second change committed to a repository
  whose PR is in review joins that PR, starts its review over and mixes two
  changes in one verdict. The next change in that repository waits for the merge;
  work on another repository meanwhile.
- **A review-slot limit.** At most `MAKE_IT_WIP` PRs in review at once (default
  3). More only fills the reviewer's queue ahead of time.
- **Only review answers go to an open PR.** A fix that a comment asks for is
  pushed to that PR. Anything else is a new change, and waits its turn.
- **A change across repositories is the expensive kind.** The provider ships
  first. The consumer declares `"extra": {"semitexa": {"floors": {"semitexa/<provider>": "next"}}}`
  and its PR opens only once the provider's PR is merged. The reviewer compares
  the code with the released provider. A comment saying "this method does not
  exist" is checked against the provider's `develop` before anyone acts on it.
- **The merge gate** is the codereview skill's: CodeRabbit finished on the
  current head, no unresolved thread, no unanswered "Outside diff range" finding
  in a review body, and a clean merge state. "Review rate limited" is not finished.
- **Release rule.** Finalize dates `next` floors with a commit on `develop`, and
  that commit has to fast-forward `master`. A repository with a `next` floor
  therefore needs no unmerged commit at release time. Start a release only when
  no such repository has an open PR or unmerged work.
- **Stop** when the profile's source has nothing left, or when the operator says
  so. Give a final report: the scorecard for all three phases (each criterion,
  its points and evidence), what moved during the run and why, what merged,
  what was rejected and why, what was found beyond the plan, what is knowingly
  left open, and what would raise each number most.

## Resources

- `references/scorecard.md` — the rubric, its rules and the report format
- `scripts/wip.sh` — open PRs, busy repositories, free slots; `--can-open <repo>`
- The codereview skill — review cycle and merge gate
- The review-prep skill — commit, checks and PR for one repository
- The release-readiness skill — the release itself

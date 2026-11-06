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

How far each phase has come is what `bin/semitexa ai:state --with-framework`
says, counted from the backlog; every run reports that line.

**The phase is an optional argument.** In Claude Code it is typed after the name
(`/make-it right`) and arrives as `ARGUMENTS: right`; other runtimes pass it the
same way or in the request ("make it right"). With one, read the profile it names
before anything else. With none, take what comes next, whatever its phase, and
read the profile of the task named. Anything but `work`, `right` or `fast`: name
the three and stop.

**What comes next is Semitexa's answer, the same one every agent gets.** This
skill adds what is particular to the framework: pull requests and their review.

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
   read `bin/semitexa ai:state --with-framework`.
2. **Review first.** Run the codereview skill's cycle on the open PRs: answer and
   fix new comments, merge what passes the gate. Close each merged task at once
   (`ai:work update --status=done`, with a fresh `ai:verify --trace=<task>
   --files=<its files>` if the last one is older than the merge): an open task is
   what Pick names next. Merging frees a slot and a repository, so it always comes
   before starting something new.
3. **Pick.** If a slot is free, `bin/semitexa ai:make-it --with-framework
   [--phase=<phase>] --json`, and read `state`:
   - `work`: it names the task. Its `context_refs` give the repositories. If one
     has an open PR (`scripts/wip.sh --can-open <repo>` exits 1), or the task names
     none and you cannot tell from its text, ask again with `--except=<task>`,
     keeping the earlier ones. Hold that list for this tick only.
   - `waiting`: everything open is blocked or set aside. End the tick.
   - `done` under `right` or `fast`: the profile's source is where its tickets come
     from. Look there, file what you find (`ai:work start --phase=<phase>`, which
     counts at once), record it (`ai:audit record`), and ask again. Under `work`,
     new work waits for the operator: end the tick and say so.
   - anything else: say what `say` says and end the tick.
4. **Make.** Follow the task through the steps `ai:make-it` gives, held to the
   profile's definition of done; phpstan must not rise above its baseline. Those
   steps end at a local commit, closing the task and asking again: here stop at
   the commit, the task stays open until its PR merges, and Ship comes next.
5. **Ship.** The review-prep skill for that one repository: commit with a last
   line `Task: <task>`, checks, a PR from `develop` to `master` whose title says
   the one change it carries.
6. **Count.** A finding — in this phase or another — is a ticket filed at once:
   `--recipe=fix_bug`, `--phase=right` or `--phase=fast` count from then on, so a
   bug found while making it fast lowers `work` by itself. New work under
   `--phase=work` waits for the operator's yes.
7. **Release, often.** After every few merges (default 5) or once a day, run the
   release-readiness skill — small releases, not one at the end. See the release
   rule below for when a release may start.

Report each tick to the operator in their language, briefly, starting with the
`ai:state --with-framework` line (`work 80% (8/10, 1 open, 1 unproven) · right not
measured · …`, with what moved since the last report): then merged, opened, waiting, and why.

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
  current head, no unresolved thread, no unanswered "Outside diff range" or "Follow-up" finding
  in a review body, and a clean merge state. "Review rate limited" is not finished.
- **Release rule.** Finalize dates `next` floors with a commit on `develop`, and
  that commit has to fast-forward `master`. A repository with a `next` floor
  therefore needs no unmerged commit at release time. Start a release only when
  no such repository has an open PR or unmerged work.
- **Stop** when the profile's source has nothing left, or when the operator says
  so. Give a final report: the `ai:state --with-framework` line, what moved
  during the run and why, what merged, what was rejected and why, what was found
  beyond the plan, and what is knowingly left open.

## Resources

- `scripts/wip.sh` — open PRs, busy repositories, free slots; `--can-open <repo>`
- The codereview skill — review cycle and merge gate
- The review-prep skill — commit, checks and PR for one repository
- The release-readiness skill — the release itself

---
name: codereview
description: Run the Semitexa pull request review cycle when the user asks for codereview, /codereview, code review, review comments, reviewer comments, open PR comments, or equivalent Ukrainian requests such as "пройди код ревю", "опрацюй коменти від ревюверів", "опрацюй всі review comments", or "пройди всі відкриті PR". By default, treat these requests as processing review comments across all open Semitexa pull requests unless the user narrows the scope to a specific repository or PR.
---

# Codereview

Use this skill when the user asks for:
- `codereview` or `/codereview`
- code review, PR review, review comments, reviewer comments, open PR comments
- Ukrainian equivalents such as `пройди код ревю`, `опрацюй коменти від ревюверів`, `опрацюй всі review comments`, `пройди всі відкриті PR`

Default assumption:
- if the user asks broadly for code review or reviewer comments and does not name a specific repository or PR, process review comments across all open Semitexa PRs
- only narrow the scope when the user explicitly names a repo or PR number

## Resources

- Primary workflow reference: [`references/CODE_REVIEW.md`](references/CODE_REVIEW.md)
- Bundled scripts live in [`scripts/`](scripts); `pr-process.sh` is the fix queue, `pr-merge-sweep.sh` the merge verdict for every open PR
- Script paths in this document are relative to **this skill's own directory** — the base
  directory the runtime reports when it loads the skill. Do not rewrite them to a
  runtime-specific path such as `.claude/skills/...` or `~/.codex/skills/...`: the same body
  is shipped to every runtime from one canonical source, and a hardcoded prefix is correct
  in at most one of them.
- This whole skill is a **copy**. The canonical version lives in
  `packages/semitexa-dev/resources/skills/codereview/` — inside a git repository,
  unlike this directory. Edit there, never here, then fan out:
```bash
bin/skills-sync.sh          # copy canonical -> bin/ and every agent directory
bin/skills-sync.sh --check  # report drift, exit 1 (run before trusting these scripts)
```
  Editing a copy directly is silently lost on the next sync. The copies exist
  because each agent runtime loads skills from its own directory, not because the
  versions are meant to differ.

## Project Root

Run this skill from the Semitexa project root when possible.

The bundled `scripts/pr-review.sh` can also work if `SEMITEXA_REVIEW_ROOT` is set to the project root.

## Workflow

1. Start with the bundled preflight:
```bash
scripts/pr-process.sh --fail-on-warnings
```

2. If the command reports blockers or warnings, stop before editing code or replying.
Report the blocking repos and why they are blocked.
`reviews-pending: <reviewers>` is the exception, and it does NOT stop the queue: it
makes `--fail-on-warnings` exit non-zero, but it is not a fault. It means an open
review request, or CodeRabbit's commit status still pending on the head. Carry on
with step 3 for every PR; process the comments that PR already has too. What it
forbids is reporting that PR as done: findings may still arrive, so re-run once
the reviewer finishes. It clears by itself: a request drops when the reviewer
submits, the status turns success.

3. If the queue is clean, get the actionable processing list:
```bash
scripts/pr-process.sh --ready-only
```

4. For each PR in the ready queue:
- Work only inside the Semitexa repository shown by the queue.
- Process every unresolved actionable comment, regardless of `kind`. The
  queue covers three sources:
  - `kind=line` — line-anchored review comments
  - `kind=review` — review summary bodies that carry inline severity markers
    (P1/P2/P3 badges, "Refactor suggestion", etc.)
  - `kind=issue` — PR conversation comments posted by non-author reviewers/bots
- Apply the smallest correct fix.
- Run the narrowest useful validation for the touched code.
- Run `composer phpstan` from the Semitexa project root before any push.
- Run:
```bash
scripts/commit.sh /absolute/path/to/repo
git -C /absolute/path/to/repo commit -m "<review fix message>"
git -C /absolute/path/to/repo push origin HEAD
```
  `commit.sh` sets the author identity and stages (`git add -A`); you supply the
  message. It exits non-zero rather than letting the two commands after it run
  against an empty index — a commit that fails there is followed by a push that
  says "Everything up-to-date" and exits 0, which reads as success.
- Confirm the commit actually landed before replying: `git -C <repo> log --oneline -1`.
- Only after push, reply on each processed thread. `pr-process.sh` prints the
  exact `pr-reply.sh` invocation per item, including the `--kind=<kind>` flag
  so the call hits the correct GitHub endpoint:
```bash
scripts/pr-reply.sh <repo-slug> <pr-number> <id> "<reply body>" --kind=line
scripts/pr-reply.sh <repo-slug> <pr-number> <id> "<reply body>" --kind=review
scripts/pr-reply.sh <repo-slug> <pr-number> <id> "<reply body>" --kind=issue
```
- Do not send review replies in a tight burst. Add a small random pause between replies and slow down further if GitHub starts returning abuse or secondary rate-limit responses.

## Skipped reviews wake themselves

CodeRabbit skips, and never queues, a review that hits its rate limit: the head gets
a "Review rate limited" status. `scripts/coderabbit-retry.sh` posts `@coderabbitai review`
on ONE such PR per run (least recently triggered first; a head is re-triggered at most
once per run). On the operator's workstation a systemd user timer runs it every 30 minutes:
`systemctl --user list-timers semitexa-coderabbit-retry.timer`, log in
`~/.local/state/semitexa/coderabbit-retry.log`. So a "Review rate limited" head is
not a reason to trigger by hand. Check the log first; `--dry-run` shows what it would do.

## The full cycle: fix → re-review → merge, until nothing is open

A broad review request means the WHOLE cycle, run autonomously until every open
PR is merged — not one pass over today's comments. The operator should not be
asked anything a rule below already answers; they get short progress reports in
their own language (merged / fixed / waiting, with SHAs), not questions.

1. **Fix pass.** Run the queue. Every repo you will touch must be on
   `develop` (the queue flags any other branch) and fast-forwarded first
   (`git fetch && git merge --ff-only origin/develop`) — local checkouts are
   routinely behind the PR head. With many repos, fan out: one subagent per
   batch of 3–5 repos, each told exactly which repos are its own, the rules
   below, and to report per comment `fixed <sha> / rejected <why>`. Serialize
   the heavy shared commands across agents with one lock in the project's
   own `var/` (same path for every runtime, and it always exists):
   `flock var/semitexa-review.lock composer phpstan`, run from the project
   root (same for phpunit in the app container).
2. **Merge sweep.** `scripts/pr-merge-sweep.sh` lists every open PR with a
   verdict; `--merge` merges the READY ones: 0 unresolved comments, the
   primary reviewer passed the current head, mergeState CLEAN. **Greptile is
   the primary reviewer** (since 2026-09-27: minutes per review, no rate
   limit, and it found the real bugs CodeRabbit missed that day); its check
   run must complete successfully. CodeRabbit is secondary: a review it is
   running is waited for, but rate limiting no longer blocks a merge. A repo
   without Greptile still needs CodeRabbit "Review completed". Findings from
   both are fixed and answered the same way. When the two contradict each
   other on the same line round after round, decide once, say why in the
   thread, and stop moving the line. Use it, not
   `pr-process.sh`, to decide merges: the fix queue drops a PR with no
   comments, so a PR waiting on a rate-limited review is invisible there.
3. **Wait, don't poke.** A fix push starts a review by itself (Greptile
   reviews every push within minutes). CodeRabbit's "Review rate limited" heads
   are woken one per 30 minutes by the retry timer (below); a PR that waited
   through two timer runs may get one `@coderabbitai review` by hand (a skipped
   trigger costs nothing). `@greptileai review` re-requests Greptile. Set a
   recurring wake-up for yourself a few minutes after each timer run — read
   its phase from `systemctl --user list-timers semitexa-coderabbit-retry.timer`
   (it drifts, e.g. after a reboot); in Claude Code a `CronCreate` job — and on
   each tick: sweep, merge what is READY, fix what came back, report.
4. **Stop** when the sweep says `No open PRs.`: cancel the wake-up and give a
   final report — what merged, what was rejected and why, what was found beyond
   the comments, and what is knowingly left open.

### How to treat a comment

- **Verify before acting.** Reproduce the claim against the code (a probe
  script, a failing test). A comment is right, right for a different reason, or
  wrong; only the first two change code.
- **A behavior fix carries a test that fails without it.** Prove it: stash the
  `src/` change, run the test, see it fail, restore. A test that passes on the
  old code protects nothing.
- **Assert positively.** Bots come back for every assertion an empty or missing
  value would satisfy (`assertIsString`, `assertContains`, a negative
  `assertStringNotContainsString`). Pin the exact value, list or message the
  first time and the thread ends in one round instead of three.
- **Fix at the source.** When the comment exposes a defect that lives in
  another package (a docs PR describing a pattern core cannot load), fix it in
  that package's develop — it joins that package's open PR — reply on both
  threads, and note the merge order (source first).
- **Not every fix is code.** A repository setting (e.g. private vulnerability
  reporting that SECURITY.md promises) is changed on GitHub; reply saying so.
- **Rejecting** is a technical reply with evidence; before merging, read the
  bot's follow-up on that thread — it either withdraws or names what remains.
- **Environment, not code:** a package test failing with "class not found" for
  its own `Tests\Fixtures` in the root phpunit is a stale
  `vendor/composer/autoload_psr4.php` — `docker compose exec -T app composer dump-autoload`.
- **phpstan is judged against its baseline**, not zero: the project-wide run
  was red before the review (208 on 2026-09-26). The rule is "no new errors"
  — compare the count, and run phpstan on the changed files alone to confirm
  none are yours.
- **Look past the comment.** A fix that reveals an adjacent defect in the same
  area (a worker that lost its lease still writing the final state) is fixed in
  the same PR when it is in the PR's own scope; otherwise report it.

## Rules

- Follow [`references/CODE_REVIEW.md`](references/CODE_REVIEW.md) when more detail is needed.
- Never reply before the fix is pushed.
- Always run `composer phpstan` from the Semitexa project root after the fix and before pushing.
- Do not touch repositories outside the specific Semitexa repository selected by the review queue — except to fix a defect at its source, as described above.
- Merge with `gh pr merge --merge` (merge commit) and never delete `develop`; `pr-merge-sweep.sh --merge` does exactly that and refuses a head that moved since the check.
- In a checkout other sessions share, run `git status --short` as its own step before `commit.sh` (which stages everything) and commit by pathspec if anything there is not yours.
- If a review comment is incorrect, reply with a concise technical explanation instead of changing code.
- Keep replies short and factual.
- Throttle review replies. Prefer one reply at a time with a random delay between posts instead of batch-spamming GitHub.

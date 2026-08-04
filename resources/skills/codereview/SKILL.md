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
- Bundled scripts live in [`scripts/`](scripts)
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

## Rules

- Follow [`references/CODE_REVIEW.md`](references/CODE_REVIEW.md) when more detail is needed.
- Never reply before the fix is pushed.
- Always run `composer phpstan` from the Semitexa project root after the fix and before pushing.
- Do not touch repositories outside the specific Semitexa repository selected by the review queue.
- If a review comment is incorrect, reply with a concise technical explanation instead of changing code.
- Keep replies short and factual.
- Throttle review replies. Prefer one reply at a time with a random delay between posts instead of batch-spamming GitHub.

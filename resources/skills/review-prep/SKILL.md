---
name: review-prep
description: Prepare Semitexa code for code review when the user asks phrases such as "підготуй все до код ревю", "починаємо код ревю", "думаю час починати код ревю", "prepare everything for code review", "start code review", "prepare for code review", or "ready for review". By default, determine the target repo from the current git repo or dirty Semitexa package repos, stage reviewable changes, generate a meaningful commit message, commit the branch, run available checks and tests, perform a local /review pass before any push, fix and commit any review findings, and only then create or update review-ready pull requests with structured descriptions, checklists, and verification notes.
---

# Review Prep

Use this skill when the user wants to prepare work for code review and open PRs.

Default assumptions:
- if the current working directory is inside a git repo, prepare that repo
- otherwise, inside `semitexa.dev`, prepare dirty repos under `packages/*`
- protected branches such as `master` and `main` must never be used as PR head branches; `develop` is allowed for Fast Development mode
- use `master` as the default PR base when the head branch is `develop`; otherwise prefer `develop`, then `master`, then `main`
- run `git status --short` first, then stage reviewable changes with `git add -A`
- generate a factual commit message from the actual staged diff unless the user asked for a specific message
- run the broadest safe local checks that are already defined by the repo after the commit and before creating or updating the PR
- run a local `/review` self-review pass after checks and before any push or PR creation
- create or update PRs through `gh` with a structured body that includes summary, changes, checks, review focus, and verification notes

## Resources

- PR body guidance: [`references/PR_BODY_TEMPLATE.md`](references/PR_BODY_TEMPLATE.md)
- Bundled helpers live in [`scripts/`](scripts)

## Workflow

- Script paths in this document are relative to **this skill's own directory** — the base
  directory the runtime reports when it loads the skill. Do not rewrite them to a
  runtime-specific path such as `.claude/skills/...` or `~/.codex/skills/...`: the same body
  is shipped to every runtime from one canonical source, and a hardcoded prefix is correct
  in at most one of them.


1. Discover target repos:
```bash
scripts/discover-review-repos.sh
```

2. For each target repo:
- confirm the current branch is not `master` or `main`; `develop` is allowed
- run:
```bash
scripts/stage-review-commit.sh /absolute/path/to/repo --status-file /tmp/review-status.txt
```
- this must print the current `git status --short`
- this must stage reviewable tracked and untracked files with `git add -A`
- this must commit them with a generated message when there is anything to commit

3. After the commit, run:
```bash
scripts/run-review-checks.sh /absolute/path/to/repo --summary-file /tmp/review-checks.txt
```
- stop immediately if any check fails

4. Run a local `/review` pass on the exact commits that would be pushed:
- review with a code-review mindset focused on bugs, regressions, risky behavior changes, missing validation, and missing tests
- if the review finds actionable issues, fix them in the repo
- rerun the narrowest useful validation for the touched area after the fix
- commit the review fixes before continuing
- only continue when the review gate is clean

5. Create or update the PR:
```bash
scripts/create-review-pr.sh /absolute/path/to/repo --checks-file /tmp/review-checks.txt
```
- this must push the current branch if needed
- this must create a PR when none exists or update the existing open PR body and title when one already exists
- use the latest commit subject as the default PR title unless the user asked for a specific title
- if the self-review step added a follow-up fix commit, make sure its subject is PR-ready before creating or updating the PR

## Rules

- Never create PRs from `master` or `main`. `develop` is allowed in Fast Development mode.
- Never skip failing checks unless the user explicitly tells you to.
- Never push or open a PR before the local `/review` pass is complete.
- Do not auto-commit ignored files.
- If `git add -A` still leaves nothing staged, stop and report that there is nothing reviewable to commit.
- If `/review` finds issues, fix them, rerun focused validation, and commit those fixes before push or PR creation.
- Prefer existing repo scripts over improvising custom validation commands.
- If no meaningful checks are defined by the repo, say so explicitly in the final note and still include that fact in the PR body.
- Keep commit messages and PR descriptions factual and compact.
- The `What to Review` and `What to Verify` sections should be derived from the actual changed files and available checks, not generic filler.

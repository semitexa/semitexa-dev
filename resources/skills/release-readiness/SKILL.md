---
name: release-readiness
description: Run the Semitexa release-readiness workflow when the user says they are ready to release or wants a pre-release verification pass, including phrases such as "ок випускаємо нову версію", "думаю ми готові випустити нову версію", "готові до релізу", "запусти передрелізну перевірку", "release readiness", or "prepare release". By default, operate on /home/taras/Documents/Projects/semitexa.rls, sync master branches in that release set, perform a full Semitexa release clone restart, run automated checks including Playwright browser smoke tests, and then tag any untagged master heads with a UTC release version. Releases are tag-driven from master; merging develop→master is the operator's responsibility outside this skill. The workflow must always release packages/semitexa-ultimate with exact internal Semitexa dependency versions instead of wildcard constraints.
---

# Release Readiness

Use this skill when the user asks to prepare, verify, or validate a Semitexa release.

Default assumptions:
- operate on [`/home/taras/Documents/Projects/semitexa.rls`](/home/taras/Documents/Projects/semitexa.rls)
- sync `master` inside the release clone repo set under `semitexa.rls` during preflight (via `release-sync-masters.sh`); `develop` is NOT touched by this skill
- this skill is tag-driven from `master`; merging `develop`→`master` is the operator's responsibility outside this skill (e.g. via `gh pr create`/`merge` or `/review-prep` workflow); if every master HEAD is already tagged, finalize is a no-op
- after finalize, fast-forward matching clean authoring checkouts under `semitexa.dev/packages/` when release bookkeeping mutates them
- after refreshing release branches, explicitly apply the updated code with a full `bin/semitexa server:stop` and `bin/semitexa server:start` cycle in the release clone before checks
- refresh the release clone's application code (`src/`, `public/`) from the dev root before the
  containers come up, via `release-sync-root.sh --code-only`; never the full sync, which would
  overwrite the clone's own `.env` and `composer.json`
- stop on the first failed automated gate
- a green automated run includes route smoke checks, SSR helper checks, Playwright browser smoke tests, logs, phpstan, and phpunit
- before starting `semitexa.rls`, rewrite its local tenant domains to an isolated release namespace under `*.rls.semitexa.test`; it must never register `semitexa.test`, `framework.semitexa.test`, `os.semitexa.test`, or `platform.semitexa.test` in the shared router
- browser smoke scope is Semitexa Demo only on `demo.rls.semitexa.test`
- Semitexa Site, Semitexa OS, and Semitexa Platform are explicitly out of release smoke scope
- package tags must point to `master` commits, never to `develop` commits
- every release must also bump `packages/semitexa-ultimate` and rewrite its internal `semitexa/*` requirements to exact released versions
- after `semitexa-ultimate` pins are refreshed in the release clone, sync the matching clean authoring checkout in `semitexa.dev/packages/semitexa-ultimate`
- every run must generate a short markdown release report in `/home/taras/Documents/Projects/semitexa.dev/var/docs/release`
- before any tagging step, explicitly ask whether the release channel is `stable` or `beta`
- new package versions use UTC date-based tags in the format `YYYY.MM.DD.HHMM`, with `-beta` appended for beta releases
- the release workflow also assigns a monthly codename, stored separately from the Composer package version
- manual browser QA is now fallback-only and should be used only when automated browser smoke fails or when the user explicitly asks for extra spot checks

## Resources

- Bundled scripts live in [`scripts/`](scripts)
- Script paths in this document are relative to **this skill's own directory** — the base
  directory the runtime reports when it loads the skill. Do not rewrite them to a
  runtime-specific path such as `.claude/skills/...` or `~/.codex/skills/...`: the same body
  is shipped to every runtime from one canonical source, and a hardcoded prefix is correct
  in at most one of them.
- Browser smoke is **skill-owned**: [`references/release-smoke.spec.ts`](references/release-smoke.spec.ts) + [`references/release-smoke.playwright.config.ts`](references/release-smoke.playwright.config.ts). `run_playwright_smoke` (in `release-auto-checks.sh`) deploys both into the release clone and runs ONLY that spec via the dedicated config — independent of the clone's own `playwright.config` testMatch, so the clone's dev-module E2E never run and never conflict with the Demo at `/`. (The clone's Playground hub used to sit at `/` and collide with the Demo; preflight's `sync-release-code` stage now keeps `src/` in step with the dev root, where the hub has moved to `/playground`. The dedicated config stays regardless — release smoke should not depend on whatever the clone's own testMatch happens to pick up.) (The old root `tests/` release-smoke suite was removed; this restores a self-contained, reproducible browser smoke.)
- **Clone prerequisite:** the release clone MUST install `semitexa/demo` (path-repo `packages/semitexa-demo` + a `require`) so `demo.rls.semitexa.test/` serves the Semitexa Demo home (with the "Get Started" CTA) the smoke validates. A bare ultimate scaffold serves only the framework Playground and fails the route checks.
- Manual fallback checklist lives in [`references/RELEASE_CHECKLIST.md`](references/RELEASE_CHECKLIST.md)
- Browser smoke must track the current Semitexa Demo structure under `demo.rls.semitexa.test`: home `/`, section routes `/demo/<section>`, and feature routes `/demo/<section>/<slug>`. Do not expand release smoke back to Semitexa Site, OS, or Platform, and do not resurrect legacy checks for removed routes such as `/demo/components`, `/demo/isomorphic`, `/demo/orm`, or other pre-rewrite demo pages.
- The route-check doctype assertion in `release-auto-checks.sh` is case-insensitive (`grep -Fiq '<!doctype html>'`) — the app emits the uppercase W3C `<!DOCTYPE html>`.

## Workflow

1. Run the full preflight:
```bash
scripts/release-preflight.sh
```

2. If preflight fails, stop and report the first failing gate with the relevant command output.

3. If preflight passes, immediately run:
```bash
scripts/release-finalize.sh
```
- before running finalize, explicitly determine the release channel with the user: `stable` or `beta`
- this fetches `origin/master` per package, fast-forwards local `master`, tags any untagged `master` HEAD with the UTC release version, refreshes `packages/semitexa-ultimate` exact internal pins (commit on `develop`, fast-forwarded to `master`, tagged), and triggers Packagist updates
- finalize does NOT merge `develop`→`master`; if every `master` HEAD is already tagged, finalize is a no-op (zero new tags). Tell the user and offer to open develop→master PRs (e.g. via `/review-prep`) before retrying
- it must always release `packages/semitexa-ultimate` and replace wildcard internal package constraints with exact released versions
- it must update the same markdown release report with the final release result
- only after that return the final readiness verdict

4. Use the bundled post-merge step only as a recovery path when PRs were already merged outside the normal flow:
- run:
```bash
scripts/release-post-merge.sh
```
- this verifies merged `master` composer versions and pushes tags on the merged `master` commits
- it must update the same markdown release report with the final release result

5. If the user explicitly asks for extra manual checking, use [`references/RELEASE_CHECKLIST.md`](references/RELEASE_CHECKLIST.md) as a fallback checklist after the Playwright run.

## Rules

- Work only against the fixed release root unless the user explicitly changes it.
- This skill never touches `develop`. The `release-sync-develop.sh` script in the scripts dir is a leftover and is NOT part of the active flow. `prepare-release-prs.php`, `merge-release-prs.php`, and `tag-merged-release-prs.php` are deprecated tombstones — do not invoke them.
- Do not stop unrelated Docker projects; only Semitexa-related containers/stacks.
- For repo sync, require a clean worktree before changing any local `master`.
- Preflight must force the release clone onto isolated `*.rls.semitexa.test` local domains before `bin/semitexa server:start` so shared `semitexa.dev` domains cannot be overwritten.
- Use the bundled scripts instead of improvising the flow.
- If automated checks fail, do not continue to release finalize.
- Tagging must use the skill-local `bump-packages.php` via `release-finalize.sh` (or `release-post-merge.sh` as recovery), not the project copies in `semitexa.rls/bin/`.
- Every release-readiness run must leave behind a markdown report in `/home/taras/Documents/Projects/semitexa.dev/var/docs/release`.
- The release assistant must not guess stability intent; it must ask for `stable` vs `beta` before tagging.

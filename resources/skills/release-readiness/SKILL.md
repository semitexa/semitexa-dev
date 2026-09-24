---
name: release-readiness
description: Run the Semitexa release-readiness workflow when the user says they are ready to release or wants a pre-release verification pass, including phrases such as "ок випускаємо нову версію", "думаю ми готові випустити нову версію", "готові до релізу", "запусти передрелізну перевірку", "release readiness", or "prepare release". By default, operate on /home/taras/Documents/Projects/semitexa.rls, sync master branches in that release set, perform a full Semitexa release clone restart, run automated checks including Playwright browser smoke tests, and then tag any untagged master heads with a UTC release version. Releases are tag-driven from master; merging develop→master is the operator's responsibility outside this skill. The workflow must always release packages/semitexa-ultimate with exact internal Semitexa dependency versions instead of wildcard constraints.
---

# Release Readiness

Use this skill when the user asks to prepare, verify, or validate a Semitexa release.

Default assumptions:
- operate on [`/home/taras/Documents/Projects/semitexa.rls`](/home/taras/Documents/Projects/semitexa.rls)
- sync `master` inside the release clone repo set under `semitexa.rls` during preflight (via `release-sync-masters.sh`). Finalize touches `develop` only to commit dated internal floors and to refresh `semitexa-ultimate` pins — each a fast-forward of develop to master plus one release commit, pushed atomically with master. It never merges `develop`→`master`
- this skill is tag-driven from `master`; merging `develop`→`master` is the operator's responsibility outside this skill (e.g. via `gh pr create`/`merge` or `/review-prep` workflow); if every master HEAD is already tagged, finalize is a no-op
- after finalize, fast-forward matching clean authoring checkouts under `semitexa.dev/packages/` when release bookkeeping mutates them
- after refreshing release branches, explicitly apply the updated code with a full `bin/semitexa server:stop` and `bin/semitexa server:start` cycle in the release clone before checks
- refresh the release clone's application code (`src/`, `public/`) from the dev root before the
  containers come up, via `release-sync-root.sh --code-only`; never the full sync, which would
  overwrite the clone's own `.env` and `composer.json`
- stop on the first failed automated gate
- a green automated run includes route smoke checks, SSR helper checks, Playwright browser smoke tests, logs, `system:doctor`, `composer audit`, phpstan neutrality, and phpunit
- the quality ledger gate runs `ai:quality check --all` in the clone (server up): every `#[AsQualityMetric]`, including the per-route request cost probed through real traced requests. At release it is ONE-way — a regression, an unrecorded metric, no server or an unreadable report fails; lower-than-baseline is a warning, because the clone's packages and data differ from the workspace where the baseline is recorded
- the phpstan gate checks NEUTRALITY, not cleanliness: the project sits above its own baseline, so the bar is that a release does not raise the count. `packages/semitexa-dev/resources/phpstan/phpstan-ceiling.json` records it (no environment override). It is a ratchet: the gate also fails when the count drops below the ceiling, so lower `ceiling` to the new count in the same commit; raise it only deliberately, with a `deliberate` entry saying what grew
- before starting `semitexa.rls`, rewrite its local tenant domains to an isolated release namespace under `*.rls.semitexa.test`; it must never register `semitexa.test`, `framework.semitexa.test`, `os.semitexa.test`, or `platform.semitexa.test` in the shared router
- browser smoke scope is Semitexa Demo only on `demo.rls.semitexa.test`
- Semitexa Site, Semitexa OS, and Semitexa Platform are explicitly out of release smoke scope
- package tags must point to `master` commits, never to `develop` commits
- every release must also bump `packages/semitexa-ultimate` and rewrite its internal `semitexa/*` requirements to exact released versions
- after `semitexa-ultimate` pins are refreshed in the release clone, sync the matching clean authoring checkout in `semitexa.dev/packages/semitexa-ultimate`
- every run must generate a short markdown release report in `/home/taras/Documents/Projects/semitexa.dev/var/docs/release`
- **the release channel defaults to `stable`.** Just run preflight; do not stop to ask, and do not
  treat the channel as an open question when nothing suggests otherwise. Pass
  `RELEASE_CHANNEL=beta` only when a beta is actually wanted
- the channel is resolved at **preflight**, not at tagging: it decides the `-beta` suffix on
  `RELEASE_VERSION`, which the internal-constraints floor gate reads and the pending report prints.
  `init_release_session()` records it in the session file and **finalize reuses it without asking again**
- **an internal floor is DECLARED by the author and DATED by the release.** A package that starts
  calling a new API of a sibling writes
  `"extra": { "semitexa": { "floors": { "semitexa/<provider>": "next" } } }` and leaves `require`
  alone. **Finalize dates it — nobody runs a step for it.** Before the first tag,
  `bump-packages.php` writes `>=$RELEASE_VERSION || dev-master` into `require`, records the same
  version back in `extra` (so a later release finds nothing to do), commits it on **develop**,
  fast-forwards master to that commit, pushes both in one atomic push, and reads the manifest back from the tree it
  is about to tag — a tree still saying `next` is refused. Every refusal is found before the first
  commit and every commit is made before the first tag, so a failed run leaves nothing, or
  untagged floor commits a re-run simply tags.
  It refuses: a declaration naming a dependency this run is not tagging (a run filtered to one
  package included), and a package whose develop carries commits master does not (no
  fast-forward is possible; sync the release baseline first).
  Preflight's `floors-are-dated` stage passes on a pending declaration and lists what finalize
  will date; it fails only on one finalize cannot date (a provider outside the release set, or no
  `RELEASE_VERSION`). `release-resolve-floors.php --confirm --commit` remains for a cut tagged by hand; it
  commits on master only, so develop has to be fast-forwarded to master afterwards.
  ⚠️ Before 2026-09-22 the resolver was the only path, and it left develop behind: ssr and
  webhooks were tagged with dated floors while develop still read `next`. Nobody writes a date by hand any more: a hand-written one is a guess about a cut
  that has not happened, and it goes stale the first time a release slips (measured 2026-09-16 on
  `os` → `prompt`, which died in preflight a day later)
- **the `new-public-api` stage is a question, not a gate.** The constraint check compares CLASS
  declarations, so a new public METHOD on a class that shipped months ago is invisible to it — ssr
  called `Request::getServedPath()` on 2026-09-18 while its floor named a core with no such method.
  The stage prints what each tagged package gained since its last tag and which dependents touch
  those classes, so the floor question is asked by the tool rather than remembered by a person
- the default is deliberate but **not silent**: a defaulted run prints a `[WARN]` naming the channel
  and how to pick beta, and every report says which of the three ways the channel was chosen —
  `(defaulted …)`, `(chosen at the prompt)` or `(explicitly passed)` — so "did anyone actually choose
  this?" stays answerable after the fact
- the choice is **sticky**: the tool cannot promote `beta`→`stable` on the same `master` commit
  (`bump-packages.php` treats any release tag on HEAD as released), so a later stable re-run is a
  no-op and the tags have to be placed by hand. That is the reason to raise beta *before* preflight
  if it is wanted at all — not a reason to ask on every release
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
scripts/release-preflight.sh                  # channel defaults to stable
RELEASE_CHANNEL=beta scripts/release-preflight.sh   # only when a beta is wanted
```
- say in the final report which channel the run used, and say so explicitly when it was the default

2. If preflight fails, stop and report the first failing gate with the relevant command output.

3. If preflight passes, immediately run:
```bash
scripts/release-finalize.sh
```
- the channel is NOT asked again here: finalize calls `load_release_session()` and inherits the
  `RELEASE_CHANNEL` and `RELEASE_VERSION` preflight recorded. Passing a different one now does not
  re-cut the version
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
- This skill touches `develop` only in finalize, for dated floors and ultimate pins (see above); it never merges `develop`→`master`. The `release-sync-develop.sh` script in the scripts dir is a leftover and is NOT part of the active flow. `prepare-release-prs.php`, `merge-release-prs.php`, and `tag-merged-release-prs.php` are deprecated tombstones — do not invoke them.
- Do not stop unrelated Docker projects; only Semitexa-related containers/stacks.
- For repo sync, require a clean worktree before changing any local `master`.
- Preflight must force the release clone onto isolated `*.rls.semitexa.test` local domains before `bin/semitexa server:start` so shared `semitexa.dev` domains cannot be overwritten.
- Use the bundled scripts instead of improvising the flow.
- If automated checks fail, do not continue to release finalize.
- Tagging must use the skill-local `bump-packages.php` via `release-finalize.sh` (or `release-post-merge.sh` as recovery), not the project copies in `semitexa.rls/bin/`.
- Every release-readiness run must leave behind a markdown report in `/home/taras/Documents/Projects/semitexa.dev/var/docs/release`.
- The release assistant defaults to `stable` and does not stop to ask. It must still **report** the
  channel it used and flag when it was defaulted, and must pass `RELEASE_CHANNEL=beta` when the user
  asks for a beta. Changed 2026-09-17: this used to be "never guess, always ask", which refused to
  start on the first command of every agent-driven release while the answer was `stable` every time.

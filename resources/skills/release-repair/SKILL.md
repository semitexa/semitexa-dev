---
name: release-repair
description: Diagnose and fix a failed Semitexa release after `release-preflight` stops on a blocking gate. Use when the user asks to fix a failed release, investigate release smoke failures, analyze the latest release report, inspect Playwright screenshots or videos, or prepare the codebase for another release attempt. Always stop `semitexarls-*` containers first, reproduce and fix the problem in `semitexa.dev`, and treat the working `develop` branches there as the only authoring branches. Never switch branches, commit, or push from this skill.
---

# Release Repair

Fix failed releases. All code changes happen in `semitexa.dev`. Never commit/push/switch branches from this skill.

## Workflow

1. **Find report**: newest file in `var/docs/release` (unless user specified path)
2. **Extract failure facts**: failed stage, first failing gate, test names, artifact paths
3. **Stop release stack**: all `semitexarls-*` containers
4. **Inspect artifacts**: report snippet → failing test → screenshot → `error-context.md`/`trace.zip` → implementation files
5. **Map to semitexa.dev**: `semitexa.rls/packages/<repo>` → `semitexa.dev/packages/<repo>`, `semitexa.rls/src/...` → `semitexa.dev/src/...`
6. **Reproduce and fix** in `semitexa.dev` (not rls). Minimal changes. Fix app code before test expectations.
7. **Validate** in `semitexa.dev`: narrowest useful checks (route/module tests, targeted Playwright spec, PHP checks)
8. **Hand off**: stop. Next steps = review-prep → merge → release-readiness

## Rules

- Fix first failing gate only. Don't skip.
- Don't patch multiple speculative areas when one artifact points to narrow cause.
- No permanent fixes in `semitexa.rls`.
- Never switch branches, commit, push, merge, or open PRs.
- Don't rerun `release-preflight` unless user asks.
- Keep fixes commit-sized and coherent.

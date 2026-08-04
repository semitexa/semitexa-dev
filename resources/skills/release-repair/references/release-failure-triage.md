# Release Failure Triage

## Default Sources

- Latest report root: `/home/taras/Documents/Projects/semitexa.dev/var/docs/release`
- Source repos root: `/home/taras/Documents/Projects/semitexa.dev`
- Release clone root: `/home/taras/Documents/Projects/semitexa.rls`
- Playwright artifacts root: `/home/taras/Documents/Projects/semitexa.rls/test-results`
- Browser smoke spec: `/home/taras/Documents/Projects/semitexa.rls/tests/e2e/release-smoke.spec.ts`

## Edit Target Rule

- Read failures from `semitexa.rls`.
- Stop all running `semitexarls-*` containers before local reproduction.
- Fix code in `semitexa.dev`.
- Reproduce and validate the fix in `semitexa.dev`.
- Never treat `semitexa.rls` as the source-of-truth repo for reviewable changes.
- Never switch branches in `semitexa.dev`; the working `develop` branches are already the source of truth.
- Never hand-edit `semitexa.rls`.

## Artifact Order

Use the smallest set of artifacts that explains the failure:

1. Release report
2. Screenshot `.png`
3. Video `.webm`
4. `error-context.md`
5. `trace.zip`

## Typical Commands

Use fast file discovery first:

```bash
find /home/taras/Documents/Projects/semitexa.dev/var/docs/release -maxdepth 1 -type f -name '*.md'
rg -n "Failed stage|Playwright Artifacts|test-results|release smoke" /path/to/report.md
find /home/taras/Documents/Projects/semitexa.rls/test-results -type f
sed -n '1,220p' /home/taras/Documents/Projects/semitexa.rls/tests/e2e/release-smoke.spec.ts
find /home/taras/Documents/Projects/semitexa.dev/packages -maxdepth 2 -type d -name '.git'
```

## Decision Heuristics

- If the screenshot shows obviously wrong UI state, inspect the route implementation before touching the test.
- If the UI is correct but the expectation string is stale, update the test.
- If multiple tests fail on the same route family, look for one shared runtime cause.
- If Playwright fails after route smoke passed, focus on rendered content, client behavior, SSE, metadata, or hydration rather than boot failure.
- If the failing file lives under `semitexa.rls/packages/<repo>`, apply the real fix under `semitexa.dev/packages/<repo>`.
- If the failing file lives under `semitexa.rls/src/...`, apply the real fix under `semitexa.dev/src/...`.
- If the failing file lives under `semitexa.rls/src/modules/...`, locate and fix the source in `semitexa.dev` and keep `semitexa.rls` read-only.

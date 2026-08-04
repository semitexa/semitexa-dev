# Agent skills — canonical copies

This directory is the **only** place these skills should be edited. Everything
else is a destination.

```bash
bin/skills-sync.sh          # fan out to bin/ and every agent runtime directory
bin/skills-sync.sh --check  # report drift, exit 1
bin/skills-sync.sh --list   # show every copy and its state
```

`ai:verify` runs `--check` on every invocation, so drift fails the gate rather
than waiting to be noticed.

## Why they live here

They were in `bin/` and in the runtime directories, none of which is
version-controlled: the Semitexa project root is not a git repository, so `bin/`,
`.claude/skills/` and `.codex/skills/` are tracked by nothing. Fixes existed on
one machine, with no history and no review, and a reinstall would have taken
them.

`semitexa-dev` already owns `ai:verify` and the rest of the tooling, and
`resources/` is the shelf for non-code assets — the module-structure validator
skips that subtree, and `semitexa-os` and `semitexa-update` already ship shell
scripts from theirs.

## Why there are copies at all

Each agent runtime loads skills from its own directory: Claude reads
`.claude/skills`, Codex reads `~/.codex/skills` (the *home* one, not the copy in
this project). The duplication is forced by those runtimes. Keeping the copies in
step, however, was left to whoever remembered.

That failed twice, and the second time is the instructive one.

**2026-08-03** — five locations held three versions of `pr-reply.sh`:

- `.claude` had `--kind` routing that `~/.codex` lacked, so a review-summary or
  issue reply issued from Codex hit the line-comment endpoint and failed.
- `~/.codex` had reply throttling that `.claude` lacked, so Claude sent replies
  in a burst straight into GitHub's secondary rate limiter.

The fix covered those five scripts and stopped there.

**2026-08-04** — everything the list did not name had drifted, in both
directions at once. Four of five skills:

| what | where it was newer |
|---|---|
| skill bodies (`SKILL.md`) | `.claude` |
| Packagist env-var precedence guard | `.claude` |
| `create-review-pr.sh` REST workaround for Projects (classic) | `.claude` |
| `release-sync-masters.sh` develop→master divergence detection | `~/.codex` |
| reply-pacing rules in `CODE_REVIEW.md` | `~/.codex` |

Again each copy held something the others needed. So the unit of sync is now the
**whole skill**, walked from this tree — a file nobody remembered to list is
precisely the file that drifts.

## What is deliberately not synced

Runtime-specific configuration. `agents/openai.yaml` is Codex's, means nothing
under `.claude`, and is absent from here. The sync only ever copies canonical
files over destinations; it never prunes what it finds, so those files survive
untouched in the runtime that owns them.

## Skill bodies must stay runtime-neutral

A `SKILL.md` here is shipped verbatim to every runtime, so it cannot name a
runtime's own path. Refer to bundled scripts relatively — `scripts/foo.sh`,
resolved against the base directory the runtime reports when it loads the skill.
A hardcoded `.claude/skills/…` or `~/.codex/skills/…` prefix is correct in at
most one destination and silently wrong in the rest.

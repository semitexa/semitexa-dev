# Codereview helper scripts — canonical copies

This directory is the **only** place these scripts should be edited. Everything
else is a destination.

```bash
bin/skills-sync.sh          # fan out to bin/ and every agent skill directory
bin/skills-sync.sh --check  # report drift, exit 1
bin/skills-sync.sh --list   # show every copy and its state
```

## Why they live here

They were in `bin/`, which is not version-controlled: the Semitexa project root
is not a git repository, so `bin/`, `.claude/skills/` and `.codex/skills/` are
tracked by nothing. Fixes to these scripts existed on one machine, with no
history and no review, and a reinstall would have taken them.

`semitexa-dev` already owns `ai:verify` and the rest of the tooling, and
`resources/` is the shelf for non-code assets — the module-structure validator
skips that subtree, and `semitexa-os` and `semitexa-update` already ship shell
scripts from theirs.

## Why there are copies at all

Each agent runtime loads skills from its own directory: Claude reads
`.claude/skills`, Codex reads `~/.codex/skills` (the *home* one, not the copy in
this project). The duplication is forced by those runtimes. Keeping the copies in
step, however, was left to whoever remembered.

That failed. Measured 2026-08-03, five locations held three versions of
`pr-reply.sh`:

- `.claude` had `--kind` routing that `~/.codex` lacked, so a review-summary or
  issue reply issued from Codex hit the line-comment endpoint and failed.
- `~/.codex` had reply throttling that `.claude` lacked, so Claude sent replies
  in a burst straight into GitHub's secondary rate limiter.

Each copy held something the others needed, and nothing would tell you without
diffing all five. `--check` is the part that matters; the sync is what you run
after it fails.

## The files

| Script | Role |
|---|---|
| `pr-review.sh` | Collects open PRs and classifies review comments into the queue |
| `pr-process.sh` | The preflight: blockers, warnings, and the per-item next commands |
| `pr-reply.sh` | Posts a reply, routed by `--kind=line\|review\|issue`, throttled |
| `pr-comment.sh` | Backwards-compatible alias for `pr-reply.sh` |
| `commit.sh` | Sets the authoring identity and stages; refuses an empty index |
| `skills-sync.sh` | This fan-out tool (synced to `bin/` only) |

`skills-sync.sh` installs through a temp file and `mv`, because `bin/` holds a
copy of the sync tool itself and a plain `cp` can rewrite the file bash is still
reading.

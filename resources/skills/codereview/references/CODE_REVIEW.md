# Task: Process Code Review Feedback for Semitexa Packages

## Objective
Process all open code review comments for Semitexa package pull requests, land the required fixes on the PR branch, and only then reply to reviewers.

## Workflow

1. Build the actionable queue:
```bash
bin/pr-process.sh
```

This command builds a review work queue and prints:
- package repo path
- local checked-out branch
- whether the local worktree is dirty
- PR base and head branches
- GitHub mergeability state
- every actionable comment with `repliedByAuthor`, across three sources:
  - `kind=line`: line-anchored review comments (`pulls/N/comments`)
  - `kind=review`: review summary bodies that contain inline P-badges or critique markers (`pulls/N/reviews`)
  - `kind=issue`: PR conversation comments from non-author reviewers/bots (`issues/N/comments`)
- exact next-step commands for commit, push, and replies, with `--kind=<kind>`
  passed automatically so the reply hits the correct GitHub endpoint

If you need raw structured data instead of the human queue:
```bash
bin/pr-review.sh --compact --actionable-only
```

Resolution heuristics:
- `kind=line`: resolved when the PR author posts a reply with `inReplyToId == comment.id`.
- `kind=review`: resolved when (a) a later non-author review exists against
  a different `commit_id` (the bot re-reviewed after the author pushed a fix)
  or (b) an author issue comment text mentions the review's commit short SHA
  (e.g. "Fixed in 8a0950d").
- `kind=issue`: resolved when any later author issue comment exists.

Useful stricter modes:
```bash
bin/pr-process.sh --ready-only
bin/pr-process.sh --fail-on-warnings
```

- `--ready-only` hides PRs that are blocked or already fully processed
- `--fail-on-warnings` exits non-zero on branch mismatch or unknown mergeability

2. For each PR in the queue:
- Confirm `mergeable` is not `CONFLICTING`
- Work only inside the package repository shown in the `path` field
- Read and process only comments where `repliedByAuthor` is `false`
- Apply the smallest correct fix that addresses the review

3. Run PHPStan from the Semitexa project root before any push:
```bash
composer phpstan
```

4. Commit and push before any reply:
```bash
bin/commit.sh /absolute/path/to/repo
git -C /absolute/path/to/repo commit -m "Your commit message"
git -C /absolute/path/to/repo push origin HEAD
```

Important:
- `bin/commit.sh` sets the Git author identity for that repo **and stages the
  work** (`git add -A`, tracked and untracked)
- it does not create the commit for you — you still write the message
- it exits non-zero when there is nothing to stage, on a protected branch
  (`master`/`main`), or when the path is not a git repo

That non-zero exit is the point. Staging used to be nobody's job in this
sequence, so with unstaged changes the `git commit` failed with "no changes added
to commit" and the `push` on the next line answered "Everything up-to-date" and
exited 0 — a no-op that reads as a successful push, which then gets replied to as
though the fix had shipped. Never reply on the strength of a push whose commit you
did not see land; `git -C <repo> log --oneline -1` costs nothing.

4. Reply to each processed review comment. Add `--kind=<kind>` to match the
   source — `pr-process.sh` prints the correct invocation per item:
```bash
bin/pr-reply.sh <repo-slug> <pr-number> <comment-id> "<reply body>" --kind=line
bin/pr-reply.sh <repo-slug> <pr-number> <review-id>  "<reply body>" --kind=review
bin/pr-reply.sh <repo-slug> <pr-number> <comment-id> "<reply body>" --kind=issue
```

- `--kind=line` (default) replies via `pulls/N/comments/<id>/replies`.
- `--kind=review` and `--kind=issue` post a fresh PR conversation comment via
  `issues/N/comments` and prepend a `> Re: ...` reference line so the thread
  back-link is preserved.

Reply pacing — GitHub moderates bursts, and a run that trips it loses the
replies it had left to post:
- Never fire many replies back-to-back without pauses.
- Insert a random delay between replies.
- If GitHub answers with abuse detection, a secondary rate limit, or any similar
  moderation response, stop the burst and retry later with longer pauses.

Compatibility note:
- `bin/pr-comment.sh` is an alias of `bin/pr-reply.sh`
- The bare 4-arg form (`pr-reply.sh <repo> <pr> <id> <body>`) defaults to
  `--kind=line` and matches the previous behavior.

## Required Actions

1. Identify all open package PRs with actionable review comments.
2. For each PR:
   - Verify it still targets `master`
   - Verify it is mergeable
   - Verify the local worktree is clean before making changes
   - Apply fixes for each unresolved actionable comment
   - Run `composer phpstan` from the Semitexa project root
   - Commit and push the changes
   - Only then reply on the exact review comment thread
3. If a comment is already addressed by the pushed code, say so briefly.
4. If a comment is incorrect or not applicable, reply with a concise technical explanation.

## Important Rules

- Always run `composer phpstan` from the Semitexa project root before pushing review fixes
- Always commit and push before replying to review comments
- Do not ignore any open actionable review comment
- Keep replies short, factual, and specific to the comment
- Do not modify unrelated code unless it is required to make the review fix correct
- Preserve existing architecture and package boundaries
- Prefer one focused commit per PR review pass

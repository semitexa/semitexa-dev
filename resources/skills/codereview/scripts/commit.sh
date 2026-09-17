#!/bin/bash
#
# Prepare a review-fix commit: check the authoring identity, then stage the work.
#
# Usage: commit.sh /absolute/path/to/repo
#
# Staging belongs here because of how this script is used. The documented review
# sequence is three separate commands:
#
#     commit.sh <repo>
#     git -C <repo> commit -m "<review fix message>"
#     git -C <repo> push origin HEAD
#
# Nothing in that sequence ever ran `git add`. With unstaged changes the commit
# failed with "no changes added to commit" and the push that followed reported
# "Everything up-to-date" and exited 0 — a no-op wearing the costume of a
# successful push. Since the caller is usually an agent running the three lines
# in one shell invocation, that combination is worth engineering against: it is
# the one failure mode here that produces a wrong belief rather than an error.
#
# So: stage, and exit non-zero when there is nothing to stage, which stops the
# sequence before it can report a push that did not happen.
set -euo pipefail

repo="${1:-}"

if [ -z "$repo" ]; then
    printf 'Usage: %s /absolute/path/to/repo\n' "$0" >&2
    exit 1
fi

# Without this the old `repo="${@: -1}"` resolved to an empty string when called
# with no arguments, and `git -C ""` silently operated on the current working
# directory — rewriting author config in whatever repo the caller happened to be
# standing in.
# `rev-parse` rather than a test for a .git *directory*: in a linked worktree
# .git is a regular file holding a gitdir pointer, so the directory test would
# reject exactly the setup an agent gets when it works in an isolated worktree.
# Compared against "true", not just tested for success: in a bare repository
# rev-parse prints "false" and exits 0, so a status-only check accepts it and the
# failure surfaces later at `git add -A` instead of here.
is_work_tree="$(git -C "$repo" rev-parse --is-inside-work-tree 2>/dev/null || printf 'false')"
if [ "$is_work_tree" != "true" ]; then
    printf 'Not a git working tree: %s\n' "$repo" >&2
    exit 1
fi

branch="$(git -C "$repo" rev-parse --abbrev-ref HEAD)"

# `--abbrev-ref HEAD` answers with the literal string "HEAD" on a detached HEAD,
# so the protected-branch case below would not match and staging would proceed.
# The commit that follows becomes unreachable the moment anything checks out a
# branch — the same "it reported success and the work is gone" shape this file
# already guards against for the empty-index case.
if [ "$branch" = "HEAD" ]; then
    printf 'Refusing to stage on a detached HEAD in %s — the commit that follows would be unreachable once a branch is checked out.\n' "$repo" >&2
    exit 1
fi

case "$branch" in
    master|main)
        printf 'Refusing to stage on protected branch: %s\n' "$branch" >&2
        exit 1
        ;;
esac

# The identity belongs to whoever is committing, and this script does not get
# to invent one.
#
# It used to pick at random from three made-up authors and write the winner
# into the repository's own config. That is how 36 of these repositories came
# to publish their history under people who do not exist, and it silently
# overrode whatever identity the developer had configured.
#
# It is also wrong the moment this file leaves this machine: it ships inside
# semitexa/dev, so any identity hardcoded here would be stamped onto someone
# else's commits in their own repository.
#
# So the script only CHECKS. Git resolves the identity from the worktree, the
# repository and the global config in that order; when none of them answers,
# the commit that follows fails with git's own message — but only after the
# staging has already happened, which is the confusing half-done state this
# file exists to prevent. Failing here keeps the documented sequence honest.
author_name="$(git -C "$repo" config user.name || true)"
author_email="$(git -C "$repo" config user.email || true)"

if [ -z "$author_name" ] || [ -z "$author_email" ]; then
    printf 'No git identity configured for %s.\n' "$repo" >&2
    printf 'Set user.name and user.email — globally, or in this repository — before staging.\n' >&2
    exit 1
fi

author="$author_name <$author_email>"

git -C "$repo" add -A

if git -C "$repo" diff --cached --quiet; then
    printf 'Nothing to commit in %s — working tree clean, so the commit and push that follow would be no-ops.\n' "$repo" >&2
    exit 1
fi

staged="$(git -C "$repo" diff --cached --name-only | wc -l | tr -d ' ')"
printf 'Staged %s file(s) in %s on %s as %s\n' "$staged" "$repo" "$branch" "$author"

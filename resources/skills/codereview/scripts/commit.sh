#!/bin/bash
#
# Prepare a review-fix commit: set the authoring identity, then stage the work.
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

authors=(
  "SyntaxWanderer <email1@example.com>"
  "Profile 朝 <email2@example.com>"
  "Yuchera <email3@example.com>"
)

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

author="$(shuf -n1 -e "${authors[@]}")"
author_name="$(printf '%s' "$author" | sed 's/ <.*//')"
author_email="$(printf '%s' "$author" | sed 's/.*<//;s/>//')"

# Repository-local config is SHARED across linked worktrees, so two worktrees
# staging at once would overwrite each other's identity and the commit could go
# out under whichever name landed last. In a linked worktree the identity is
# therefore written worktree-locally instead; extensions.worktreeConfig is the
# switch git requires for that to take effect, and it is scoped to this repo.
if [ -f "$repo/.git" ]; then
    git -C "$repo" config extensions.worktreeConfig true
    git -C "$repo" config --worktree user.name "$author_name"
    git -C "$repo" config --worktree user.email "$author_email"
else
    git -C "$repo" config user.name "$author_name"
    git -C "$repo" config user.email "$author_email"
fi

git -C "$repo" add -A

if git -C "$repo" diff --cached --quiet; then
    printf 'Nothing to commit in %s — working tree clean, so the commit and push that follow would be no-ops.\n' "$repo" >&2
    exit 1
fi

staged="$(git -C "$repo" diff --cached --name-only | wc -l | tr -d ' ')"
printf 'Staged %s file(s) in %s on %s as %s\n' "$staged" "$repo" "$branch" "$author"

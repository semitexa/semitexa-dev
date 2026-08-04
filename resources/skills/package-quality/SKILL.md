---
name: package-quality
description: Audit and improve the quality and release hygiene of Semitexa packages under packages/*. Use when the user wants to verify package metadata, license files, git remotes, GitHub repository provisioning, or develop-branch consistency across all package repositories.
---

# Package Quality

## Overview

Use this skill to inspect or normalize Semitexa package repositories under `packages/*`.
The skill covers package metadata, license currency, git/GitHub setup, GitHub metadata/webhooks, Packagist readiness, and branch policy alignment.

## When To Use This Skill

Use this skill for requests such as:

- "Перевір всі пакети"
- "пройдися по packages і покращ якість"
- "create missing GitHub repos for new packages"
- "make sure all package repos are on develop"
- "validate composer descriptions and keywords"
- "update all package LICENSE years"

Do not use this skill for application feature work unrelated to package governance.

## Workflow

### 1. Build package inventory

- Treat each directory under `packages/*` as a candidate package.
- Skip known non-package exceptions that intentionally live under `packages/*` but are not governed as Semitexa package repositories.
- Prefer `find packages -mindepth 1 -maxdepth 1 -type d | sort` for inventory.
- For each package, inspect:
  - `composer.json`
  - `LICENSE`
  - `.git/`
  - current branch
  - `origin` remote

Default exclusions:

- `semitexa-installer`: Docker and installer assets, not a governed Semitexa package

### 2. Run in the correct mode

Choose one of these modes before making changes:

- `audit`: read-only inspection and report only.
- `fix`: apply local file changes such as LICENSE year updates or `composer.json` metadata normalization.
- `provision`: create missing GitHub repositories, attach remotes, ensure `develop` exists locally and remotely.
- `full`: run `audit`, then `fix`, then `provision`.

Default to `audit` unless the user clearly asked for changes.

## Checks

### License

- Verify that `LICENSE` exists in the package root.
- Verify that the copyright year matches the current calendar year.
- If the file is missing, create an MIT license using the standard Semitexa copyright line.
- If the year is stale, update only the year.

Use `scripts/package_quality.py --check license`.

### Composer metadata

- Verify `composer.json` exists and parses as JSON.
- Require `description` to be present and non-empty.
- Require `keywords` to be present and non-empty.
- Evaluate whether `description` and `keywords` match the package purpose.
- Use package signals to infer purpose:
  - package name
  - namespace under `src/`
  - README first paragraphs if present
  - class names and service names
- Prefer concise package descriptions and 4-8 lowercase keywords.
- Keep keywords concrete. Include `semitexa` and the package domain. Avoid generic filler such as `framework`, `library`, or `tooling` unless they add meaning.

Use `references/metadata_policy.md` for normalization rules and examples.
Use `scripts/package_quality.py --check metadata` for deterministic validation and template generation.

If semantic relevance is ambiguous, report a suggested replacement instead of silently inventing metadata.

### Git remote and GitHub repository

- If `.git` is missing, treat the package as not yet initialized as a git repository and report it.
- If `.git` exists but `origin` is missing, treat the package as a new repository candidate.
- If `.git` and `origin` both exist, still verify the GitHub repository metadata.
- Before provisioning, collect:
  - repo name
  - short description
  - homepage `https://semitexa.com`
  - topics derived from package keywords
- Use GitHub CLI to create missing repos, connect `origin`, and synchronize GitHub metadata for both new and existing repos.

Preferred command pattern:

```bash
gh repo create semitexa/<repo-name> --source <package-dir> --public --description "<short-description>" --homepage "https://semitexa.com" --remote origin --push
```

Then apply topics separately:

```bash
gh repo edit semitexa/<repo-name> --add-topic topic-a --add-topic topic-b
```

For existing repositories, do not stop after detecting `origin`. Resolve the GitHub repo slug from `origin`, then ensure:

- repository description matches the package short description
- homepage is `https://semitexa.com`
- topics are present and aligned with package keywords

Prefer synchronizing topics as the exact intended set instead of only appending new topics.

Do not create GitHub repositories unless the user asked for provisioning or explicitly approved the action.

### Packagist presence and webhook

- Derive the Packagist package name from `composer.json` `name`.
- Before adding a Packagist webhook, verify that the package already exists on Packagist.
- If the package does not exist on Packagist, report `manual-review` and stop short of webhook creation for that package.
- If the package exists on Packagist, ensure the GitHub repository has a Packagist webhook with:
  - Payload URL: `https://packagist.org/api/github?username=PACKAGIST_USERNAME`
  - Content-Type: `application/json`
  - Secret: Packagist API token
  - Events: only `push`
  - Active: `true`
- When an existing Packagist webhook is present but misconfigured, update it instead of creating a duplicate.
- Because GitHub does not return webhook secrets, prefer reapplying the secret when synchronizing the webhook.

Use `scripts/sync_packagist_webhook.sh`.

Required runtime environment variables:

- `PACKAGIST_USERNAME`
- `PACKAGIST_API_TOKEN`

### Branch policy

- Local working branch must always be `develop`.
- GitHub default branch must always be `master`.
- Pull requests must target `master`.
- If the current local branch is not `develop`, switch to `develop`.
- If `develop` does not exist locally:
  - create it from `master` if `master` exists;
  - otherwise create it from the current default branch and report the deviation.
- If `origin/develop` does not exist after local creation, push with upstream tracking.
- If the GitHub repository default branch is not `master`, change it to `master`.
- Treat either mismatch as something to fix, not just report:
  - local active branch is not `develop`;
  - GitHub default branch is not `master`.
- If the worktree is dirty, stop before switching branches and report the blocking files.

Use `scripts/ensure_develop_branch.sh`.

## Reporting

Always produce a compact package-by-package report with these statuses:

- `ok`
- `fixed`
- `provisioned`
- `blocked`
- `manual-review`

Also list skipped exceptions separately when relevant.

For each package, include only the relevant findings:

- missing or stale `LICENSE`
- missing or weak `description`
- missing or weak `keywords`
- missing `.git`
- missing `origin`
- missing or stale GitHub description
- missing or stale GitHub homepage
- missing or stale GitHub topics
- package missing on Packagist
- missing or stale Packagist GitHub webhook
- branch mismatch
- provisioning performed

## Safety Rules

- Never switch branches in a dirty package worktree.
- Never overwrite an existing `origin` remote unless the user explicitly asks.
- Never create GitHub repositories in `audit` mode.
- Prefer deterministic updates for licenses and JSON formatting.
- When metadata quality is subjective, propose text first or write clearly marked suggested values.

## Scripts

- `scripts/package_quality.py`
  - inventory, license checks, metadata validation, and optional fixing
- `scripts/ensure_develop_branch.sh`
  - branch alignment to `develop`
- `scripts/provision_github_repo.sh`
  - GitHub repo creation, remote wiring, homepage, and topics
- `scripts/sync_packagist_webhook.sh`
  - Packagist presence check and GitHub webhook synchronization for existing or new repositories

Run scripts from the repository root unless the user narrowed the scope.

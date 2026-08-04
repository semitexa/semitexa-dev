# Package Metadata Policy

Use this reference when validating or generating `composer.json` metadata for Semitexa packages.

## Description rules

- Keep descriptions to one sentence.
- Start with the package or domain name when it adds clarity.
- Describe the capability, not implementation trivia.
- Avoid vague wording such as `module for Semitexa Framework` when stronger domain language is available.
- Prefer specific nouns and 2-4 concrete capabilities.

Good patterns:

- `Semitexa Cache - tenant-aware cache store with tag invalidation and namespace isolation`
- `Semitexa Scheduler - recurring and delayed jobs with lease-based workers and overlap protection`
- `Semitexa Mail - outbound email delivery with SMTP, Twig templates, and storage-backed attachments`

Weak patterns:

- `A module for Semitexa`
- `Framework component`
- `Utilities and helpers`

## Keyword rules

- Include `semitexa` in every package.
- Include the package domain, for example `cache`, `scheduler`, `mail`, `rbac`, `orm`.
- Add capability words that a user would plausibly search for.
- Use lowercase kebab-case or lowercase single words.
- Prefer 4-8 keywords.
- Avoid duplicates and synonyms that add no search value.

Example keyword sets:

- Cache: `semitexa`, `cache`, `redis`, `tags`, `tenancy`
- Scheduler: `semitexa`, `scheduler`, `cron`, `jobs`, `queue`
- Mail: `semitexa`, `mail`, `smtp`, `twig`, `attachments`
- Storage: `semitexa`, `storage`, `filesystem`, `s3`, `minio`

## Package name heuristics

Infer the initial domain from the package name:

- `semitexa-cache` -> `cache`
- `semitexa-platform-user` -> `platform-user`
- `semitexa-platform-wm` -> `window-manager`
- `semitexa-ssr` -> `ssr`

Then refine using README and source names.

## Fix policy

- In `audit` mode, report proposed `description` and `keywords` but do not write them.
- In `fix` mode, write deterministic additions for missing `keywords` only when the package purpose is obvious.
- If confidence is low, keep the file unchanged and report `manual-review`.

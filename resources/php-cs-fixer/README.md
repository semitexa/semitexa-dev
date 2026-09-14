# php-cs-fixer

The canonical copy of `.php-cs-fixer.dist.php`, on the same shelf and for the
same reason as `resources/phpstan/` and `resources/skills/`: the project root is
not a git repository, so a config living only at the root is versioned by
nothing at all.

Copy it to the workspace root to use it:

```bash
cp packages/semitexa-dev/resources/php-cs-fixer/.php-cs-fixer.dist.php .
vendor/bin/php-cs-fixer fix --dry-run --diff
```

## What it is, and what it deliberately is not

It describes the style the code **already has**. Every rule in it was run alone
and measured to change **zero** of the 4605 files under `packages/` and `src/`,
and the whole config together reports `Found 0 of 4605 files that can be fixed`.

That order was the requirement, not a nicety: the config was written and proven
from a throwaway install *before* the dependency was added. A fixer introduced
the other way round arrives with a thousand-file normalising commit that nobody
can review.

It is **not** `@PSR12` wholesale. Seven PSR-12 rules disagree with this
codebase; each is listed in the config with the number of files it would touch,
so switching one on stays a deliberate act with a known cost.

## The one finding that is not about style

`no_unused_imports` would change **142 files** — those files import something
nothing uses. That is not a formatting preference, and it is tracked as its own
task rather than hidden in a linter flag.

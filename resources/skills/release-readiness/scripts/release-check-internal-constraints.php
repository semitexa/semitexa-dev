#!/usr/bin/env php
<?php

declare(strict_types=1);

$releaseRoot = rtrim(getenv('RELEASE_ROOT') ?: '/home/taras/Documents/Projects/semitexa.rls', DIRECTORY_SEPARATOR);
$packagesDir = $releaseRoot . '/packages';

if (!is_dir($packagesDir)) {
    fwrite(STDERR, "Packages directory not found: {$packagesDir}\n");
    exit(1);
}

$composerFiles = glob($packagesDir . '/*/composer.json') ?: [];
$violations = [];

foreach ($composerFiles as $composerPath) {
    $json = json_decode((string) file_get_contents($composerPath), true);
    if (!is_array($json)) {
        $violations[] = [
            'package' => basename(dirname($composerPath)),
            'section' => 'composer.json',
            'dependency' => '(invalid-json)',
            'constraint' => '',
            'reason' => 'invalid JSON',
        ];
        continue;
    }

    $packageName = (string) ($json['name'] ?? basename(dirname($composerPath)));
    if ($packageName === 'semitexa/ultimate') {
        continue;
    }

    foreach (['require', 'require-dev'] as $section) {
        $requirements = $json[$section] ?? [];
        if (!is_array($requirements)) {
            continue;
        }

        foreach ($requirements as $dependency => $constraint) {
            if (!is_string($dependency) || !str_starts_with($dependency, 'semitexa/') || $dependency === $packageName) {
                continue;
            }

            $constraint = is_string($constraint) ? trim($constraint) : '';
            if (isCompatibleInternalConstraint($constraint)) {
                continue;
            }

            $violations[] = [
                'package' => $packageName,
                'section' => $section,
                'dependency' => $dependency,
                'constraint' => $constraint,
                'reason' => 'internal Semitexa dependency is not compatible with UTC date-based releases; '
                    . 'use "*", an exact "2026.09.13.0749", or a floor ">=2026.09.13.0749" naming the release '
                    . 'that first shipped the API this package calls',
            ];
        }
    }
}

if ($violations === []) {
    echo "[OK] Internal Semitexa package constraints are compatible with UTC date-based releases.\n";

    $unsatisfiable = checkFloorsAreSatisfiable($packagesDir);
    if ($unsatisfiable !== []) {
        fwrite(STDERR, "A floor names a release that does not contain what this package imports:\n");
        foreach ($unsatisfiable as $problem) {
            fwrite(STDERR, '- ' . $problem . "\n");
        }
        fwrite(STDERR, "\nRaise the floor to the release that first shipped the class, or stop importing it.\n");
        exit(1);
    }

    echo "[OK] Every internal floor names a release that actually contains the classes its package imports.\n";
    exit(0);
}

fwrite(STDERR, "Found incompatible internal Semitexa package constraints:\n");
foreach ($violations as $violation) {
    fwrite(
        STDERR,
        sprintf(
            "- %s [%s] %s: %s (%s)\n",
            $violation['package'],
            $violation['section'],
            $violation['dependency'],
            $violation['constraint'] !== '' ? $violation['constraint'] : '(empty)',
            $violation['reason']
        )
    );
}

exit(1);

/**
 * Four forms, and each says something different.
 *
 *   *                            works with any release of that one
 *   2026.09.13.0749              exactly this release (ultimate's pins)
 *   >=2026.09.13.0749            a FLOOR: where the API it calls appeared
 *   >=2026.09.13.0749 || dev-master   the same floor, plus the local checkout
 *
 * THE `|| dev-master` IS NOT OPTIONAL ON A FLOOR, and the release that added
 * the first floors proved it by failing preflight outright. Packages are
 * developed as PATH REPOSITORIES, where composer takes the version from the
 * git branch -- `dev-master` -- and a branch version satisfies no date floor.
 * The path repo is also canonical, so composer cannot fall back to Packagist:
 * it simply reports the whole set as uninstallable. A floor without the escape
 * is a floor that makes the workspace unbuildable while protecting a consumer
 * who was never at risk from the local checkout.
 *
 * `dev-master` and not `dev-develop`: composer resolution runs in the release
 * clone, which this flow keeps on master by construction. It is also safe for
 * consumers -- a branch version needs an explicit dev minimum-stability, which
 * a project that installs released packages does not have.
 *
 * (Never a `version` field in the package to sidestep this. It poisons two
 * things at once: path-repo resolution, and Packagist, which silently skips
 * every tag whose version does not match it.)
 *
 * The floor exists because `*` cannot express a minimum, and a consumer who
 * installs one package directly -- `composer require semitexa/ledger` beside a
 * core that is pinned older -- got a resolution that composer was happy with
 * and a worker that died on `Class Semitexa\Core\Support\StandingCoroutines
 * not found`. A floor turns that into a resolution error, which is a sentence
 * rather than a crash.
 *
 * WHEN TO WRITE ONE: you added a call into another Semitexa package's NEW API.
 * Put the release that first shipped that API. Nothing rewrites it afterwards
 * and nothing should: it is a fact about the code, not about the current
 * release, and bumping it every release would make it mean `*` again.
 *
 * WHEN NOT TO: everything else. A floor on an API that has been there for
 * months only stops consumers from mixing versions that would have worked.
 */
function isCompatibleInternalConstraint(string $constraint): bool
{
    if ($constraint === '*') {
        return true;
    }

    $version = '\d{4}\.\d{2}\.\d{2}\.\d{4}(?:-(?:alpha|beta|rc\d+|p\d+))?';

    // An exact pin, or a floor with the optional `|| dev-master` escape the
    // path-repo workspace needs. The escape is only accepted on a FLOOR: on an
    // exact pin it would defeat the pin, which is what ultimate's are for.
    if (preg_match('/^' . $version . '$/i', $constraint) === 1) {
        return true;
    }

    return preg_match('/^>=\s*' . $version . '(?:\s*\|\|\s*dev-master)?$/i', $constraint) === 1;
}

/**
 * A FLOOR THAT NAMES A RELEASE WITHOUT THE CLASS IS WORSE THAN NO FLOOR.
 *
 * The form check above asks whether a constraint is spelled correctly. This
 * asks the question that actually matters: `semitexa/ssr` floored core at the
 * release before the one that introduced `Semitexa\Core\Support\Row`, while
 * importing Row in thirteen files. Composer resolves that happily and the
 * worker dies on `Class not found` at the first request — which is the exact
 * failure the floor exists to turn into a resolution error.
 *
 * A human found that in review. Nothing here would have.
 *
 * The check: for every `>=` floor on a sibling, read the classes this package
 * IMPORTS from that sibling's namespace, and confirm each one's file exists in
 * the sibling AT THAT TAG. One `git ls-tree` per (package, tag) rather than a
 * lookup per import — the tree is read once and membership tested in memory.
 *
 * @return list<string> one sentence per unsatisfiable import
 */
function checkFloorsAreSatisfiable(string $packagesDir): array
{
    $packages = indexPackages($packagesDir);
    $problems = [];

    foreach ($packages as $name => $package) {
        foreach ($package['floors'] as $dependency => $floorVersion) {
            $target = $packages[$dependency] ?? null;
            if ($target === null) {
                // Not in this workspace; nothing local to verify against.
                continue;
            }

            $tree = treeAtTag($target['dir'], $floorVersion);
            if ($tree === null) {
                $problems[] = sprintf(
                    '%s floors %s at %s, but that tag is not in %s — fetch tags, or the floor names a release that does not exist',
                    $name,
                    $dependency,
                    $floorVersion,
                    basename($target['dir']),
                );
                continue;
            }

            // THE MAP MUST COME FROM THE SAME TAG AS THE TREE. $target['psr4']
            // is today's autoload block; if the provider moved its sources
            // between that release and now, mapping a class through the current
            // map and looking it up in the OLD tree compares two different
            // layouts — rejecting a floor that is fine, or approving a path
            // that was never autoloadable at that tag.
            $psr4 = psr4AtTag($target['dir'], $floorVersion) ?? $target['psr4'];

            foreach (importsFrom($package['dir'], $psr4) as $class => $relativePath) {
                if (in_array($relativePath, $tree, true)) {
                    continue;
                }

                $problems[] = sprintf(
                    '%s imports %s but floors %s at %s, where %s does not exist',
                    $name,
                    $class,
                    $dependency,
                    $floorVersion,
                    $relativePath,
                );
            }
        }
    }

    return $problems;
}

/**
 * @return array<string, array{dir: string, psr4: array<string, string>, floors: array<string, string>}>
 */
function indexPackages(string $packagesDir): array
{
    $packages = [];

    foreach (glob($packagesDir . '/*/composer.json') ?: [] as $composerPath) {
        $json = json_decode((string) file_get_contents($composerPath), true);
        if (!is_array($json) || !is_string($json['name'] ?? null)) {
            continue;
        }

        $psr4 = [];
        foreach ($json['autoload']['psr-4'] ?? [] as $prefix => $dir) {
            if (is_string($prefix) && is_string($dir)) {
                $psr4[$prefix] = rtrim($dir, '/');
            }
        }

        $floors = [];
        foreach ($json['require'] ?? [] as $dependency => $constraint) {
            if (!is_string($dependency) || !is_string($constraint) || !str_starts_with($dependency, 'semitexa/')) {
                continue;
            }
            // The `|| dev-master` escape is not part of the promise being
            // checked; the floor is.
            if (preg_match('/^>=\s*(\d{4}\.\d{2}\.\d{2}\.\d{4}(?:-[a-z0-9]+)?)/i', $constraint, $m) === 1) {
                $floors[$dependency] = $m[1];
            }
        }

        $packages[$json['name']] = [
            'dir' => dirname($composerPath),
            'psr4' => $psr4,
            'floors' => $floors,
        ];
    }

    return $packages;
}

/**
 * Every path in a package at one tag, or null when the tag is not there.
 *
 * @return list<string>|null
 */
function treeAtTag(string $dir, string $tag): ?array
{
    $command = sprintf(
        'git -C %s ls-tree -r --name-only %s 2>/dev/null',
        escapeshellarg($dir),
        escapeshellarg($tag),
    );

    exec($command, $lines, $code);

    return $code === 0 && $lines !== [] ? $lines : null;
}

/**
 * The PSR-4 map as it stood AT a tag, or null when the tag has no readable
 * composer.json (in which case the caller keeps today's map rather than
 * treating every import as unresolvable).
 *
 * @return array<string, string>|null namespace prefix => source directory
 */
function psr4AtTag(string $dir, string $tag): ?array
{
    $command = sprintf(
        'git -C %s show %s 2>/dev/null',
        escapeshellarg($dir),
        escapeshellarg($tag . ':composer.json'),
    );

    exec($command, $lines, $code);
    if ($code !== 0 || $lines === []) {
        return null;
    }

    $json = json_decode(implode("\n", $lines), true);
    if (!is_array($json)) {
        return null;
    }

    $map = $json['autoload']['psr-4'] ?? null;
    if (!is_array($map)) {
        return null;
    }

    $psr4 = [];
    foreach ($map as $prefix => $path) {
        if (is_string($prefix) && is_string($path)) {
            $psr4[$prefix] = rtrim($path, '/');
        }
    }

    return $psr4 === [] ? null : $psr4;
}

/**
 * Classes this package imports from another's namespaces, as tag-relative
 * paths.
 *
 * `use function` and `use const` are skipped: they are not class files, and a
 * floor that guarantees a class says nothing about them either way.
 *
 * @param array<string, string> $psr4 namespace prefix => source directory
 * @return array<string, string> FQCN => path inside the target package
 */
function importsFrom(string $packageDir, array $psr4): array
{
    $found = [];
    $sourceDir = $packageDir . '/src';
    if (!is_dir($sourceDir)) {
        return $found;
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir));
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        foreach (importedClasses((string) file_get_contents($file->getPathname())) as $class) {
            foreach ($psr4 as $prefix => $dir) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
                $found[$class] = $dir . '/' . $relative . '.php';
                break;
            }
        }
    }

    return $found;
}

/**
 * Every class name a file imports at namespace level.
 *
 * TOKENIZED, not matched. A regex over `use ...;` lines gets three things
 * wrong, and all three break a gate that is supposed to fail closed:
 *
 *  - GROUPED imports — `use Semitexa\Core\Support\{Row, Other};` — match
 *    nothing, because `{` is not part of a class name. The gate then reports
 *    success for a floor whose tag has no Row.php, which is the exact runtime
 *    "class not found" it exists to prevent.
 *  - COMMA-SEPARATED imports — `use A\B, C\D;` — yield only the first name.
 *  - The word `use` inside a comment, a string, or a closure's `use (...)`
 *    clause is matched as though it were an import.
 *
 * `use function` and `use const` are skipped, and so are trait `use` statements
 * inside a class body: only namespace-level imports name a class file.
 *
 * @return list<string>
 */
function importedClasses(string $contents): array
{
    $tokens = PhpToken::tokenize($contents);
    $classes = [];
    $depth = 0;

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];

        // Trait `use` lives inside a class body; namespace-level `use` does not.
        if ($token->text === '{') {
            $depth++;
            continue;
        }
        if ($token->text === '}') {
            $depth--;
            continue;
        }
        if (!$token->is(T_USE) || $depth > 0) {
            continue;
        }

        // Collect the whole statement, so grouped and comma-separated forms
        // are read as one thing rather than a first name and some leftovers.
        $statement = '';
        $j = $i + 1;
        for (; $j < $n; $j++) {
            $text = $tokens[$j]->text;
            if ($text === ';') {
                break;
            }
            if ($tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            $statement .= $text;
        }
        $i = $j;

        // `use ($captured)` on a closure — not an import at all.
        if ($statement === '' || str_starts_with($statement, '(')) {
            continue;
        }
        // `use function foo;` / `use const BAR;` name no class file.
        if (str_starts_with($statement, 'function') || str_starts_with($statement, 'const')) {
            continue;
        }

        foreach (expandUseStatement($statement) as $class) {
            $classes[$class] = true;
        }
    }

    return array_keys($classes);
}

/**
 * Expand one `use` statement body into the class names it imports.
 *
 * Handles the plain, aliased, comma-separated and grouped forms:
 *   A\B\C            A\B\CasD        A\B,C\D        A\B\{C,DasE}
 *
 * @return list<string>
 */
function expandUseStatement(string $statement): array
{
    $open = strpos($statement, '{');
    if ($open !== false) {
        $prefix = substr($statement, 0, $open);
        $inner = rtrim(substr($statement, $open + 1), '}');
        $classes = [];
        foreach (explode(',', $inner) as $piece) {
            $name = stripAlias($piece);
            if ($name !== '') {
                $classes[] = ltrim($prefix, '\\') . $name;
            }
        }

        return $classes;
    }

    $classes = [];
    foreach (explode(',', $statement) as $piece) {
        $name = stripAlias($piece);
        if ($name !== '') {
            $classes[] = ltrim($name, '\\');
        }
    }

    return $classes;
}

/** Drop a trailing `as Alias` — the tokens arrive with whitespace removed. */
function stripAlias(string $piece): string
{
    $position = strrpos($piece, 'as');
    if ($position !== false && $position > 0) {
        $tail = substr($piece, $position + 2);
        // `as` is only an alias keyword when what follows is a bare name and
        // what precedes it ends a class name — never inside e.g. `Database`.
        if ($tail !== '' && !str_contains($tail, '\\') && ctype_upper($tail[0])) {
            $piece = substr($piece, 0, $position);
        }
    }

    return trim($piece);
}

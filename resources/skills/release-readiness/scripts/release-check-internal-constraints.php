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
        // A FLOOR and an EXACT PIN are both promises about a specific release,
        // so both are verified the same way. An exact pin used to be recorded
        // as neither floor nor wildcard and was therefore never inspected at
        // all — a package pinned to a release predating a class it imports
        // passed the gate and failed at the first request.
        foreach ($package['promises'] as $dependency => $promise) {
            $target = $packages[$dependency] ?? null;
            if ($target === null) {
                // Not in this workspace; nothing local to verify against.
                continue;
            }

            $problems = array_merge($problems, verifyAgainstTag(
                $name,
                $package['dir'],
                $dependency,
                $target,
                $promise['version'],
                $promise['kind'],
            ));
        }

        // `*` IS THE ONE FORM THAT PROMISES NOTHING, which is why the checks
        // above skip it — and exactly why it needs one of its own.
        //
        // A package that calls a sibling's BRAND-NEW class while requiring it
        // at `*` resolves against every released version, including the ones
        // without that class. semitexa/mail called SandboxGuard while requiring
        // semitexa/core at `*`: composer accepts a lockfile with yesterday's
        // core, and every send fatals on `Class not found` — outside a sandbox
        // too. A reviewer found that; the floor check waved it through.
        //
        // The narrowest statement that is always true: if the class exists in
        // NO released version of the sibling, the constraint cannot be
        // satisfied by anything on Packagist today. That is a fact, not a
        // judgement about which versions a consumer might pick.
        foreach ($package['wildcards'] as $dependency) {
            $target = $packages[$dependency] ?? null;
            if ($target === null) {
                continue;
            }

            $latest = latestReleaseTag($target['dir']);
            if ($latest === null) {
                // Nothing released yet; there is no version to be wrong about.
                continue;
            }

            $problems = array_merge($problems, verifyAgainstTag(
                $name,
                $package['dir'],
                $dependency,
                $target,
                $latest,
                'wildcard',
            ));
        }
    }

    return $problems;
}

/**
 * Check every class one package uses from another against one tag of it.
 *
 * @param array{dir: string, psr4: array<string, list<string>>} $target
 * @param 'floor'|'pin'|'wildcard' $kind
 * @return list<string>
 */
function verifyAgainstTag(
    string $name,
    string $packageDir,
    string $dependency,
    array $target,
    string $version,
    string $kind,
): array {
    $problems = [];

    $tree = treeAtTag($target['dir'], $version);
    if ($tree === null) {
        if ($kind === 'wildcard') {
            return [];
        }

        return [sprintf(
            '%s %s %s at %s, but that tag is not in %s — fetch tags, or it names a release that does not exist',
            $name,
            $kind === 'pin' ? 'pins' : 'floors',
            $dependency,
            $version,
            basename($target['dir']),
        )];
    }

    // THE MAP MUST COME FROM THE SAME TAG AS THE TREE. Today's autoload block
    // describes today's layout; if the provider moved its sources since that
    // release, mapping a class through the current map and looking it up in the
    // OLD tree compares two different layouts.
    //
    // And when the tagged map cannot be read, the gate FAILS rather than
    // falling back to the current one — falling back is the same mixing of
    // revisions in a quieter form. Something the gate cannot see is not
    // something it approves.
    $psr4 = psr4AtTag($target['dir'], $version);
    if ($psr4 === null) {
        return [sprintf(
            '%s names %s at %s, but that tag has no readable autoload.psr-4 map — '
            . 'nothing can be verified against it',
            $name,
            $dependency,
            $version,
        )];
    }

    foreach (importsFrom($packageDir, $psr4) as $class => $candidatePaths) {
        foreach ($candidatePaths as $candidate) {
            if (in_array($candidate, $tree, true)) {
                continue 2;
            }
        }
        if (classDeclaredAtTag($target['dir'], $version, $class, $psr4)) {
            continue;
        }

        $problems[] = $kind === 'wildcard'
            ? sprintf(
                '%s uses %s but requires %s at "*", and NO released %s contains %s '
                . '(newest is %s) — floor it at the release that ships the class',
                $name,
                $class,
                $dependency,
                $dependency,
                $candidatePaths[0] ?? '(unmapped)',
                $version,
            )
            : sprintf(
                '%s uses %s but %s %s at %s, where %s does not exist',
                $name,
                $class,
                $kind === 'pin' ? 'pins' : 'floors',
                $dependency,
                $version,
                $candidatePaths[0] ?? '(unmapped)',
            );
    }

    return $problems;
}

/**
 * Whether a class is DECLARED anywhere in a package at a tag, regardless of
 * which file holds it.
 *
 * The path check above is a proxy: PSR-4 says a class lives in the file its
 * name maps to, and it almost always does. `Semitexa\Core\Tenant\Layer\ThemeValue`
 * is the exception that proves the proxy needs a second opinion — it is a
 * second class declared inside ThemeLayer.php, so no ThemeValue.php exists in
 * any release, yet the class resolves fine through the classmap. Reporting it
 * would fail every release over something that has worked for months, and a
 * gate that cries wolf gets switched off.
 *
 * Only ever called on a MISS, so the cost is one grep per finding rather than
 * one per import.
 */
function classDeclaredAtTag(string $dir, string $tag, string $fqcn, array $psr4): bool
{
    $short = $fqcn;
    $lastSeparator = strrpos($short, '\\');
    if ($lastSeparator !== false) {
        $short = substr($short, $lastSeparator + 1);
    }
    if ($short === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $short) !== 1) {
        return false;
    }

    $paths = [];
    foreach ($psr4 as $dirs) {
        foreach ($dirs as $sourceDir) {
            $paths[] = escapeshellarg($sourceDir);
        }
    }

    $command = sprintf(
        'git -C %s grep -lE %s %s%s 2>/dev/null',
        escapeshellarg($dir),
        escapeshellarg('^[a-z ]*(class|interface|trait|enum) ' . $short . '\b'),
        escapeshellarg($tag),
        $paths === [] ? '' : ' -- ' . implode(' ', $paths),
    );

    exec($command, $lines, $code);

    return $code === 0 && $lines !== [];
}

/**
 * The newest release tag in a package, or null when nothing is released.
 *
 * Date-based tags (`YYYY.MM.DD.HHMM`) sort correctly as strings, so the newest
 * is the last one. Anything that is not a release tag is ignored rather than
 * ranked.
 */
function latestReleaseTag(string $dir): ?string
{
    $command = sprintf('git -C %s tag --list 2>/dev/null', escapeshellarg($dir));
    exec($command, $lines, $code);
    if ($code !== 0) {
        return null;
    }

    $releases = [];
    foreach ($lines as $tag) {
        $tag = trim($tag);
        if (preg_match('/^\d{4}\.\d{2}\.\d{2}\.\d{4}$/', $tag) === 1) {
            $releases[] = $tag;
        }
    }

    if ($releases === []) {
        return null;
    }

    sort($releases);

    return (string) end($releases);
}

/**
 * @return array<string, array{dir: string, psr4: array<string, string>, floors: array<string, string>, wildcards: list<string>}>
 */
function indexPackages(string $packagesDir): array
{
    $packages = [];

    foreach (glob($packagesDir . '/*/composer.json') ?: [] as $composerPath) {
        $json = json_decode((string) file_get_contents($composerPath), true);
        if (!is_array($json) || !is_string($json['name'] ?? null)) {
            continue;
        }

        $promises = [];
        $wildcards = [];
        foreach ($json['require'] ?? [] as $dependency => $constraint) {
            if (!is_string($dependency) || !is_string($constraint) || !str_starts_with($dependency, 'semitexa/')) {
                continue;
            }

            $version = '\d{4}\.\d{2}\.\d{2}\.\d{4}(?:-[a-z0-9]+)?';

            // The `|| dev-master` escape is not part of the promise being
            // checked; the floor is.
            if (preg_match('/^>=\s*(' . $version . ')/i', $constraint, $m) === 1) {
                $promises[$dependency] = ['version' => $m[1], 'kind' => 'floor'];
            } elseif (preg_match('/^(' . $version . ')$/i', trim($constraint), $m) === 1) {
                $promises[$dependency] = ['version' => $m[1], 'kind' => 'pin'];
            } elseif (trim($constraint) === '*') {
                $wildcards[] = $dependency;
            }
        }

        $packages[$json['name']] = [
            'dir' => dirname($composerPath),
            'psr4' => normalizePsr4($json['autoload']['psr-4'] ?? null) ?? [],
            'promises' => $promises,
            'wildcards' => $wildcards,
        ];
    }

    return $packages;
}

/**
 * A PSR-4 block as prefix => LIST of source directories.
 *
 * Composer lets one prefix map to several directories. Keeping only
 * string-valued entries silently dropped such a prefix, and every class under
 * it then went unmapped and unchecked — a fail-open in a gate that exists to
 * fail closed.
 *
 * @return array<string, list<string>>|null null when there is no usable map
 */
function normalizePsr4(mixed $map): ?array
{
    if (!is_array($map)) {
        return null;
    }

    $psr4 = [];
    foreach ($map as $prefix => $paths) {
        if (!is_string($prefix)) {
            continue;
        }
        foreach ((array) $paths as $path) {
            if (is_string($path)) {
                $psr4[$prefix][] = rtrim($path, '/');
            }
        }
    }

    return $psr4 === [] ? null : $psr4;
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

    return normalizePsr4($json['autoload']['psr-4'] ?? null);
}

/**
 * Classes this package imports from another's namespaces, as tag-relative
 * paths.
 *
 * `use function` and `use const` are skipped: they are not class files, and a
 * floor that guarantees a class says nothing about them either way.
 *
 * @param array<string, list<string>> $psr4 namespace prefix => source directories
 * @return array<string, list<string>> FQCN => candidate paths in the target package
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

        foreach (usedClasses((string) file_get_contents($file->getPathname())) as $class) {
            foreach ($psr4 as $prefix => $dirs) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
                foreach ($dirs as $dir) {
                    $found[$class][] = $dir . '/' . $relative . '.php';
                }
                break;
            }
        }
    }

    return $found;
}

/**
 * Every class of another package this file names — imported OR written out.
 *
 * An import is not the only way to reach a sibling's class.
 * `new \Semitexa\Core\Support\Row()` names it just as surely, and this
 * workspace has 140 distinct cross-package references written that way. A scan
 * that only entered on `use` was blind to every one of them, so a class added
 * after the floored release could be called from a fully-qualified reference
 * and the gate would still report success.
 *
 * @return list<string>
 */
function usedClasses(string $contents): array
{
    $classes = [];

    foreach (importedClasses($contents) as $class) {
        $classes[$class] = true;
    }

    foreach (PhpToken::tokenize($contents) as $token) {
        // T_NAME_FULLY_QUALIFIED is `\Foo\Bar` wherever it appears — a `new`,
        // a static call, a type, an attribute, a catch. Relative names are not
        // collected: they resolve through the file's own imports, which the
        // import scan already has.
        if (!$token->is(T_NAME_FULLY_QUALIFIED)) {
            continue;
        }

        $name = ltrim($token->text, '\\');
        if ($name !== '' && str_contains($name, '\\')) {
            $classes[$name] = true;
        }
    }

    return array_keys($classes);
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
 * The alias is dropped at TOKEN level, on T_AS. Doing it by string surgery is
 * its own trap: searching the joined text for "as" turns
 * `use Semitexa\Orm\Metadata\HasColumnReferences;` into
 * `Semitexa\Orm\Metadata\H`, and the gate then fails releases over a file
 * nobody ever imported. Measured, in this repository, on six packages.
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
    $namespaceBraceDepths = [];
    $inNamespaceDeclaration = false;

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];

        // A BRACED namespace — `namespace Semitexa\Ssr { use ...; }` — opens a
        // brace that is not a class body, and counting it would skip every
        // genuine import inside. Its depth is remembered so the matching `}`
        // closes it rather than looking like a class ending.
        if ($token->is(T_NAMESPACE)) {
            $inNamespaceDeclaration = true;
            continue;
        }
        if ($inNamespaceDeclaration && ($token->text === ';' || $token->text === '{')) {
            if ($token->text === '{') {
                $depth++;
                $namespaceBraceDepths[$depth] = true;
            }
            $inNamespaceDeclaration = false;
            continue;
        }

        // Trait `use` lives inside a class body; namespace-level `use` does not.
        if ($token->text === '{') {
            $depth++;
            continue;
        }
        if ($token->text === '}') {
            unset($namespaceBraceDepths[$depth]);
            $depth--;
            continue;
        }

        $classBodyDepth = $depth - count($namespaceBraceDepths);
        if (!$token->is(T_USE) || $classBodyDepth > 0) {
            continue;
        }

        $prefix = '';
        $current = '';
        $skippingAlias = false;
        $skippingItem = false;
        $isClassImport = null;
        $found = [];

        $flush = static function () use (&$current, &$prefix, &$found): void {
            $name = ltrim($prefix . $current, '\\');
            if ($name !== '') {
                $found[] = $name;
            }
            $current = '';
        };

        $j = $i + 1;
        for (; $j < $n; $j++) {
            $piece = $tokens[$j];
            $text = $piece->text;

            if ($text === ';') {
                break;
            }
            if ($piece->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            if ($isClassImport === null) {
                // The first meaningful token decides what kind of `use` this is.
                // `(` is a closure capture, which imports no class at all.
                $isClassImport = !$piece->is([T_FUNCTION, T_CONST]) && $text !== '(';
                if ($isClassImport === false) {
                    break;
                }
            }

            if ($piece->is(T_AS)) {
                $skippingAlias = true;
                continue;
            }
            if ($piece->is([T_FUNCTION, T_CONST])) {
                // `use Foo\{Bar, function helper};` — the kind may also appear
                // per ITEM inside a group, not only before the whole statement.
                $skippingItem = true;
                continue;
            }
            if ($text === ',') {
                $skippingAlias = false;
                if ($skippingItem) {
                    $skippingItem = false;
                    $current = '';
                    continue;
                }
                $flush();
                continue;
            }
            if ($text === '{') {
                $prefix = $current;
                $current = '';
                continue;
            }
            if ($text === '}') {
                $skippingAlias = false;
                if ($skippingItem) {
                    $skippingItem = false;
                    $current = '';
                } else {
                    $flush();
                }
                $prefix = '';
                continue;
            }
            if ($skippingAlias || $skippingItem) {
                continue;
            }

            $current .= $text;
        }

        $i = $j;

        if ($isClassImport !== true) {
            continue;
        }

        if ($skippingItem) {
            $current = '';
        }
        $flush();

        foreach ($found as $class) {
            $classes[$class] = true;
        }
    }

    return array_keys($classes);
}


#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Which packages should be looking at their floors, because a dependency grew.
 *
 * ## The gap this fills
 *
 * `release-check-internal-constraints.php` asks whether a promised release
 * DECLARES every sibling CLASS a package imports. That catches a moved or
 * renamed class and misses the commonest case entirely: a new PUBLIC METHOD on
 * a class that has existed for months.
 *
 *   MEASURED 2026-09-18 — semitexa/ssr started calling
 *   Semitexa\Core\Request::getServedPath(). Request has been in every release
 *   since forever, so the gate was satisfied while ssr's floor still named a
 *   core that has no such method. A consumer installing that pair gets
 *   "Call to undefined method" on the first shell request. Nothing but a human
 *   remembering stood between that and a release.
 *
 * So this reports, per package being tagged, the public methods and constants
 * its classes gained since its previous release, and names the packages that
 * import those classes. It is reflection over two git revisions — no taint
 * analysis, no call-graph — and its output is a question for a person, not a
 * verdict: "core grew these; ssr, orm and dev use these classes; do their floors
 * need to move?"
 *
 * A package that declares `extra.semitexa.floors: {"<provider>": "next"}` has
 * already answered for that provider, and is left out of the question.
 *
 * ## Usage
 *
 *   php release-new-public-api.php [--json]
 *
 * RELEASE_ROOT selects the tree. Exit code is 0 whatever it finds: this is a
 * report, and a release that stops on it would stop on every ordinary feature.
 */

$args = array_slice($argv, 1);
$asJson = in_array('--json', $args, true);

$releaseRoot = rtrim(getenv('RELEASE_ROOT') ?: '/home/taras/Documents/Projects/semitexa.rls', DIRECTORY_SEPARATOR);
$packagesDir = $releaseRoot . '/packages';

if (!is_dir($packagesDir)) {
    fwrite(STDERR, "Packages directory not found: {$packagesDir}\n");
    exit(1);
}

$report = [];

foreach (glob($packagesDir . '/*') ?: [] as $packageDir) {
    if (!is_dir($packageDir . '/.git') || !is_file($packageDir . '/composer.json')) {
        continue;
    }

    $name = packageName($packageDir);
    $previousTag = latestTag($packageDir);
    if ($previousTag === null) {
        continue; // never released: everything in it is new, which is not news
    }

    $grown = grownSymbols($packageDir, $previousTag);
    if ($grown === []) {
        continue;
    }

    $dependents = dependentsUsing($packagesDir, $name, array_keys($grown));
    if ($dependents === []) {
        continue;
    }

    $report[] = [
        'package' => $name,
        'since' => $previousTag,
        'grown' => $grown,
        'dependents' => $dependents,
    ];
}

if ($asJson) {
    echo json_encode(['artifact' => 'semitexa.release-new-public-api/v1', 'findings' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

if ($report === []) {
    echo "[OK] No package in this set grew public API that another package's classes touch.\n";
    exit(0);
}

echo "Public API added since the last release, and who might need a floor for it:\n\n";
foreach ($report as $finding) {
    printf("%s (since %s)\n", $finding['package'], $finding['since']);
    foreach ($finding['grown'] as $class => $members) {
        printf("  %s\n", $class);
        foreach ($members as $member) {
            printf("    + %s\n", $member);
        }
    }
    echo "  touched by:\n";
    foreach ($finding['dependents'] as $dependent => $classes) {
        printf("    %-28s %s\n", $dependent, implode(', ', array_map('shortName', $classes)));
    }
    printf(
        "  -> for each of those that CALLS one of the new members, declare it there:\n"
        . "     \"extra\": { \"semitexa\": { \"floors\": { \"%s\": \"next\" } } }\n\n",
        $finding['package'],
    );
}

echo "This is a question, not a verdict: a package can use a class for years without touching what\n";
echo "was added to it. Answer it per dependent, then run release-resolve-floors.php.\n";
exit(0);

/** The class name without its namespace, for a report a person has to scan. */
function shortName(string $fqcn): string
{
    $position = strrpos($fqcn, '\\');

    return $position === false ? $fqcn : substr($fqcn, $position + 1);
}

function packageName(string $packageDir): string
{
    $json = json_decode((string) file_get_contents($packageDir . '/composer.json'), true);

    return is_array($json) && is_string($json['name'] ?? null) ? $json['name'] : basename($packageDir);
}

/** The newest release tag, by creation date, or null when the package has none. */
function latestTag(string $packageDir): ?string
{
    $out = shell_exec(sprintf(
        'git -C %s tag --sort=-creatordate --list %s 2>/dev/null',
        escapeshellarg($packageDir),
        escapeshellarg('20*'),
    ));

    $tags = array_values(array_filter(array_map('trim', explode("\n", (string) $out))));

    return $tags[0] ?? null;
}

/**
 * Public methods and constants each class gained between $tag and the working
 * tree, keyed by FQCN.
 *
 * Read from the SOURCE TEXT of both revisions rather than by loading the class:
 * loading two versions of one class in a process is impossible, and a release
 * script may not autoload a tree it is about to tag.
 *
 * @return array<string, list<string>>
 */
function grownSymbols(string $packageDir, string $tag): array
{
    $grown = [];

    $changed = shell_exec(sprintf(
        'git -C %s diff --name-only %s -- src 2>/dev/null',
        escapeshellarg($packageDir),
        escapeshellarg($tag),
    ));

    foreach (array_filter(array_map('trim', explode("\n", (string) $changed))) as $relative) {
        if (!str_ends_with($relative, '.php')) {
            continue;
        }

        $now = @file_get_contents($packageDir . '/' . $relative);
        if ($now === false) {
            continue; // deleted; nothing gained
        }

        $before = shell_exec(sprintf(
            'git -C %s show %s:%s 2>/dev/null',
            escapeshellarg($packageDir),
            escapeshellarg($tag),
            escapeshellarg($relative),
        ));

        $added = array_values(array_diff(publicMembers((string) $now), publicMembers((string) $before)));
        if ($added === []) {
            continue;
        }

        $fqcn = fqcnIn((string) $now);
        if ($fqcn === null) {
            continue;
        }

        $grown[$fqcn] = $added;
    }

    ksort($grown);

    return $grown;
}

/**
 * The public surface of one file, as text.
 *
 * Deliberately crude: a public method or constant named in the source. A private
 * one cannot be what a sibling package calls, and this is the whole reason the
 * report exists rather than a stricter analysis nobody would finish.
 *
 * @return list<string>
 */
function publicMembers(string $source): array
{
    $members = [];

    if (preg_match_all('/^\s*(?:final\s+|abstract\s+)*public\s+(?:static\s+)?function\s+(\w+)/mi', $source, $m) > 0) {
        foreach ($m[1] as $name) {
            $members[] = $name . '()';
        }
    }

    if (preg_match_all('/^\s*(?:final\s+)?public\s+const\s+(?:[\w\\\\|?]+\s+)?(\w+)/mi', $source, $m) > 0) {
        foreach ($m[1] as $name) {
            $members[] = $name;
        }
    }

    sort($members);

    return array_values(array_unique($members));
}

function fqcnIn(string $source): ?string
{
    if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1) {
        return null;
    }

    if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $c) !== 1) {
        return null;
    }

    return trim($ns[1]) . '\\' . $c[1];
}

/**
 * Packages whose source mentions any of these classes, minus the ones that have
 * already declared a floor on the provider.
 *
 * @param list<string> $classes
 * @return array<string, list<string>> package name => the classes it mentions
 */
function dependentsUsing(string $packagesDir, string $provider, array $classes): array
{
    $dependents = [];

    foreach (glob($packagesDir . '/*') ?: [] as $packageDir) {
        if (!is_dir($packageDir . '/src') || !is_file($packageDir . '/composer.json')) {
            continue;
        }

        $name = packageName($packageDir);
        if ($name === $provider) {
            continue;
        }

        $json = json_decode((string) file_get_contents($packageDir . '/composer.json'), true);
        if (!is_array($json) || !isset($json['require'][$provider])) {
            continue;
        }

        // Already answered for this provider.
        if (($json['extra']['semitexa']['floors'][$provider] ?? null) !== null) {
            continue;
        }

        $mentions = [];
        foreach ($classes as $class) {
            $found = shell_exec(sprintf(
                'grep -rlF %s %s 2>/dev/null | head -1',
                escapeshellarg($class),
                escapeshellarg($packageDir . '/src'),
            ));

            if (trim((string) $found) !== '') {
                $mentions[] = $class;
            }
        }

        if ($mentions !== []) {
            $dependents[$name] = $mentions;
        }
    }

    ksort($dependents);

    return $dependents;
}

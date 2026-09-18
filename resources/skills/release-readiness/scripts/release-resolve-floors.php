#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Turn a DECLARED floor into a DATED one, at the moment the date exists.
 *
 * ## The problem this removes
 *
 * A floor says "this package needs at least that release of its dependency",
 * and until the tag exists the release it names is a guess. Authors were
 * writing the date by hand, during review, for a cut that had not happened:
 *
 *   MEASURED 2026-09-16 — semitexa/os floored semitexa/prompt at 2026.09.15.1403,
 *   the date the author expected. Review ran a day past it, no release happened
 *   that day, and the next preflight died on check-internal-constraints with
 *   "that tag is not in semitexa-prompt". Nothing was wrong with the code or the
 *   reasoning; only the date was stale, and slipping is the normal case when the
 *   floor is written during review.
 *
 * So the author stops writing dates. They write WHICH dependency needs a floor:
 *
 *   "extra": { "semitexa": { "floors": { "semitexa/core": "next" } } }
 *
 * and `require` keeps whatever it had — `*` until this script runs. That matters:
 * a sentinel inside the constraint string itself ("`>=NEXT`") is not a constraint
 * composer can parse, so the workspace and the release clone would be
 * uninstallable between the declaration and the release. `extra` is free-form by
 * design and composer ignores it.
 *
 * At the cut, with RELEASE_VERSION known, this writes
 *
 *   "semitexa/core": ">=<RELEASE_VERSION> || dev-master"
 *
 * into `require` and records the same version back in `extra`, so the fact is
 * durable and a later release re-reading the file finds nothing to do. `next`
 * means "the release being cut now"; once cut, it is a date like any other.
 *
 * ## Why `|| dev-master` is not optional
 *
 * Packages are developed as PATH REPOSITORIES, where composer takes the version
 * from the git branch — `dev-master` — and a branch version satisfies no date
 * floor. The path repo is canonical, so composer cannot fall back to Packagist:
 * it reports the whole set as uninstallable. A floor without the escape is a
 * floor that makes the workspace unbuildable while protecting a consumer who was
 * never at risk from the local checkout.
 *
 * ## What it refuses
 *
 * A `next` naming a provider that is NOT being tagged in this cut. If the
 * provider is not changing, it cannot have grown the API the floor is for, so
 * the declaration is an authoring mistake and gets said out loud rather than
 * resolved to a version that means nothing.
 *
 * ## Usage
 *
 *   php release-resolve-floors.php --check      # report, change nothing (preflight)
 *   php release-resolve-floors.php --confirm    # write the resolved floors
 *
 * RELEASE_ROOT selects the tree (default: the release clone). RELEASE_VERSION is
 * required for --confirm and for a meaningful --check.
 */

const SENTINEL = 'next';

$args = array_slice($argv, 1);
$confirm = in_array('--confirm', $args, true);
$check = in_array('--check', $args, true) || !$confirm;

$releaseRoot = rtrim(getenv('RELEASE_ROOT') ?: '/home/taras/Documents/Projects/semitexa.rls', DIRECTORY_SEPARATOR);
$packagesDir = $releaseRoot . '/packages';

if (!is_dir($packagesDir)) {
    fwrite(STDERR, "Packages directory not found: {$packagesDir}\n");
    exit(1);
}

$releaseVersion = trim((string) (getenv('RELEASE_VERSION') ?: ''));

/** @var list<string> $releaseSet packages being tagged in this cut, '' means "every package" */
$releaseSetRaw = trim((string) (getenv('RELEASE_SET') ?: ''));
$releaseSet = $releaseSetRaw === ''
    ? null
    : array_values(array_filter(array_map('trim', explode(',', $releaseSetRaw))));

$declarations = collectDeclarations($packagesDir);
$pending = array_values(array_filter($declarations, static fn (array $d): bool => $d['declared'] === SENTINEL));

if ($pending === []) {
    echo "[OK] No floor is waiting for a version.\n";
    reportResolved($declarations);
    exit(0);
}

if ($releaseVersion === '' || preg_match('/^\d{4}\.\d{2}\.\d{2}\.\d{4}$/', $releaseVersion) !== 1) {
    fwrite(STDERR, "These floors are declared but not dated, and RELEASE_VERSION is not set:\n");
    foreach ($pending as $d) {
        fwrite(STDERR, "- {$d['package']} floors {$d['dependency']} at the release being cut\n");
    }
    fwrite(
        STDERR,
        "\nThat is the normal state on develop. Set RELEASE_VERSION (UTC YYYY.MM.DD.HHMM) and run this\n"
        . "with --confirm as part of the cut, before the packages are tagged.\n"
    );
    exit($check ? 1 : 1);
}

$problems = [];
foreach ($pending as $d) {
    if ($releaseSet !== null && !in_array($d['dependency'], $releaseSet, true)) {
        $problems[] = sprintf(
            '%s floors %s at this release, but %s is not being tagged in it. A dependency that is not '
            . 'changing cannot have grown the API the floor is for — check whether the floor belongs on a '
            . 'different package, or whether the dependency should be in the release set.',
            $d['package'],
            $d['dependency'],
            $d['dependency'],
        );
    }
}

if ($problems !== []) {
    fwrite(STDERR, "Floor declarations that cannot be resolved:\n");
    foreach ($problems as $problem) {
        fwrite(STDERR, "- {$problem}\n");
    }
    exit(1);
}

if ($check) {
    fwrite(STDERR, "These floors still name the release rather than a version:\n");
    foreach ($pending as $d) {
        fwrite(STDERR, sprintf("- %s: %s -> >=%s || dev-master\n", $d['package'], $d['dependency'], $releaseVersion));
    }
    fwrite(STDERR, "\nRun this script with --confirm before tagging, or the packages ship a floor that says nothing.\n");
    exit(1);
}

foreach ($pending as $d) {
    writeResolvedFloor($d['composer_path'], $d['dependency'], $releaseVersion);
    printf("  %s: %s >=%s || dev-master\n", $d['package'], $d['dependency'], $releaseVersion);
}

printf("[OK] Dated %d floor declaration(s) at %s.\n", count($pending), $releaseVersion);
exit(0);

/**
 * Every `extra.semitexa.floors` entry in the tree.
 *
 * @return list<array{package: string, composer_path: string, dependency: string, declared: string, require: string}>
 */
function collectDeclarations(string $packagesDir): array
{
    $out = [];

    foreach (glob($packagesDir . '/*/composer.json') ?: [] as $composerPath) {
        $json = json_decode((string) file_get_contents($composerPath), true);
        if (!is_array($json)) {
            continue;
        }

        $floors = $json['extra']['semitexa']['floors'] ?? null;
        if (!is_array($floors)) {
            continue;
        }

        $packageName = (string) ($json['name'] ?? basename(dirname($composerPath)));

        foreach ($floors as $dependency => $declared) {
            if (!is_string($dependency) || !is_string($declared)) {
                continue;
            }

            $out[] = [
                'package' => $packageName,
                'composer_path' => $composerPath,
                'dependency' => $dependency,
                'declared' => $declared,
                'require' => (string) ($json['require'][$dependency] ?? ''),
            ];
        }
    }

    return $out;
}

/**
 * @param list<array{package: string, dependency: string, declared: string, require: string}> $declarations
 */
function reportResolved(array $declarations): void
{
    foreach ($declarations as $d) {
        $expected = floorConstraint($d['declared']);
        if ($d['require'] !== $expected) {
            printf(
                "  note: %s declares a floor on %s at %s, and require says \"%s\"\n",
                $d['package'],
                $d['dependency'],
                $d['declared'],
                $d['require'],
            );
        }
    }
}

function floorConstraint(string $version): string
{
    return '>=' . $version . ' || dev-master';
}

/**
 * Write the dated floor into `require`, and record the date in `extra` so the
 * declaration stops asking. Only these two keys are touched.
 */
function writeResolvedFloor(string $composerPath, string $dependency, string $version): void
{
    $json = json_decode((string) file_get_contents($composerPath), true);
    if (!is_array($json)) {
        throw new RuntimeException("Invalid JSON: {$composerPath}");
    }

    $json['require'][$dependency] = floorConstraint($version);
    $json['extra']['semitexa']['floors'][$dependency] = $version;

    file_put_contents($composerPath, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

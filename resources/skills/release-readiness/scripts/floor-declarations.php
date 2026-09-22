<?php

declare(strict_types=1);

/**
 * What a declared internal floor is, and how it gets its date.
 *
 * Two scripts act on a declaration: release-resolve-floors.php, which an
 * operator runs and preflight runs as --check, and bump-packages.php, which
 * dates the floors itself at the cut, right before it tags. They share this file
 * so they cannot disagree about the sentinel, the constraint written, or which
 * declarations a cut is allowed to date.
 *
 * An author writes WHICH dependency needs a floor:
 *
 *   "extra": { "semitexa": { "floors": { "semitexa/core": "next" } } }
 *
 * and the release writes WHEN: `>=<RELEASE_VERSION> || dev-master` into
 * `require`, with the same version recorded back in `extra`.
 */

const FLOOR_SENTINEL = 'next';

function floorConstraint(string $version): string
{
    return '>=' . $version . ' || dev-master';
}

/**
 * The dependencies this manifest declares a floor on and has not dated yet.
 *
 * @param array<mixed> $composer
 * @return list<string>
 */
function undatedFloorDependencies(array $composer): array
{
    $floors = $composer['extra']['semitexa']['floors'] ?? null;
    if (!is_array($floors)) {
        return [];
    }

    $undated = [];
    foreach ($floors as $dependency => $declared) {
        if (is_string($dependency) && $declared === FLOOR_SENTINEL) {
            $undated[] = $dependency;
        }
    }

    return $undated;
}

/**
 * Why this cut cannot date `$package`'s floor on `$dependency`, or null when it can.
 *
 * `next` means "the release being cut now". A dependency that is not being
 * tagged in it is not changing, so it cannot have grown the API the floor is
 * for: the declaration is an authoring mistake, and dating it would write a
 * version that means nothing.
 *
 * @param list<string> $releaseSet
 */
function whyFloorCannotBeDated(string $package, string $dependency, array $releaseSet): ?string
{
    if (in_array($dependency, $releaseSet, true)) {
        return null;
    }

    return sprintf(
        '%s floors %s at this release, but %s is not being tagged in it. A dependency that is not '
        . 'changing cannot have grown the API the floor is for — check whether the floor belongs on a '
        . 'different package, or whether the dependency should be in the release set.',
        $package,
        $dependency,
        $dependency,
    );
}

/**
 * Write the dated floor into `require`, and record the date in `extra` so the
 * declaration stops asking. Only these two keys are touched.
 */
function writeResolvedFloor(string $composerPath, string $dependency, string $version): void
{
    $raw = file_get_contents($composerPath);
    if ($raw === false) {
        fwrite(STDERR, "Cannot read {$composerPath}\n");
        exit(1);
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        fwrite(STDERR, "Invalid JSON: {$composerPath}\n");
        exit(1);
    }

    $json['require'][$dependency] = floorConstraint($version);
    $json['extra']['semitexa']['floors'][$dependency] = $version;

    $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        fwrite(STDERR, "Cannot encode {$composerPath}\n");
        exit(1);
    }

    // A failed write that is reported as a dated floor is the worst outcome
    // available here: the operator reads "[OK] Dated 1" and tags a tree that
    // still carries the old constraint.
    if (file_put_contents($composerPath, $encoded . PHP_EOL) === false) {
        fwrite(STDERR, "Cannot write {$composerPath} — the floor is NOT dated.\n");
        exit(1);
    }
}

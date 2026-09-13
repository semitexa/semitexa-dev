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

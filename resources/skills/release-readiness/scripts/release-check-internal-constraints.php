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
 * Three forms, and each says something different.
 *
 *   *                     this package works with any release of that one
 *   2026.09.13.0749       exactly this release (what ultimate's pins are)
 *   >=2026.09.13.0749     a FLOOR: the release where the API it calls appeared
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

    return preg_match('/^(?:>=\s*)?' . $version . '$/i', $constraint) === 1;
}

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
 *   php release-resolve-floors.php --check              # report, change nothing (preflight)
 *   php release-resolve-floors.php --confirm            # write the resolved floors
 *   php release-resolve-floors.php --confirm --commit   # write, commit and push them to master
 *
 * ## Why --commit exists, and why writing alone is not enough
 *
 * bump-packages.php tags each package after `git reset --hard origin/master`.
 * A floor written into the clone and left uncommitted is therefore DISCARDED
 * before the tag is made — the release would ship the old constraint while the
 * operator had watched the new one being written. A floor that is not in the
 * tagged tree is not in the release, so the resolved file has to reach
 * origin/master before tagging, and --commit is that step.
 *
 * RELEASE_ROOT selects the tree (default: the release clone). RELEASE_VERSION is
 * required for --confirm and for a meaningful --check.
 */

const SENTINEL = 'next';

$args = array_slice($argv, 1);
$confirm = in_array('--confirm', $args, true);
$commit = in_array('--commit', $args, true);
$check = in_array('--check', $args, true) || !$confirm;

$releaseRoot = rtrim(getenv('RELEASE_ROOT') ?: '/home/taras/Documents/Projects/semitexa.rls', DIRECTORY_SEPARATOR);
$packagesDir = $releaseRoot . '/packages';

if (!is_dir($packagesDir)) {
    fwrite(STDERR, "Packages directory not found: {$packagesDir}\n");
    exit(1);
}

$releaseVersion = trim((string) (getenv('RELEASE_VERSION') ?: ''));

// WHICH PACKAGES THIS CUT IS TAGGING, derived rather than trusted to an
// environment variable nothing sets. RELEASE_SET used to be the only source and
// no release script passed it, so the empty value meant "assume everything is
// being tagged" — the refusal below could never fire, which is worse than not
// promising it. It is kept as an explicit override, for tests and for a release
// that knows better than the derivation.
$releaseSetRaw = trim((string) (getenv('RELEASE_SET') ?: ''));
$releaseSet = $releaseSetRaw !== ''
    ? array_values(array_filter(array_map('trim', explode(',', $releaseSetRaw))))
    : deriveReleaseSet($packagesDir);

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
if ($releaseSet === null) {
    fwrite(
        STDERR,
        "Cannot tell which packages this release is tagging: the package directories are not git\n"
        . "checkouts, and RELEASE_SET was not passed. Refusing to date a floor without knowing whether\n"
        . "the dependency it names is even being released — that is the one check this script owes.\n"
    );
    exit(1);
}

foreach ($pending as $d) {
    if (!in_array($d['dependency'], $releaseSet, true)) {
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
    fwrite(
        STDERR,
        "\nThe sequence, and the order matters:\n"
        . "  1. php release-resolve-floors.php --confirm --commit\n"
        . "     (writes the floor, commits it on master and pushes — the tagger resets to origin/master,\n"
        . "      so an uncommitted edit is discarded before the tag)\n"
        . "  2. then tag, via the normal finalize path\n"
    );
    exit(1);
}

$touchedPackages = [];
foreach ($pending as $d) {
    writeResolvedFloor($d['composer_path'], $d['dependency'], $releaseVersion);
    printf("  %s: %s >=%s || dev-master\n", $d['package'], $d['dependency'], $releaseVersion);
    $touchedPackages[dirname($d['composer_path'])] = true;
}

printf("[OK] Dated %d floor declaration(s) at %s.\n", count($pending), $releaseVersion);

if (!$commit) {
    fwrite(
        STDERR,
        "\nNOT COMMITTED. bump-packages.php tags each package after `git reset --hard origin/master`,\n"
        . "so this edit is discarded before the tag unless it reaches origin/master first. Re-run with\n"
        . "--confirm --commit, or commit and push these files yourself, BEFORE tagging.\n"
    );
    exit(0);
}

foreach (array_keys($touchedPackages) as $packageDir) {
    commitAndPush($packageDir, $releaseVersion);
}

exit(0);

/**
 * Put the resolved floor where the tagger will see it.
 *
 * On master, because that is the branch the tag is cut from, and pushed, because
 * the tagger resets to origin/master and a local commit would go with it.
 */
function commitAndPush(string $packageDir, string $releaseVersion): void
{
    $run = static function (string $command) use ($packageDir): array {
        $output = [];
        $exit = 0;
        exec(sprintf('git -C %s %s 2>&1', escapeshellarg($packageDir), $command), $output, $exit);

        return ['exit' => $exit, 'output' => implode("\n", $output)];
    };

    $branch = trim((string) shell_exec(sprintf(
        'git -C %s rev-parse --abbrev-ref HEAD 2>/dev/null',
        escapeshellarg($packageDir),
    )));

    if ($branch !== 'master') {
        fwrite(STDERR, sprintf(
            "Refusing to commit the resolved floor in %s: HEAD is on \"%s\", not master. The tag is cut\n"
            . "from master, so a commit anywhere else is not in the release.\n",
            basename($packageDir),
            $branch,
        ));
        exit(1);
    }

    $add = $run('add -- composer.json');
    if ($add['exit'] !== 0) {
        fwrite(STDERR, "git add failed in " . basename($packageDir) . ": " . $add['output'] . "\n");
        exit(1);
    }

    $commitResult = $run(sprintf(
        'commit -m %s',
        escapeshellarg('Date the declared internal floors at ' . $releaseVersion),
    ));
    if ($commitResult['exit'] !== 0) {
        fwrite(STDERR, "git commit failed in " . basename($packageDir) . ": " . $commitResult['output'] . "\n");
        exit(1);
    }

    $push = $run('push origin HEAD:master');
    if ($push['exit'] !== 0) {
        fwrite(STDERR, "git push failed in " . basename($packageDir) . ": " . $push['output'] . "\n");
        exit(1);
    }

    printf("  committed and pushed %s\n", basename($packageDir));
}

/**
 * The packages this cut will tag: the ones whose master HEAD carries no release
 * tag yet.
 *
 * The same rule bump-packages.php uses to decide whether a package needs
 * releasing at all (`head_has_release_tag`), so the two cannot disagree about
 * what "being released" means. Null when the question cannot be answered — a
 * tree of plain directories rather than checkouts — and the caller refuses
 * rather than guessing.
 *
 * @return list<string>|null
 */
function deriveReleaseSet(string $packagesDir): ?array
{
    $set = [];
    $sawGit = false;

    foreach (glob($packagesDir . '/*') ?: [] as $packageDir) {
        if (!is_file($packageDir . '/composer.json')) {
            continue;
        }

        if (!is_dir($packageDir . '/.git')) {
            continue;
        }

        $sawGit = true;

        $tagsOnHead = shell_exec(sprintf(
            'git -C %s tag --points-at HEAD --list %s 2>/dev/null',
            escapeshellarg($packageDir),
            escapeshellarg('20*'),
        ));

        if (trim((string) $tagsOnHead) !== '') {
            continue; // already released at this commit
        }

        $json = json_decode((string) file_get_contents($packageDir . '/composer.json'), true);
        $name = is_array($json) && is_string($json['name'] ?? null) ? $json['name'] : basename($packageDir);
        $set[] = $name;
    }

    return $sawGit ? $set : null;
}

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

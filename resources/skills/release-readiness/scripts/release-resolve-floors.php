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
 * At a normal cut nobody runs it: bump-packages.php dates the floors of the
 * packages it is about to tag, through the same functions (floor-declarations.php),
 * commits them on develop, fast-forwards master and only then tags. --commit is
 * for a cut tagged by hand.
 *
 * RELEASE_ROOT selects the tree (default: the release clone). RELEASE_VERSION is
 * required for --confirm and for a meaningful --check.
 */

require_once __DIR__ . '/floor-declarations.php';

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
$pending = array_values(array_filter($declarations, static fn (array $d): bool => $d['declared'] === FLOOR_SENTINEL));

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
    $problem = whyFloorCannotBeDated($d['package'], $d['dependency'], $releaseSet);
    if ($problem !== null) {
        $problems[] = $problem;
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
        "\nThat is expected before the cut: finalize dates them itself. bump-packages.php commits each\n"
        . "floor on develop, fast-forwards master to it and pushes both BEFORE it tags, and refuses to tag\n"
        . "a tree that still says `next`. Run --confirm --commit by hand only for a cut you tag by hand.\n"
    );
    exit(1);
}

// EVERY REPOSITORY IS CHECKED BEFORE ANY MANIFEST IS WRITTEN. The writes happen
// per package and the pushes happen after them, so a branch or cleanliness
// problem discovered halfway leaves earlier packages pushed and later ones dated
// locally — and finalize resets those away, silently, taking the floor with
// them. Refusing before the first write is the only ordering where a failure
// leaves nothing behind.
if ($commit) {
    $blockers = [];
    foreach (packageDirsOf($pending) as $packageDir) {
        $blocker = whyNotCommittable($packageDir);
        if ($blocker !== null) {
            $blockers[] = $blocker;
        }
    }

    if ($blockers !== []) {
        fwrite(STDERR, "Refusing to date any floor — these repositories cannot take the commit:\n");
        foreach ($blockers as $blocker) {
            fwrite(STDERR, "- {$blocker}\n");
        }
        exit(1);
    }
}

$applied = 0;
foreach ($pending as $d) {
    $packageDir = dirname($d['composer_path']);
    $before = file_get_contents($d['composer_path']);

    writeResolvedFloor($d['composer_path'], $d['dependency'], $releaseVersion);
    printf("  %s: %s >=%s || dev-master\n", $d['package'], $d['dependency'], $releaseVersion);
    $applied++;

    if (!$commit) {
        continue;
    }

    // Committed and pushed one package at a time, and RESTORED on failure. A
    // dated-but-unpushed manifest is the worst state to leave: a retry finds no
    // `next` to resolve, reports success, and finalize then resets the file away
    // — so the release ships the old constraint with nothing left to show why.
    if (!commitAndPush($packageDir, $releaseVersion)) {
        if (is_string($before)) {
            file_put_contents($d['composer_path'], $before);
            fwrite(STDERR, "Restored {$d['composer_path']} so the declaration stays pending for a retry.\n");
        }
        exit(1);
    }
}

printf("[OK] Dated %d floor declaration(s) at %s.\n", $applied, $releaseVersion);

if (!$commit) {
    fwrite(
        STDERR,
        "\nNOT COMMITTED. bump-packages.php tags each package after `git reset --hard origin/master`,\n"
        . "so this edit is discarded before the tag unless it reaches origin/master first. Re-run with\n"
        . "--confirm --commit, or commit and push these files yourself, BEFORE tagging.\n"
    );
}

exit(0);

/**
 * Put the resolved floor where the tagger will see it.
 *
 * On master, because that is the branch the tag is cut from, and pushed, because
 * the tagger resets to origin/master and a local commit would go with it.
 */
function commitAndPush(string $packageDir, string $releaseVersion): bool
{
    // ONLY composer.json, by path. `git commit` with no path commits the index,
    // so anything another process had staged in that repository would ride to
    // master on the back of a floor. The pathspec form ignores the index
    // entirely and commits exactly the file this script wrote.
    $commit = runGitIn($packageDir, sprintf(
        'commit -m %s -- composer.json',
        escapeshellarg('Date the declared internal floors at ' . $releaseVersion),
    ));

    if ($commit['exit'] !== 0) {
        fwrite(STDERR, 'git commit failed in ' . basename($packageDir) . ': ' . $commit['output'] . "\n");

        return false;
    }

    // THE COMMIT THIS INVOCATION MADE, by sha. The rollback below undoes one
    // commit, and "one commit" is only safe to name relative to something that
    // was true when it was created.
    $made = runGitIn($packageDir, 'rev-parse HEAD');
    $madeSha = $made['exit'] === 0 ? trim($made['output']) : '';

    $push = runGitIn($packageDir, 'push origin HEAD:master');
    if ($push['exit'] !== 0) {
        fwrite(STDERR, 'git push failed in ' . basename($packageDir) . ': ' . $push['output'] . "\n");
        // The commit is local and the manifest is about to be restored, so undo
        // it too — otherwise a retry sees a clean tree with a floor already
        // committed and nothing to push it.
        undoLocalCommit($packageDir, $madeSha);

        return false;
    }

    printf("  committed and pushed %s\n", basename($packageDir));

    return true;
}

/**
 * Take back the local floor commit, and nothing else.
 *
 * `reset --hard HEAD~1` was the obvious undo and the wrong one. It resets every
 * tracked path, so a file some other process touched between the cleanliness
 * check and the failed push is destroyed by a rollback whose whole purpose was
 * to leave the repository as it was found. `--soft` moves the branch pointer and
 * touches neither index nor worktree; the one index entry the commit did move —
 * composer.json, which `git commit -- <path>` stages as it commits — is put back
 * by hand, and the caller restores the file itself straight afterwards.
 *
 * It fires only while HEAD is still the commit this invocation made. If it is
 * not, "one commit back" names somebody else's work, and undoing that is worse
 * than leaving a floor commit behind with a sentence explaining it. Every step
 * is checked: a rollback that quietly failed is the state this function exists
 * to prevent.
 */
function undoLocalCommit(string $packageDir, string $madeSha): void
{
    $name = basename($packageDir);
    $head = runGitIn($packageDir, 'rev-parse HEAD');
    $headSha = $head['exit'] === 0 ? trim($head['output']) : '';

    if ($madeSha === '' || $headSha !== $madeSha) {
        fwrite(STDERR, sprintf(
            "NOT undoing the floor commit in %s: HEAD is no longer the commit this script made.\n"
            . "Undo it by hand before retrying — a retry otherwise finds a clean tree with the floor\n"
            . "already committed and nothing left to push it.\n",
            $name,
        ));

        return;
    }

    $reset = runGitIn($packageDir, 'reset --soft HEAD~1');
    if ($reset['exit'] !== 0) {
        fwrite(STDERR, sprintf(
            "Could not undo the floor commit in %s (%s). It is still on the local master and unpushed;\n"
            . "undo it by hand before retrying.\n",
            $name,
            $reset['output'],
        ));

        return;
    }

    $unstage = runGitIn($packageDir, 'restore --staged -- composer.json');
    if ($unstage['exit'] !== 0) {
        fwrite(STDERR, sprintf(
            "Undid the floor commit in %s, but composer.json is still staged (%s) — unstage it before retrying.\n",
            $name,
            $unstage['output'],
        ));
    }
}

/**
 * Why this repository cannot take the commit, or null when it can.
 *
 * Both halves matter and they fail differently. HEAD somewhere other than master
 * means the commit would not be in the release at all. An unclean worktree or
 * index means the commit would carry somebody else's work to master: an
 * unrelated edit to composer.json is staged with the floor, and anything already
 * in the index rides along with an unrestricted commit.
 */
function whyNotCommittable(string $packageDir): ?string
{
    $name = basename($packageDir);

    $branch = trim((string) shell_exec(sprintf(
        'git -C %s rev-parse --abbrev-ref HEAD 2>/dev/null',
        escapeshellarg($packageDir),
    )));

    if ($branch !== 'master') {
        return sprintf(
            '%s is on "%s", not master — the tag is cut from master, so a commit anywhere else is not in the release',
            $name,
            $branch === '' ? '(unknown)' : $branch,
        );
    }

    $status = runGitIn($packageDir, 'status --porcelain');
    if ($status['exit'] !== 0) {
        return sprintf('%s: cannot read git status (%s)', $name, $status['output']);
    }

    if (trim($status['output']) !== '') {
        return sprintf(
            "%s has uncommitted changes, and this script commits composer.json on master:\n    %s",
            $name,
            str_replace("\n", "\n    ", trim($status['output'])),
        );
    }

    return whyMasterIsNotOriginMaster($packageDir, $name);
}

/**
 * Why local master cannot be pushed as-is, or null when it can.
 *
 * A CLEAN WORKTREE SAYS NOTHING ABOUT UNPUSHED HISTORY, and the push is
 * `HEAD:master` — whatever HEAD carries goes to master with the floor. A local
 * master one commit ahead of origin publishes that commit under a message about
 * dating floors, which is exactly the "commit carries somebody else's work to
 * master" failure the cleanliness check above was written to stop; it simply
 * looks clean because the work is already committed. A master that has DIVERGED
 * fails at push time instead, which is later and worse: earlier packages in the
 * loop are already pushed by then.
 *
 * So origin is fetched and HEAD is required to equal it. Fetching is the check;
 * a fetch that cannot run leaves the question unanswered, and an unanswerable
 * question is a refusal, not a pass.
 */
function whyMasterIsNotOriginMaster(string $packageDir, string $name): ?string
{
    $remote = runGitIn($packageDir, 'remote get-url origin');
    if ($remote['exit'] !== 0 || trim($remote['output']) === '') {
        return sprintf('%s has no "origin" remote, and --commit pushes the floor to origin/master', $name);
    }

    // `fetch origin master` writes FETCH_HEAD unconditionally, which an
    // opportunistic update of refs/remotes/origin/master does not guarantee.
    $fetch = runGitIn($packageDir, 'fetch --quiet origin master');
    if ($fetch['exit'] !== 0) {
        return sprintf(
            "%s: cannot fetch origin master, so whether the push would carry anything besides the floor is unknown:\n    %s",
            $name,
            str_replace("\n", "\n    ", trim($fetch['output'])),
        );
    }

    $head = runGitIn($packageDir, 'rev-parse HEAD');
    $origin = runGitIn($packageDir, 'rev-parse FETCH_HEAD');

    if ($head['exit'] !== 0 || $origin['exit'] !== 0) {
        return sprintf('%s: cannot compare master with origin/master (%s)', $name, trim($head['output'] . ' ' . $origin['output']));
    }

    if (trim($head['output']) === trim($origin['output'])) {
        return null;
    }

    $ahead = runGitIn($packageDir, 'rev-list --count FETCH_HEAD..HEAD');
    $behind = runGitIn($packageDir, 'rev-list --count HEAD..FETCH_HEAD');

    return sprintf(
        '%s: master is not origin/master (%s ahead, %s behind) — `push origin HEAD:master` would publish that '
        . 'history with the floor, or be rejected halfway through the release. Sync master first.',
        $name,
        $ahead['exit'] === 0 ? trim($ahead['output']) : '?',
        $behind['exit'] === 0 ? trim($behind['output']) : '?',
    );
}

/**
 * @param list<array{composer_path: string}> $declarations
 * @return list<string>
 */
function packageDirsOf(array $declarations): array
{
    $dirs = [];
    foreach ($declarations as $declaration) {
        $dirs[dirname($declaration['composer_path'])] = true;
    }

    return array_keys($dirs);
}

/** The master commit this cut would tag: origin/master, or the local one. */
function masterRef(string $packageDir): ?string
{
    foreach (['refs/remotes/origin/master', 'refs/heads/master'] as $ref) {
        $exists = runGitIn($packageDir, 'rev-parse --verify --quiet ' . escapeshellarg($ref));
        if ($exists['exit'] === 0 && trim($exists['output']) !== '') {
            return $ref;
        }
    }

    return null;
}

/** @return array{exit: int, output: string} */
function runGitIn(string $packageDir, string $command): array
{
    $output = [];
    $exit = 0;
    exec(sprintf('git -C %s %s 2>&1', escapeshellarg($packageDir), $command), $output, $exit);

    return ['exit' => $exit, 'output' => implode("\n", $output)];
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

        // MASTER, not HEAD. The tagger releases master; a checkout sitting on
        // develop would otherwise look untagged and put its package in the
        // release set, so a consumer floor could be dated against a provider
        // this cut never tags. origin/master is what bump-packages.php resets
        // to, with the local master as the fallback for a repo without a remote.
        $ref = masterRef($packageDir);
        if ($ref === null) {
            return null; // no master to reason about: the caller refuses
        }

        $tagsOnMaster = shell_exec(sprintf(
            'git -C %s tag --points-at %s --list %s 2>/dev/null',
            escapeshellarg($packageDir),
            escapeshellarg($ref),
            escapeshellarg('20*'),
        ));

        if (trim((string) $tagsOnMaster) !== '') {
            continue; // already released at the commit this cut would tag
        }

        $manifest = file_get_contents($packageDir . '/composer.json');
        if ($manifest === false) {
            fwrite(STDERR, "Cannot read {$packageDir}/composer.json while working out the release set\n");
            exit(1);
        }

        $json = json_decode($manifest, true);
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
        // A manifest this script cannot read or parse is not a manifest it can
        // clear. Skipping one would let --check exit 0 with an undated floor
        // sitting in the very file it failed to open, which is the shape of a
        // gate that passes because it did not look.
        $raw = file_get_contents($composerPath);
        if ($raw === false) {
            fwrite(STDERR, "Cannot read {$composerPath}\n");
            exit(1);
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            fwrite(STDERR, "Invalid JSON in {$composerPath}\n");
            exit(1);
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

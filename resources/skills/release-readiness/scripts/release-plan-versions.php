#!/usr/bin/env php
<?php

declare(strict_types=1);

$expectedRootDir = '/home/taras/Documents/Projects/semitexa.rls';
$rootDir = getenv('RELEASE_ROOT') ?: $expectedRootDir;
$resolvedRootDir = realpath($rootDir);
$resolvedExpectedRootDir = realpath($expectedRootDir);

if ($resolvedRootDir === false || $resolvedExpectedRootDir === false || $resolvedRootDir !== $resolvedExpectedRootDir) {
    fwrite(STDERR, "This release planner is pinned to {$expectedRootDir}.\n");
    exit(1);
}

$releaseStateDir = getenv('RELEASE_STATE_DIR') ?: ((getenv('TMPDIR') ?: '/tmp') . '/semitexa-release-readiness');
$releaseSessionFile = getenv('RELEASE_SESSION_FILE') ?: ($releaseStateDir . '/session.env');
if (getenv('RELEASE_VERSION') === false && is_file($releaseSessionFile)) {
    $sessionLines = file($releaseSessionFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($sessionLines as $line) {
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . trim($value));
        }
    }
}

$packagesDir = $resolvedRootDir . '/packages';
$dirs = array_filter(
    glob($packagesDir . '/*', GLOB_ONLYDIR) ?: [],
    static fn(string $dir): bool => is_file($dir . '/composer.json') && is_dir($dir . '/.git'),
);

echo "--- Planned next release tags from master HEAD ---\n\n";

$releaseVersion = getenv('RELEASE_VERSION') ?: '';
if ($releaseVersion === '' || !isReleaseTag($releaseVersion)) {
    fwrite(STDERR, "RELEASE_VERSION is required and must match the Semitexa UTC release format.\n");
    exit(1);
}

$planned = 0;

foreach ($dirs as $dir) {
    $composerJson = file_get_contents($dir . '/composer.json');
    if ($composerJson === false) {
        continue;
    }

    $composer = json_decode($composerJson, true);
    if (!is_array($composer)) {
        continue;
    }

    $name = (string) ($composer['name'] ?? '');
    if ($name === '') {
        continue;
    }

    fetchRemoteBranch($dir, 'master');
    syncLocalMaster($dir);

    $latestTag = latestReleaseTag($dir);
    $headTagged = headReleaseTag($dir);
    if ($headTagged !== null) {
        continue;
    }

    $headSha = trim((string) shell_exec('git -C ' . escapeshellarg($dir) . ' rev-parse --short master 2>/dev/null'));
    printf(
        "%s|latest_tag:%s|next:%s|head:%s\n",
        $name,
        $latestTag ?? 'none',
        $releaseVersion,
        $headSha !== '' ? $headSha : 'unknown',
    );
    $planned++;
}

if ($planned === 0) {
    echo "No unreleased master heads found.\n";
}

function fetchRemoteBranch(string $repoDir, string $branch): bool
{
    $cmd = sprintf(
        'git -C %s fetch --prune --tags origin %s >/dev/null 2>&1',
        escapeshellarg($repoDir),
        escapeshellarg($branch),
    );

    exec($cmd, $out, $code);

    return $code === 0;
}

function syncLocalMaster(string $packageDir): bool
{
    $originMasterRef = 'refs/remotes/origin/master';
    $localMasterRef = 'refs/heads/master';
    $targetSha = trim((string) shell_exec(sprintf(
        'git -C %s rev-parse --verify %s 2>/dev/null',
        escapeshellarg($packageDir),
        escapeshellarg($originMasterRef),
    )));

    if ($targetSha === '') {
        return false;
    }

    $currentBranch = trim((string) shell_exec(
        'git -C ' . escapeshellarg($packageDir) . ' rev-parse --abbrev-ref HEAD 2>/dev/null'
    ));

    if ($currentBranch === 'master') {
        exec('git -C ' . escapeshellarg($packageDir) . ' reset --hard ' . escapeshellarg($originMasterRef) . ' >/dev/null 2>&1');
        return true;
    }

    exec('git -C ' . escapeshellarg($packageDir) . ' update-ref ' . escapeshellarg($localMasterRef) . ' ' . escapeshellarg($targetSha) . ' >/dev/null 2>&1');
    return true;
}

function latestReleaseTag(string $packageDir): ?string
{
    $out = [];
    exec(sprintf(
        'git -C %s tag --list %s --sort=-v:refname --merged master 2>/dev/null',
        escapeshellarg($packageDir),
        escapeshellarg('v*'),
    ), $out);

    foreach ($out as $line) {
        $line = trim($line);
        if ($line !== '' && isReleaseTag($line)) {
            return $line;
        }
    }

    return null;
}

function headReleaseTag(string $packageDir): ?string
{
    $headSha = trim((string) shell_exec(sprintf(
        'git -C %s rev-parse --verify master 2>/dev/null',
        escapeshellarg($packageDir),
    )));

    if ($headSha === '') {
        return null;
    }

    $out = [];
    exec(sprintf(
        'git -C %s tag --points-at %s 2>/dev/null',
        escapeshellarg($packageDir),
        escapeshellarg($headSha),
    ), $out);

    foreach ($out as $tag) {
        $tag = trim($tag);
        if (isReleaseTag($tag)) {
            return $tag;
        }
    }

    return null;
}

function normalizeVersion(string $version): string
{
    return ltrim(trim($version), 'v');
}

function isReleaseTag(string $tag): bool
{
    $normalized = normalizeVersion($tag);
    return preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?(?:-(?:alpha|beta|rc\d+|p\d+))?$/i', $normalized) === 1;
}

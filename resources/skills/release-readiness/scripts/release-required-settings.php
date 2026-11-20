#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * The settings and doctor checks this release newly requires, per package, in
 * the upgrade notes — and a gate: a newly named setting must be in the
 * package's CHANGELOG.
 *
 * On 2026-11-20 a consumer's prod returned 500 because APP_SECRET was missing.
 * The requirement had shipped in 2026.10.29.1052 and no CHANGELOG said so
 * (tk-new-required-setting-reaches-prod-silently). A doctor check that judges a
 * setting declares it (`#[AsDoctorCheck(..., settings: ['APP_SECRET'])]`), so
 * the cut can write the requirement down every time instead of a person
 * remembering to.
 *
 * Per package, the `#[AsDoctorCheck]` declarations in `src/` at its previous
 * tag and in the tree are compared: the check name, and its `settings` (a list
 * of string literals; anything else is unreadable). Read from the SOURCE with
 * nikic/php-parser (the release clone has it), as release-upgrade-notes.php
 * reads deprecations. A package with no previous tag counts everything as new.
 *
 * ## Usage
 *
 *   php release-required-settings.php [--version=<release version>] [--write=<file>]
 *
 * --write appends "New required settings" and "New doctor checks" to the
 * upgrade notes (the file release-upgrade-notes.php writes), replacing the two
 * sections when they are already there; "none" is written, so the absence is
 * stated. That script rewrites the file, so this one runs after each of its runs.
 *
 * RELEASE_ROOT selects the tree; SEMITEXA_AUTOLOAD the autoloader that provides
 * the parser (default: RELEASE_ROOT/vendor/autoload.php). Prints the sections
 * as Markdown. Exit 1 when:
 *   - a newly named setting does not appear in its package's CHANGELOG.md (a
 *     plain substring of the setting's name is enough);
 *   - something could not be read — the packages, a package's tags, a file now
 *     or at the tag, a declaration, nikic/php-parser — or the notes could not be
 *     written: notes that skipped something would say "none" about code nobody
 *     looked at.
 */

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

const DOCTOR_CHECK_ATTRIBUTE = 'Semitexa\Core\Attribute\AsDoctorCheck';
const SETTINGS_HEADING = '## New required settings';

$options = getopt('', ['version:', 'write:']);
$version = is_string($options['version'] ?? null) ? $options['version'] : (getenv('RELEASE_VERSION') ?: 'next');
$write = is_string($options['write'] ?? null) ? $options['write'] : null;

$releaseRoot = rtrim(getenv('RELEASE_ROOT') ?: '/home/taras/Documents/Projects/semitexa.rls', DIRECTORY_SEPARATOR);
$autoload = getenv('SEMITEXA_AUTOLOAD') ?: $releaseRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fail("No autoloader at {$autoload}: reading the doctor checks needs nikic/php-parser.");
}
require $autoload;
if (!class_exists(ParserFactory::class)) {
    fail("{$autoload} does not provide nikic/php-parser.");
}

$packagesDir = $releaseRoot . '/packages';
$packageDirs = is_dir($packagesDir) ? glob($packagesDir . '/*') : false;
if ($packageDirs === false) {
    fail("Cannot list the packages in {$packagesDir}.");
}

/** @var list<array{string, string, string, string}> $newSettings setting, check, package, since */
$newSettings = [];
/** @var list<array{string, string, string}> $newChecks check, package, since */
$newChecks = [];
/** @var list<string> $unread */
$unread = [];
/** @var list<string> $unannounced */
$unannounced = [];
foreach ($packageDirs as $packageDir) {
    // No manifest at all: not a package. One that is there and cannot be read is recorded later.
    $manifest = $packageDir . '/composer.json';
    if (!is_dir($packageDir . '/.git') || (!file_exists($manifest) && !is_link($manifest))) {
        continue;
    }
    $name = packageName($packageDir, $unread);
    $tag = latestTag($packageDir, $version, $unread);
    if ($tag === false) {
        continue; // the tags could not be listed (recorded)
    }
    // Never released: nothing was declared before, so everything in it is new.
    $then = $tag === null ? [] : checksAtTag($packageDir, $tag, $unread);
    $now = checksInTree($packageDir, $unread);
    $since = $tag ?? 'its first release';

    foreach (array_keys($now) as $check) {
        if (!isset($then[$check])) {
            $newChecks[] = [$check, $name, $since];
        }
    }
    // By package, not by check: a setting that only moved to another check was required before.
    $namedThen = array_merge(...array_values($then));
    $settingsNow = [];
    foreach ($now as $check => $settings) {
        foreach ($settings as $setting) {
            if (!in_array($setting, $namedThen, true)) {
                $settingsNow[$setting][] = $check;
            }
        }
    }
    if ($settingsNow === []) {
        continue;
    }
    $changelogPath = $packageDir . '/CHANGELOG.md';
    $changelog = is_file($changelogPath) ? @file_get_contents($changelogPath) : '';
    if ($changelog === false) {
        $unread[] = $changelogPath;
        $changelog = null;
    }
    foreach ($settingsNow as $setting => $checks) {
        $setting = (string) $setting;
        $newSettings[] = [$setting, implode('`, `', $checks), $name, $since];
        if ($changelog !== null && !str_contains($changelog, $setting)) {
            $unannounced[] = "{$name}: {$setting} is newly required (doctor check " . implode(', ', $checks) . ") and {$changelogPath} does not mention it.";
        }
    }
}
sort($newSettings);
sort($newChecks);

$lines = [SETTINGS_HEADING, ''];
if ($newSettings === []) {
    $lines[] = 'none';
} else {
    $lines[] = 'Set these before the new code serves requests: `bin/semitexa system:doctor` fails without them, and so does `bin/semitexa update`.';
    $lines[] = '';
    foreach ($newSettings as [$setting, $checks, $package, $since]) {
        $lines[] = "- `{$setting}` — check `{$checks}`, {$package} (since {$since})";
    }
}
$lines[] = '';
$lines[] = '## New doctor checks';
$lines[] = '';
if ($newChecks === []) {
    $lines[] = 'none';
} else {
    foreach ($newChecks as [$check, $package, $since]) {
        $lines[] = "- `{$check}` — {$package} (since {$since})";
    }
}
$markdown = implode("\n", $lines) . "\n";

echo $markdown;
if ($write !== null) {
    $notes = "# Upgrading to {$version}\n";
    if (file_exists($write) || is_link($write)) {
        $notes = @file_get_contents($write);
        if ($notes === false) {
            fail("Cannot read {$write}: the sections would replace the notes instead of joining them.");
        }
    } elseif (!is_dir(dirname($write)) && !@mkdir(dirname($write), 0775, true) && !is_dir(dirname($write))) {
        fail('Cannot create ' . dirname($write));
    }
    // Both sections are the end of the file: a second run replaces them.
    $at = strpos("\n" . $notes, "\n" . SETTINGS_HEADING . "\n");
    if ($at !== false) {
        $notes = substr($notes, 0, $at);
    }
    if (@file_put_contents($write, rtrim($notes, "\n") . "\n\n" . $markdown) === false) {
        fail("Cannot write {$write}");
    }
    fwrite(STDERR, "Required settings written to {$write}\n");
}
if ($unread !== []) {
    fwrite(STDERR, 'Not read, so not in the notes: ' . implode(', ', $unread) . "\n");
}
if ($unannounced !== []) {
    fwrite(STDERR, "A new required setting has to be named in its package's CHANGELOG.md:\n  " . implode("\n  ", $unannounced) . "\n");
}
exit($unread === [] && $unannounced === [] ? 0 : 1);

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * Runs git in $dir, with paths printed as they are (no quoting of unusual
 * characters), and returns its output; $status receives the exit status.
 * With $strict, anything git wrote to stderr makes the status 2:
 * `git grep` skips an object it cannot read, says so there, and exits as if
 * nothing had matched (no temporary file to catch it in counts as a complaint).
 *
 * @param list<string> $arguments
 * @param-out int $status
 */
function git(string $dir, array $arguments, ?int &$status = null, bool $strict = false): string
{
    $command = 'git -C ' . escapeshellarg($dir) . ' -c core.quotePath=false ' . implode(' ', array_map('escapeshellarg', $arguments));
    $errors = $strict ? tempnam(sys_get_temp_dir(), 'semitexa-git-') : false;
    $output = shell_exec($command . ' 2>' . ($errors === false ? '/dev/null' : escapeshellarg($errors)) . '; printf "\n%s" "$?"');
    $complained = $strict;
    if ($errors !== false) {
        $complained = @file_get_contents($errors) !== '';
        @unlink($errors);
    }
    if (!is_string($output)) {
        $status = 1;

        return '';
    }
    $cut = (int) strrpos($output, "\n");
    $status = (int) substr($output, $cut + 1);
    if ($complained && $status <= 1) {
        $status = 2;
    }

    return substr($output, 0, $cut);
}

/**
 * The package's Composer name; an unreadable manifest is recorded, and the
 * directory name stands in so its lines are still printed.
 *
 * @param list<string> $unread
 */
function packageName(string $packageDir, array &$unread): string
{
    $source = @file_get_contents($packageDir . '/composer.json');
    $manifest = is_string($source) ? json_decode($source, true) : null;
    if (!is_array($manifest) || !is_string($manifest['name'] ?? null)) {
        $unread[] = $packageDir . '/composer.json';

        return basename($packageDir);
    }

    return $manifest['name'];
}

/**
 * The newest release tag before this release, by creation date: the tag this
 * release is cut as is skipped, so a rerun after tagging still compares with
 * the release before. Null when the package was never released; false when the
 * listing failed (then recorded in $unread).
 *
 * @param list<string> $unread
 */
function latestTag(string $packageDir, string $version, array &$unread): string|false|null
{
    $out = git($packageDir, ['tag', '--sort=-creatordate', '--list', '20*'], $status);
    if ($status !== 0) {
        $unread[] = $packageDir . ' (tags)';

        return false;
    }
    $tags = array_values(array_filter(array_map('trim', explode("\n", $out)), static fn (string $t): bool => $t !== '' && $t !== $version));

    return $tags[0] ?? null;
}

/**
 * Every doctor check declared under src/ at $tag. Only the files that mention
 * the attribute are parsed; git finds them.
 *
 * @param list<string> $unread
 * @return array<string, list<string>> check name => the settings it names
 */
function checksAtTag(string $packageDir, string $tag, array &$unread): array
{
    // -z: NUL-separated, so no path is quoted or split on a newline. Status 1: no file mentions it.
    $out = git($packageDir, ['grep', '-l', '-z', '-F', 'AsDoctorCheck', $tag, '--', 'src'], $status, true);
    if ($status !== 0 && $status !== 1) {
        $unread[] = $packageDir . ' (doctor checks at ' . $tag . ')';

        return [];
    }
    $checks = [];
    foreach ($out === '' ? [] : explode("\0", rtrim($out, "\0")) as $object) {
        if (!str_ends_with($object, '.php')) {
            continue;
        }
        $where = $packageDir . '/' . substr($object, strlen($tag) + 1) . ' (at ' . $tag . ')';
        $source = git($packageDir, ['show', $object], $showStatus);
        if ($showStatus !== 0) {
            $unread[] = $where;
            continue;
        }
        $checks += checksIn($source, $unread, $where);
    }

    return $checks;
}

/**
 * Every doctor check declared under src/ in the working tree.
 *
 * @param list<string> $unread
 * @return array<string, list<string>> check name => the settings it names
 */
function checksInTree(string $packageDir, array &$unread): array
{
    $src = $packageDir . '/src';
    if (!is_dir($src)) {
        return [];
    }
    $paths = [];
    try {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getPathname(), '.php')) {
                $paths[] = $file->getPathname();
            }
        }
    } catch (UnexpectedValueException $e) {
        $unread[] = $src . ' (' . $e->getMessage() . ')';

        return [];
    }
    sort($paths);
    $checks = [];
    foreach ($paths as $path) {
        // A dangling link is a file that is there and cannot be read, not a missing one.
        $source = @file_get_contents($path);
        if ($source === false) {
            $unread[] = $path;
            continue;
        }
        if (str_contains($source, 'AsDoctorCheck')) {
            $checks += checksIn($source, $unread, $path);
        }
    }

    return $checks;
}

/**
 * The #[AsDoctorCheck] declarations in one source file. A name that is not a
 * string literal, or settings that are not a list of them, cannot be compared
 * with another revision: recorded as unread.
 *
 * @param list<string> $unread
 * @return array<string, list<string>> check name => the settings it names
 */
function checksIn(string $source, array &$unread, string $where): array
{
    try {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
    } catch (\PhpParser\Error $e) {
        $unread[] = $where . ' (' . $e->getMessage() . ')';

        return [];
    }
    $ast = (new NodeTraverser(new NameResolver()))->traverse($ast);

    $checks = [];
    foreach ((new NodeFinder())->findInstanceOf($ast, Node\Attribute::class) as $attribute) {
        if ($attribute->name->toString() !== DOCTOR_CHECK_ATTRIBUTE) {
            continue;
        }
        // The constructor's order: name, package, settings.
        $name = $settings = null;
        $readable = true;
        foreach ($attribute->args as $position => $argument) {
            if ($argument->unpack) {
                $readable = false;
                break;
            }
            $parameter = $argument->name?->toString() ?? ['name', 'package', 'settings'][$position] ?? null;
            if ($parameter === 'name') {
                $name = $argument->value;
            } elseif ($parameter === 'settings') {
                $settings = $argument->value;
            }
        }
        if (!$readable || !$name instanceof Node\Scalar\String_) {
            $unread[] = $where . ' (a doctor check whose name is not a string literal)';
            continue;
        }
        $named = $settings === null ? [] : stringList($settings);
        if ($named === null) {
            $unread[] = $where . " (the settings of doctor check {$name->value} are not a list of string literals)";
            continue;
        }
        $checks[$name->value] = $named;
    }

    return $checks;
}

/**
 * The strings of an array literal that holds nothing else; null for any other expression.
 *
 * @return list<string>|null
 */
function stringList(Node\Expr $expression): ?array
{
    if (!$expression instanceof Node\Expr\Array_) {
        return null;
    }
    $strings = [];
    foreach ($expression->items as $item) {
        if ($item->key !== null || $item->unpack || $item->byRef || !$item->value instanceof Node\Scalar\String_) {
            return null;
        }
        $strings[] = $item->value->value;
    }

    return $strings;
}

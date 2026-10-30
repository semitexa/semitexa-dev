#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * What this release deprecated, per package, as upgrade notes.
 *
 * The stability contract (docs: get-started/stability) promises a deprecated
 * `@api` part 90 days of warning. A warning is only one if it is read: a
 * `@deprecated` tag in a vendor file is seen by whoever opens that file. This
 * collects every deprecation a package gained since its previous tag — classes,
 * interfaces, traits, enums, methods, constants, properties (promoted ones too)
 * and enum cases; whether it is `@api`; and the tag's text (when, and what to
 * use instead) — so the release can say it once, in one place.
 *
 * Read from the SOURCE of the tag and of the working tree, parsed with
 * nikic/php-parser (the release clone has it): two versions of one class
 * cannot be loaded in one process, and a token scan mistook anonymous classes,
 * switch labels and functions after a class for members.
 *
 * ## Usage
 *
 *   php release-upgrade-notes.php [--version=<release version>] [--write=<file>]
 *
 * RELEASE_ROOT selects the tree; SEMITEXA_AUTOLOAD the autoloader that provides
 * the parser (default: RELEASE_ROOT/vendor/autoload.php). Prints the notes as
 * Markdown; --write also saves them. Exit 0 whatever it finds (nothing
 * deprecated is the usual case). Exit 1 when something could not be read — the
 * packages, a package's tags or changes, a file now or at the tag — or the notes
 * could not be written: notes that skipped something would say "nothing
 * deprecated" about code nobody looked at.
 */

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

$options = getopt('', ['version:', 'write:']);
$version = is_string($options['version'] ?? null) ? $options['version'] : (getenv('RELEASE_VERSION') ?: 'next');
$write = is_string($options['write'] ?? null) ? $options['write'] : null;

$releaseRoot = rtrim(getenv('RELEASE_ROOT') ?: '/home/taras/Documents/Projects/semitexa.rls', DIRECTORY_SEPARATOR);
$autoload = getenv('SEMITEXA_AUTOLOAD') ?: $releaseRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fail("No autoloader at {$autoload}: the notes need nikic/php-parser.");
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

$sections = [];
$unread = [];
foreach ($packageDirs as $packageDir) {
    // No manifest at all: not a package. One that is there and cannot be read is recorded later.
    $manifest = $packageDir . '/composer.json';
    if (!is_dir($packageDir . '/.git') || (!file_exists($manifest) && !is_link($manifest))) {
        continue;
    }
    $tag = latestTag($packageDir, $version, $unread);
    if ($tag === null) {
        continue; // never released (or unreadable, recorded): nothing in it was promised yet
    }
    // Read for every released package, not only one with news: an unreadable manifest fails the run either way.
    $name = packageName($packageDir, $unread);
    $added = addedDeprecations($packageDir, $tag, $unread);
    if ($added !== []) {
        $sections[$name] = ['since' => $tag, 'added' => $added];
    }
}
ksort($sections);

$lines = ["# Upgrading to {$version}", ''];
if ($sections === []) {
    $lines[] = 'Nothing was deprecated in this release.';
} else {
    $lines[] = 'Deprecated in this release. Parts marked `@api` keep working for at least 90 days after the release that ships the deprecation; the others are listed as a courtesy, with no such promise (see get-started/stability).';
    foreach ($sections as $package => $section) {
        $lines[] = '';
        $lines[] = "## {$package} (since {$section['since']})";
        $lines[] = '';
        foreach ($section['added'] as $symbol => [$isApi, $text]) {
            $lines[] = sprintf('- `%s`%s — %s', $symbol, $isApi ? ' (`@api`)' : '', $text !== '' ? $text : 'no reason given');
        }
    }
}
$markdown = implode("\n", $lines) . "\n";

echo $markdown;
if ($write !== null) {
    if (!is_dir(dirname($write)) && !@mkdir(dirname($write), 0775, true) && !is_dir(dirname($write))) {
        fail('Cannot create ' . dirname($write));
    }
    if (@file_put_contents($write, $markdown) === false) {
        fail("Cannot write {$write}");
    }
    fwrite(STDERR, "Upgrade notes written to {$write}\n");
}
if ($unread !== []) {
    fail('Not read, so not in the notes: ' . implode(', ', $unread));
}
exit(0);

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * Runs git in $dir, with paths printed as they are (no quoting of unusual
 * characters), and returns its output; $status receives the exit status.
 *
 * @param list<string> $arguments
 * @param-out int $status
 */
function git(string $dir, array $arguments, ?int &$status = null): string
{
    $command = 'git -C ' . escapeshellarg($dir) . ' -c core.quotePath=false ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>/dev/null';
    $output = shell_exec($command . '; printf "\n%s" "$?"');
    if (!is_string($output)) {
        $status = 1;

        return '';
    }
    $cut = (int) strrpos($output, "\n");
    $status = (int) substr($output, $cut + 1);

    return substr($output, 0, $cut);
}

/**
 * The package's Composer name; an unreadable manifest is recorded, and the
 * directory name stands in so the section is still printed.
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
 * the release before; null when there is none, or when the listing failed
 * (then recorded in $unread).
 *
 * @param list<string> $unread
 */
function latestTag(string $packageDir, string $version, array &$unread): ?string
{
    $out = git($packageDir, ['tag', '--sort=-creatordate', '--list', '20*'], $status);
    if ($status !== 0) {
        $unread[] = $packageDir . ' (tags)';

        return null;
    }
    $tags = array_values(array_filter(array_map('trim', explode("\n", $out)), static fn (string $t): bool => $t !== '' && $t !== $version));

    return $tags[0] ?? null;
}

/**
 * Deprecations present in the working tree and absent at $tag, by symbol. A
 * renamed file is compared with its old path, so a deprecation that only moved
 * is not news.
 *
 * @param list<string> $unread what could not be read
 * @return array<string, array{bool, string}> symbol => [is @api, the tag's text]
 */
function addedDeprecations(string $packageDir, string $tag, array &$unread): array
{
    // -z: NUL-separated, so no path is quoted or split on a newline.
    $out = git($packageDir, ['diff', '--name-status', '-z', '-M', $tag, '--', 'src'], $status);
    if ($status !== 0) {
        $unread[] = $packageDir . ' (changes since ' . $tag . ')';

        return [];
    }
    $fields = $out === '' ? [] : explode("\0", rtrim($out, "\0"));
    $added = [];
    for ($i = 0; $i < count($fields);) {
        $kind = $fields[$i][0] ?? '';
        if ($kind === 'R' || $kind === 'C') {
            [$before, $now] = [$fields[$i + 1] ?? '', $fields[$i + 2] ?? ''];
            $i += 3;
        } else {
            $before = $now = $fields[$i + 1] ?? '';
            $i += 2;
        }
        if ($kind === 'D' || !str_ends_with($now, '.php')) {
            continue; // deleted: a removal, which is the BC gate's to judge
        }
        $path = $packageDir . '/' . $now;
        // A dangling link is a file that is there and cannot be read, not a deleted one.
        $current = @file_get_contents($path);
        if ($current === false) {
            $unread[] = $path;
            continue;
        }
        $old = '';
        if ($kind !== 'A' && $kind !== 'C') {
            $old = git($packageDir, ['show', $tag . ':' . $before], $showStatus);
            if ($showStatus !== 0) {
                $unread[] = $path . ' (at ' . $tag . ')';
                continue;
            }
        }
        $then = deprecationsIn($old, $unread, $path . ' (at ' . $tag . ')');
        foreach (deprecationsIn($current, $unread, $path) as $symbol => $entry) {
            if (!isset($then[$symbol])) {
                $added[$symbol] = $entry;
            }
        }
    }
    ksort($added);

    return $added;
}

/**
 * Every deprecated class-like, method, constant, property and enum case in one
 * source file. An anonymous class names nothing a user can reach, so it is left
 * out; a member is `@api` when marked itself, or when public in an `@api` class
 * (a property only when marked itself, as the phpstan rule reads it).
 *
 * @param list<string> $unread
 * @return array<string, array{bool, string}> symbol => [is @api, the tag's text]
 */
function deprecationsIn(string $source, array &$unread, string $where): array
{
    if (trim($source) === '') {
        return [];
    }
    try {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
    } catch (\PhpParser\Error $e) {
        $unread[] = $where . ' (' . $e->getMessage() . ')';

        return [];
    }
    $ast = (new NodeTraverser(new NameResolver()))->traverse($ast);

    $found = [];
    foreach ((new NodeFinder())->findInstanceOf($ast, Stmt\ClassLike::class) as $class) {
        if ($class->namespacedName === null) {
            continue; // anonymous
        }
        $name = $class->namespacedName->toString();
        $classIsApi = hasTag($class->getDocComment(), 'api');
        if (hasTag($class->getDocComment(), 'deprecated')) {
            $found[$name] = [$classIsApi, tagText($class->getDocComment(), 'deprecated')];
        }
        foreach (members($class) as [$member, $doc, $public, $isProperty]) {
            if (hasTag($doc, 'deprecated')) {
                $isApi = hasTag($doc, 'api') || ($classIsApi && $public && !$isProperty);
                $found[$name . '::' . $member] = [$isApi, tagText($doc, 'deprecated')];
            }
        }
    }

    return $found;
}

/**
 * A class-like's members: name, docblock, public, is a property.
 *
 * @return list<array{string, ?Doc, bool, bool}>
 */
function members(Stmt\ClassLike $class): array
{
    $members = [];
    foreach ($class->stmts as $stmt) {
        if ($stmt instanceof Stmt\ClassMethod) {
            $members[] = [$stmt->name->toString() . '()', $stmt->getDocComment(), $stmt->isPublic(), false];
            foreach ($stmt->params as $param) {
                if ($param->isPromoted() && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                    $members[] = ['$' . $param->var->name, $param->getDocComment(), $param->isPublic(), true];
                }
            }
        } elseif ($stmt instanceof Stmt\ClassConst) {
            foreach ($stmt->consts as $const) {
                $members[] = [$const->name->toString(), $stmt->getDocComment(), $stmt->isPublic(), false];
            }
        } elseif ($stmt instanceof Stmt\Property) {
            foreach ($stmt->props as $property) {
                $members[] = ['$' . $property->name->toString(), $stmt->getDocComment(), $stmt->isPublic(), true];
            }
        } elseif ($stmt instanceof Stmt\EnumCase) {
            $members[] = [$stmt->name->toString(), $stmt->getDocComment(), true, false];
        }
    }

    return $members;
}

/**
 * A tag opens a docblock line: `* @tag` or `/** @tag`. Further in, it is an
 * example inside the prose (DeprecatedApiRule's own docblock shows one) and
 * deprecates nothing — the same reading as the phpstan rule's.
 */
function tagStart(): string
{
    return '^[ \t]*(?:\*[ \t]?|\/\*\*[ \t]*)@';
}

function hasTag(?Doc $doc, string $tag): bool
{
    return $doc !== null && preg_match('/' . tagStart() . $tag . '\b/m', $doc->getText()) === 1;
}

/** The text after @<tag>, continuation lines joined, up to the next tag. */
function tagText(?Doc $doc, string $tag): string
{
    if ($doc === null || preg_match('/' . tagStart() . $tag . '\b(.*?)(?=^[ \t]*\*[ \t]?@|\*\/)/ms', $doc->getText(), $m) !== 1) {
        return '';
    }

    return trim((string) preg_replace('/\s*\n\s*\*\s*/', ' ', $m[1]));
}

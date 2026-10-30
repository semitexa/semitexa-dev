#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * What this release deprecated, per package, as upgrade notes.
 *
 * The stability contract (docs: get-started/stability) promises a deprecated
 * `@api` part 90 days of warning. A warning is only one if it is read: a
 * `@deprecated` tag in a vendor file is seen by whoever opens that file. This
 * collects every deprecation a package gained since its previous tag — the
 * symbol, whether it is `@api`, and the tag's text (when, and what to use
 * instead) — so the release can say it once, in one place.
 *
 * Read from the SOURCE TEXT of the tag and of the working tree, as
 * release-new-public-api.php does: two versions of one class cannot be loaded
 * in one process.
 *
 * ## Usage
 *
 *   php release-upgrade-notes.php [--version=<release version>] [--write=<file>]
 *
 * RELEASE_ROOT selects the tree. Prints the notes as Markdown; --write also
 * saves them. Exit 0 whatever it finds (nothing deprecated is the usual case);
 * exit 1 when a changed file could not be read, since notes that skipped a file
 * would say "nothing deprecated" about code nobody looked at.
 */

$options = getopt('', ['version:', 'write:']);
$version = is_string($options['version'] ?? null) ? $options['version'] : (getenv('RELEASE_VERSION') ?: 'next');
$write = is_string($options['write'] ?? null) ? $options['write'] : null;

$releaseRoot = rtrim(getenv('RELEASE_ROOT') ?: '/home/taras/Documents/Projects/semitexa.rls', DIRECTORY_SEPARATOR);
$packagesDir = $releaseRoot . '/packages';
if (!is_dir($packagesDir)) {
    fwrite(STDERR, "Packages directory not found: {$packagesDir}\n");
    exit(1);
}

$sections = [];
$unread = [];
foreach (glob($packagesDir . '/*') ?: [] as $packageDir) {
    if (!is_dir($packageDir . '/.git') || !is_file($packageDir . '/composer.json')) {
        continue;
    }
    $tag = latestTag($packageDir);
    if ($tag === null) {
        continue; // never released: nothing in it was promised yet
    }
    $added = addedDeprecations($packageDir, $tag, $unread);
    if ($added !== []) {
        $sections[packageName($packageDir)] = ['since' => $tag, 'added' => $added];
    }
}
ksort($sections);

$lines = ["# Upgrading to {$version}", ''];
if ($sections === []) {
    $lines[] = 'Nothing was deprecated in this release.';
} else {
    $lines[] = 'Deprecated in this release. A deprecated part keeps working for at least 90 days after the release that ships it; `@api` parts are promised that, the rest is a courtesy (see get-started/stability).';
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
    if (!is_dir(dirname($write)) && !mkdir(dirname($write), 0775, true) && !is_dir(dirname($write))) {
        fwrite(STDERR, "Cannot create " . dirname($write) . "\n");
        exit(1);
    }
    if (file_put_contents($write, $markdown) === false) {
        fwrite(STDERR, "Cannot write {$write}\n");
        exit(1);
    }
    fwrite(STDERR, "Upgrade notes written to {$write}\n");
}
if ($unread !== []) {
    fwrite(STDERR, "Not read, so not in the notes: " . implode(', ', $unread) . "\n");
    exit(1);
}
exit(0);

function packageName(string $packageDir): string
{
    $manifest = json_decode((string) file_get_contents($packageDir . '/composer.json'), true);

    return is_array($manifest) && is_string($manifest['name'] ?? null) ? $manifest['name'] : basename($packageDir);
}

/** The newest release tag, by creation date, or null when the package has none. */
function latestTag(string $packageDir): ?string
{
    $out = shell_exec(sprintf('git -C %s tag --sort=-creatordate --list %s 2>/dev/null', escapeshellarg($packageDir), escapeshellarg('20*')));
    $tags = array_values(array_filter(array_map('trim', explode("\n", (string) $out))));

    return $tags[0] ?? null;
}

/**
 * Deprecations present in the working tree and absent at $tag, by symbol.
 *
 * @param list<string> $unread files changed since $tag that could not be read
 * @return array<string, array{bool, string}> symbol => [is @api, the tag's text]
 */
function addedDeprecations(string $packageDir, string $tag, array &$unread): array
{
    $changed = shell_exec(sprintf('git -C %s diff --name-only %s -- src 2>/dev/null', escapeshellarg($packageDir), escapeshellarg($tag)));
    $added = [];
    foreach (array_filter(array_map('trim', explode("\n", (string) $changed))) as $relative) {
        if (!str_ends_with($relative, '.php')) {
            continue;
        }
        $path = $packageDir . '/' . $relative;
        if (!file_exists($path)) {
            continue; // deleted: a removal, which is the BC gate's to judge
        }
        $now = @file_get_contents($path);
        if ($now === false) {
            $unread[] = $path;
            continue;
        }
        // Absent at the tag (a new file) reads as empty: everything in it is new.
        $before = (string) shell_exec(sprintf('git -C %s show %s 2>/dev/null', escapeshellarg($packageDir), escapeshellarg($tag . ':' . $relative)));
        $old = deprecationsIn($before);
        foreach (deprecationsIn($now) as $symbol => $entry) {
            if (!isset($old[$symbol])) {
                $added[$symbol] = $entry;
            }
        }
    }
    ksort($added);

    return $added;
}

/**
 * Every deprecated class, method, constant and enum case in one source file.
 *
 * @return array<string, array{bool, string}> symbol => [is @api, the tag's text]
 */
function deprecationsIn(string $source): array
{
    if ($source === '') {
        return [];
    }
    $tokens = array_values(PhpToken::tokenize($source));
    $namespace = '';
    $class = null;
    $classIsApi = false;
    $found = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if ($token->is(T_NAMESPACE)) {
            $namespace = '';
            for ($j = $i + 1; $j < $count && !in_array($tokens[$j]->text, [';', '{'], true); $j++) {
                if ($tokens[$j]->is([T_NAME_QUALIFIED, T_STRING])) {
                    $namespace = $tokens[$j]->text;
                }
            }
            continue;
        }
        $doc = $token->is(T_DOC_COMMENT) ? $token->text : null;
        // What a docblock documents: the next declaration after attributes and modifiers.
        $j = $doc !== null ? $i + 1 : $i;
        $j = skipToDeclaration($tokens, $j);
        if ($j === null) {
            continue;
        }
        $kind = $tokens[$j];
        if ($kind->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && !isClassConstantFetch($tokens, $j)) {
            $name = nextOf($tokens, $j, [T_STRING]);
            if ($name === null) {
                continue;
            }
            $class = ltrim($namespace . '\\' . $name, '\\');
            $classIsApi = $doc !== null && hasTag($doc, 'api');
            if ($doc !== null && hasTag($doc, 'deprecated')) {
                $found[$class] = [$classIsApi, tagText($doc, 'deprecated')];
            }
            $i = $j;
            continue;
        }
        if ($doc === null || $class === null || !hasTag($doc, 'deprecated')) {
            continue;
        }
        $member = match (true) {
            $kind->is(T_FUNCTION) => ($n = nextOf($tokens, $j, [T_STRING])) !== null ? $n . '()' : null,
            $kind->is(T_CONST) => constName($tokens, $j),
            $kind->is(T_CASE) => nextOf($tokens, $j, [T_STRING]),
            default => null,
        };
        if ($member === null) {
            continue;
        }
        $public = !hasModifier($tokens, $i + 1, $j, ['private', 'protected']);
        $found[$class . '::' . $member] = [hasTag($doc, 'api') || ($classIsApi && $public), tagText($doc, 'deprecated')];
        $i = $j;
    }

    return $found;
}

/**
 * @param list<PhpToken> $tokens
 */
function skipToDeclaration(array $tokens, int $from): ?int
{
    $modifiers = ['public', 'protected', 'private', 'static', 'final', 'abstract', 'readonly'];
    for ($j = $from, $count = count($tokens); $j < $count; $j++) {
        $t = $tokens[$j];
        if ($t->is([T_WHITESPACE, T_COMMENT]) || in_array(strtolower($t->text), $modifiers, true)) {
            continue;
        }
        if ($t->is(T_ATTRIBUTE)) {
            for ($depth = 1, $j++; $j < $count && $depth > 0; $j++) {
                $depth += $tokens[$j]->text === '[' ? 1 : ($tokens[$j]->text === ']' ? -1 : 0);
            }
            $j--;
            continue;
        }

        return $t->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION, T_CONST, T_CASE]) ? $j : null;
    }

    return null;
}

/**
 * `Foo::class` is not a declaration.
 *
 * @param list<PhpToken> $tokens
 */
function isClassConstantFetch(array $tokens, int $at): bool
{
    for ($j = $at - 1; $j >= 0 && $tokens[$j]->is(T_WHITESPACE); $j--) {
    }

    return $j >= 0 && $tokens[$j]->is(T_DOUBLE_COLON);
}

/**
 * @param list<PhpToken> $tokens
 * @param list<int> $kinds
 */
function nextOf(array $tokens, int $from, array $kinds): ?string
{
    for ($j = $from + 1, $count = count($tokens); $j < $count; $j++) {
        if ($tokens[$j]->is($kinds)) {
            return $tokens[$j]->text;
        }
        if (in_array($tokens[$j]->text, ['(', '{', ';', '='], true)) {
            return null;
        }
    }

    return null;
}

/**
 * The name of `const [type] NAME = ...`: the last identifier before `=`.
 *
 * @param list<PhpToken> $tokens
 */
function constName(array $tokens, int $from): ?string
{
    $name = null;
    for ($j = $from + 1, $count = count($tokens); $j < $count && $tokens[$j]->text !== '='; $j++) {
        if ($tokens[$j]->is(T_STRING)) {
            $name = $tokens[$j]->text;
        }
    }

    return $name;
}

/**
 * @param list<PhpToken> $tokens
 * @param list<string> $modifiers
 */
function hasModifier(array $tokens, int $from, int $to, array $modifiers): bool
{
    for ($j = $from; $j < $to; $j++) {
        if (in_array(strtolower($tokens[$j]->text), $modifiers, true)) {
            return true;
        }
    }

    return false;
}

/**
 * A tag opens a docblock line: `* @tag` or `/** @tag`. Further in, it is an
 * example inside the prose (DeprecatedApiRule's own docblock shows one) and
 * deprecates nothing.
 */
function tagStart(): string
{
    return '(?:^[ \t]*\*[ \t]?|\/\*\*[ \t]*)@';
}

function hasTag(string $doc, string $tag): bool
{
    return preg_match('/' . tagStart() . $tag . '\b/m', $doc) === 1;
}

/** The text after @<tag>, continuation lines joined, up to the next tag. */
function tagText(string $doc, string $tag): string
{
    if (preg_match('/' . tagStart() . $tag . '\b(.*?)(?=^[ \t]*\*[ \t]?@|\*\/)/ms', $doc, $m) !== 1) {
        return '';
    }

    return trim((string) preg_replace('/\s*\n\s*\*\s*/', ' ', $m[1]));
}

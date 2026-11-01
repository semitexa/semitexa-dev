#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * The backward-compatibility gate of the stability contract.
 *
 * The contract (docs: get-started/stability) promises what is marked `@api`,
 * and a deprecated `@api` part 90 days before it may go. roave/backward-
 * compatibility-check finds every break between two versions, but checks every
 * public symbol and skips only `@internal`: it has no `@api`-only mode. So it
 * runs per package against the package's previous tag, and its breaks are
 * kept only when they touch a symbol that was `@api` at that tag — the class,
 * or the member itself — and are refused unless that symbol had been
 * deprecated at least 90 days before this release.
 *
 * A package with no `@api` at its previous tag promised nothing and is not
 * checked, so the gate costs nothing until a package marks its surface.
 *
 * ## Usage
 *
 *   php release-bc-gate.php [--version=<release version>]
 *
 * RELEASE_ROOT selects the tree; SEMITEXA_AUTOLOAD the autoloader with
 * nikic/php-parser (default RELEASE_ROOT/vendor/autoload.php); SEMITEXA_BC_CHECK
 * the command that runs roave inside a package directory, given
 * `--from=<tag> --to=HEAD --format=json` (default
 * `vendor/bin/roave-backward-compatibility-check` of RELEASE_ROOT). Prints each
 * break it keeps; exit 1 when one is not allowed, or when a package, a tag or
 * roave's answer could not be read — a gate that cannot check must not pass.
 *
 * The one way past an unreadable tag is an exemption the operator decided,
 * declared in release-bc-gate-exemptions.json next to this script
 * (SEMITEXA_BC_EXEMPTIONS another file): `{"<package dir>": {"<tag>": "<why>"}}`.
 * It forgives only roave failing to read that package at that tag; breaks
 * roave does report are judged as always. It names one tag, so it stops
 * applying once the package is released again, and an entry no package used
 * is printed for removal.
 */

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

const WINDOW_DAYS = 90;

$options = getopt('', ['version:']);
$version = is_string($options['version'] ?? null) ? $options['version'] : (getenv('RELEASE_VERSION') ?: gmdate('Y.m.d.Hi'));
$releaseRoot = rtrim(getenv('RELEASE_ROOT') ?: '/home/taras/Documents/Projects/semitexa.rls', DIRECTORY_SEPARATOR);
$autoload = getenv('SEMITEXA_AUTOLOAD') ?: $releaseRoot . '/vendor/autoload.php';
$check = getenv('SEMITEXA_BC_CHECK') ?: escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($releaseRoot . '/vendor/bin/roave-backward-compatibility-check');
$exemptions = exemptions(getenv('SEMITEXA_BC_EXEMPTIONS') ?: __DIR__ . '/release-bc-gate-exemptions.json');

if (!is_file($autoload)) {
    fail("No autoloader at {$autoload}: the gate needs nikic/php-parser.");
}
require $autoload;
if (!class_exists(ParserFactory::class)) {
    fail("{$autoload} does not provide nikic/php-parser.");
}
$today = cutDate($version);
if ($today === null) {
    fail("--version {$version} is not a release version (YYYY.MM.DD or YYYY.MM.DD.HHMM).");
}

$packagesDir = $releaseRoot . '/packages';
$packageDirs = is_dir($packagesDir) ? glob($packagesDir . '/*') : false;
if ($packageDirs === false) {
    fail("Cannot list the packages in {$packagesDir}.");
}

$refused = [];
$allowed = [];
$problems = [];
$unchanged = [];
$exempted = [];
$used = [];
foreach ($packageDirs as $packageDir) {
    // No src at HEAD is no reason to skip: an @api package that deleted it broke everything it promised.
    if (!is_dir($packageDir . '/.git')) {
        continue;
    }
    $tag = previousTag($packageDir, $version, $problems);
    if ($tag === null) {
        continue;
    }
    $surface = surfaceAt($packageDir, $tag, $problems);
    if ($surface === []) {
        continue; // nothing promised at that tag
    }
    if (sourceUnchanged($packageDir, $tag, $problems)) {
        // Nothing to compare, so nothing can have broken: roave is not run,
        // and a tag it cannot read (core 2026.10.31.0849 declares ThemeValue
        // in ThemeLayer.php) does not fail a cut that did not touch the code.
        $unchanged[] = basename($packageDir) . ": no source change since {$tag}";
        continue;
    }
    $unchecked = null;
    $descriptions = breaks($packageDir, $tag, $check, $problems, $unchecked);
    if ($descriptions === null) {
        $why = $exemptions[basename($packageDir)][$tag] ?? null;
        if ($why === null) {
            $problems[] = basename($packageDir) . ': ' . $unchecked;
        } else {
            $used[basename($packageDir)][$tag] = true;
            $exempted[] = basename($packageDir) . " (since {$tag}): {$unchecked} — exempt: {$why}";
        }
        continue;
    }
    foreach ($descriptions as $description) {
        // By reference: what shippedIn() cannot read must reach the exit code.
        $verdict = judge($description, $surface, $today, static function (string $key) use ($packageDir, $tag, &$problems): array {
            return shippedIn($packageDir, $key, $tag, $problems);
        });
        if ($verdict === null) {
            continue; // not on the @api surface
        }
        [$allowedBreak, $why] = $verdict;
        $line = basename($packageDir) . " (since {$tag}): {$description} — {$why}";
        if ($allowedBreak) {
            $allowed[] = $line;
        } else {
            $refused[] = $line;
        }
    }
}

foreach ($unchanged as $line) {
    echo "[SAME] {$line}\n";
}
foreach ($exempted as $line) {
    echo "[EXEMPT] {$line}\n";
}
foreach ($exemptions as $package => $tags) {
    foreach (array_keys($tags) as $tag) {
        if (!isset($used[$package][$tag])) {
            echo "[UNUSED EXEMPTION] {$package} at {$tag}: nothing needed it; remove it from the exemptions\n";
        }
    }
}
foreach ($allowed as $line) {
    echo "[ALLOWED] {$line}\n";
}
foreach ($refused as $line) {
    echo "[BREAK] {$line}\n";
}
foreach ($problems as $line) {
    fwrite(STDERR, "[UNCHECKED] {$line}\n");
}
if ($refused === [] && $problems === []) {
    echo $allowed === [] ? "No break on the @api surface.\n" : count($allowed) . " break(s), each deprecated long enough.\n";
    exit(0);
}
exit(1);

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/** The date a release version is cut on, or null. */
function cutDate(string $version): ?\DateTimeImmutable
{
    if (preg_match('/^(\d{4}\.\d{2}\.\d{2})(?:\.\d{4})?(?:-beta)?$/', $version, $m) !== 1) {
        return null;
    }
    $date = \DateTimeImmutable::createFromFormat('!Y.m.d', $m[1], new \DateTimeZone('UTC'));

    return $date !== false && $date->format('Y.m.d') === $m[1] ? $date : null;
}

/**
 * @param list<string> $arguments
 * @param-out int $status
 */
function git(string $dir, array $arguments, ?int &$status = null): string
{
    $output = [];
    exec('git -C ' . escapeshellarg($dir) . ' -c core.quotePath=false ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>/dev/null', $output, $status);

    return implode("\n", $output);
}

/**
 * Whether src and composer.json's autoload are exactly as they were at $tag:
 * the only things a class's API can come from. A comparison git cannot make
 * is recorded and counts as changed, so roave still runs.
 *
 * @param list<string> $problems
 */
function sourceUnchanged(string $packageDir, string $tag, array &$problems): bool
{
    git($packageDir, ['diff', '--quiet', $tag, 'HEAD', '--', 'src'], $status);
    if ($status === 1) {
        return false;
    }
    if ($status !== 0) {
        $problems[] = basename($packageDir) . ": src could not be compared with {$tag}";

        return false;
    }
    $before = json_decode(git($packageDir, ['show', $tag . ':composer.json'], $shown), true);
    $now = json_decode(git($packageDir, ['show', 'HEAD:composer.json'], $current), true);
    if ($shown !== 0 || $current !== 0 || !is_array($before) || !is_array($now)) {
        return false;
    }

    return ($before['autoload'] ?? null) === ($now['autoload'] ?? null);
}

/**
 * The newest release tag before this one; null when there is none, or when the
 * listing failed (then recorded).
 *
 * @param list<string> $problems
 */
function previousTag(string $packageDir, string $version, array &$problems): ?string
{
    $out = git($packageDir, ['tag', '--sort=-creatordate', '--list', '20*'], $status);
    if ($status !== 0) {
        $problems[] = basename($packageDir) . ': its tags could not be listed';

        return null;
    }
    foreach (array_map('trim', explode("\n", $out)) as $tag) {
        if ($tag !== '' && $tag !== $version) {
            return $tag;
        }
    }

    return null;
}

/**
 * What was promised at $tag: every class-like marked @api, and every member of
 * one that is public or marked itself (a promoted property too), each with the
 * date its deprecation gives, or null when it was not deprecated.
 *
 * @param list<string> $problems
 * @return array<string, string|null> "Class" or "Class::member" => deprecation date written, or null
 */
function surfaceAt(string $packageDir, string $tag, array &$problems): array
{
    // Cheap first: a tag without one @api in src promised nothing.
    git($packageDir, ['grep', '-q', '-e', '@api', $tag, '--', 'src'], $grep);
    if ($grep === 1) {
        return [];
    }
    if ($grep !== 0) {
        $problems[] = basename($packageDir) . ": its sources at {$tag} could not be searched";

        return [];
    }
    $symbols = symbolsAt($packageDir, $tag, $problems);
    $surface = [];
    foreach ($symbols['api'] as $key) {
        $surface[$key] = $symbols['deprecated'][$key] ?? null;
    }

    return $surface;
}

/**
 * Every class-like and member in src at $tag: which are on the @api surface,
 * and which are deprecated (a member of a deprecated class is), with the date
 * written. Read once per tag.
 *
 * @param list<string> $problems
 * @return array{api: list<string>, deprecated: array<string, string>}
 */
function symbolsAt(string $packageDir, string $tag, array &$problems): array
{
    /** @var array<string, array{api: list<string>, deprecated: array<string, string>}> $cache */
    static $cache = [];
    if (isset($cache[$packageDir . '@' . $tag])) {
        return $cache[$packageDir . '@' . $tag];
    }
    $symbols = ['api' => [], 'deprecated' => []];
    $files = git($packageDir, ['ls-tree', '-r', '--name-only', $tag, '--', 'src'], $status);
    if ($status !== 0) {
        $problems[] = basename($packageDir) . ": its sources at {$tag} could not be listed";

        return $symbols;
    }
    foreach (array_filter(explode("\n", $files), static fn (string $f): bool => str_ends_with($f, '.php')) as $file) {
        $source = git($packageDir, ['show', $tag . ':' . $file], $show);
        if ($show !== 0) {
            $problems[] = basename($packageDir) . ": {$file} at {$tag} could not be read";
            continue;
        }
        try {
            $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
        } catch (\PhpParser\Error $e) {
            $problems[] = basename($packageDir) . ": {$file} at {$tag} does not parse ({$e->getMessage()})";
            continue;
        }
        $ast = (new NodeTraverser(new NameResolver()))->traverse($ast);
        foreach ((new NodeFinder())->findInstanceOf($ast, Stmt\ClassLike::class) as $class) {
            if ($class->namespacedName === null) {
                continue;
            }
            $name = $class->namespacedName->toString();
            $classIsApi = hasTag($class->getDocComment(), 'api');
            $classWritten = deprecatedSince($class->getDocComment());
            if ($classIsApi) {
                $symbols['api'][] = $name;
            }
            if ($classWritten !== null) {
                $symbols['deprecated'][$name] = $classWritten;
            }
            foreach (members($class) as [$member, $doc, $public]) {
                $key = $name . '::' . $member;
                if (hasTag($doc, 'api') || ($classIsApi && $public)) {
                    $symbols['api'][] = $key;
                }
                $written = deprecatedSince($doc) ?? $classWritten;
                if ($written !== null) {
                    $symbols['deprecated'][$key] = $written;
                }
            }
        }
    }

    return $cache[$packageDir . '@' . $tag] = $symbols;
}

/**
 * The release that first shipped the deprecation of $key: the earliest tag,
 * by its version's date, at which that very declaration is deprecated. The
 * 90 days count from it, as the contract says; not from the date written in
 * the tag, which could be backdated, and not from another declaration that
 * happens to carry the same line. The previous tag when none is found (the
 * latest the deprecation can have shipped in).
 *
 * @param list<string> $problems
 * @return array{string, \DateTimeImmutable}
 */
function shippedIn(string $packageDir, string $key, string $previousTag, array &$problems): array
{
    $until = cutDate($previousTag);
    $listed = git($packageDir, ['tag', '--list', '20*'], $status);
    if ($status !== 0) {
        $problems[] = basename($packageDir) . ": its tags could not be listed to date the deprecation of {$key}";
    }
    $tags = [];
    foreach ($status === 0 ? array_filter(array_map('trim', explode("\n", $listed))) : [] as $tag) {
        $date = cutDate($tag);
        if ($date !== null && $until !== null && $date <= $until) {
            $tags[$tag] = $date;
        }
    }
    uksort($tags, static fn (string $a, string $b): int => [$tags[$a], $a] <=> [$tags[$b], $b]);
    foreach ($tags as $tag => $date) {
        if (isset(symbolsAt($packageDir, $tag, $problems)['deprecated'][$key])) {
            return [$tag, $date];
        }
    }

    return [$previousTag, $until ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'))];
}

/**
 * @return list<array{string, ?Doc, bool}> member name, docblock, public
 */
function members(Stmt\ClassLike $class): array
{
    $members = [];
    foreach ($class->stmts as $stmt) {
        if ($stmt instanceof Stmt\ClassMethod) {
            $members[] = [$stmt->name->toString(), $stmt->getDocComment(), $stmt->isPublic()];
            foreach ($stmt->params as $param) {
                if ($param->isPromoted() && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                    $members[] = ['$' . $param->var->name, $param->getDocComment(), $param->isPublic()];
                }
            }
        } elseif ($stmt instanceof Stmt\ClassConst) {
            foreach ($stmt->consts as $const) {
                $members[] = [$const->name->toString(), $stmt->getDocComment(), $stmt->isPublic()];
            }
        } elseif ($stmt instanceof Stmt\Property) {
            foreach ($stmt->props as $property) {
                $members[] = ['$' . $property->name->toString(), $stmt->getDocComment(), $stmt->isPublic()];
            }
        } elseif ($stmt instanceof Stmt\EnumCase) {
            $members[] = [$stmt->name->toString(), $stmt->getDocComment(), true];
        }
    }

    return $members;
}

/**
 * Why roave stopped, from its stderr: the console's error block (the lines
 * after "In <File>.php line <n>:", up to the usage line that follows a blank
 * one), or else the last line. Composer's install log comes before it.
 *
 * @param list<string> $lines
 */
function whatRoaveSaid(array $lines): string
{
    $lines = array_map('trim', $lines);
    $block = [];
    foreach ($lines as $i => $line) {
        if (preg_match('/^In \S+ line \d+:$/', $line) === 1) {
            $block = [];
            for ($j = $i + 1; $j < count($lines); $j++) {
                if ($lines[$j] === '' && $block !== []) {
                    break;
                }
                if ($lines[$j] !== '') {
                    $block[] = $lines[$j];
                }
            }
        }
    }
    if ($block !== []) {
        return implode(' ', $block);
    }
    $nonEmpty = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));

    return $nonEmpty === [] ? '' : $nonEmpty[count($nonEmpty) - 1];
}

/**
 * The operator's exemptions: package dir => tag => why. No file is none; a
 * file that does not read as that shape fails, rather than exempting nothing
 * or something nobody wrote.
 *
 * @return array<string, array<string, string>>
 */
function exemptions(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    // An object, not a list: `[]` decodes to the same empty array as `{}`.
    $decoded = is_string($raw) && json_decode($raw) instanceof \stdClass ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        fail("{$file} is not a JSON object of exemptions.");
    }
    foreach ($decoded as $package => $tags) {
        if (!is_string($package) || !is_array($tags) || $tags === []) {
            fail("{$file}: \"{$package}\" must map tags to reasons.");
        }
        foreach ($tags as $tag => $why) {
            if (!is_string($tag) || !is_string($why) || trim($why) === '') {
                fail("{$file}: {$package} at {$tag} needs the reason it is exempt.");
            }
        }
    }

    /** @var array<string, array<string, string>> $decoded */
    return $decoded;
}

/**
 * Roave's breaks for one package since $tag, as their descriptions; null when
 * roave did not check it, and $unchecked says how (the caller decides whether
 * an exemption covers that).
 *
 * @param list<string> $problems
 * @return list<string>|null
 */
function breaks(string $packageDir, string $tag, string $check, array &$problems, ?string &$unchecked = null): ?array
{
    $unchecked = null;
    // stderr kept apart, so its last line can say why roave stopped.
    $errors = tempnam(sys_get_temp_dir(), 'semitexa-bc-');
    if ($errors === false) {
        $problems[] = basename($packageDir) . ': no temporary file for what roave prints to stderr';

        return [];
    }
    $command = 'cd ' . escapeshellarg($packageDir) . ' && ' . $check . ' --from=' . escapeshellarg($tag) . ' --to=HEAD --format=json 2>' . escapeshellarg($errors);
    $output = [];
    exec($command, $output, $status);
    $said = whatRoaveSaid(file($errors) ?: []);
    @unlink($errors);
    $said = $said === '' ? '' : ': ' . $said;
    // 0: no break; 3: breaks found. Anything else is roave failing to check.
    // The report is the last line that reads as JSON: roave checks out the
    // old tag with composer, which prints "No security vulnerability
    // advisories found." to stdout first, and the whole output never decoded.
    $report = null;
    foreach (array_reverse($output) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $report = $decoded;
            break;
        }
    }
    if (in_array($status, [125, 126, 127], true)) {
        // The shell or docker could not run the checker at all: not roave
        // failing to read a tag, so no exemption may cover it.
        $problems[] = basename($packageDir) . ": the checker could not be run (exit {$status}){$said}";

        return [];
    }
    if (!in_array($status, [0, 3], true)) {
        $unchecked = "roave did not check it (exit {$status}){$said}";

        return null;
    }
    if (!is_array($report) || !is_array($report['errors'] ?? null)) {
        // Roave did check (0 or 3) and its answer is unreadable: not a tag it
        // could not read, so no exemption covers it.
        $problems[] = basename($packageDir) . ": roave checked it (exit {$status}), but its report could not be read";

        return [];
    }
    if ($status === 3 && $report['errors'] === []) {
        // "Breaks found", and none listed: nothing here can be judged.
        $problems[] = basename($packageDir) . ': roave said it found breaks and listed none';

        return [];
    }
    $descriptions = [];
    foreach ($report['errors'] as $i => $error) {
        if (!is_array($error) || !is_string($error['description'] ?? null) || trim($error['description']) === '') {
            // A break it cannot read is not a break it can clear.
            $problems[] = basename($packageDir) . ": roave reported break #{$i} without a description";
            continue;
        }
        $descriptions[] = $error['description'];
    }

    return $descriptions;
}

/**
 * Whether one break touches the promised surface, and if so whether it may
 * ship: [allowed, why], or null when it touches nothing promised.
 *
 * The break is judged by its subject only: the class before `#` or `::` in
 * roave's description (Class#method(), Class#$property, Class::CONSTANT), or,
 * for a class-level break ("Class X has been deleted", "Method m() was added to
 * interface X"), the surface class it names. A class named only as a type in
 * someone else's signature is not the subject. A class removed whole takes
 * every promised member with it.
 *
 * @param array<string, string|null> $surface
 * @param callable(string): array{string, \DateTimeImmutable} $shippedIn
 * @return array{bool, string}|null
 */
function judge(string $description, array $surface, \DateTimeImmutable $today, callable $shippedIn): ?array
{
    $classes = [];
    foreach (array_keys($surface) as $key) {
        $classes[explode('::', $key, 2)[0]] = true;
    }
    if (preg_match('/([A-Za-z_\\\\][\w\\\\]*)(?:#(\$?\w+)|::(\w+))/', $description, $m) === 1) {
        $class = ltrim($m[1], '\\');
        $member = ($m[2] ?? '') !== '' ? $m[2] : $m[3];
        if (array_key_exists($class . '::' . $member, $surface)) {
            $keys = [$class . '::' . $member];
        } elseif (array_key_exists($class, $surface)) {
            $keys = [$class];
        } else {
            return null; // the subject is not promised, whatever its signature mentions
        }
        $whole = false;
    } else {
        // The longest name that appears, so App\Clock is not taken for Clock.
        $names = array_keys($classes);
        usort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $class = null;
        foreach ($names as $name) {
            if (preg_match('/(?<![\w\\\\])' . preg_quote($name, '/') . '(?![\w\\\\])/', $description) === 1) {
                $class = $name;
                break;
            }
        }
        if ($class === null) {
            return null;
        }
        $members = array_values(array_filter(array_keys($surface), static fn (string $k): bool => str_starts_with($k, $class . '::')));
        // A class deleted takes every promised member with it, a member added after
        // the class was deprecated included; a change to the class itself (a method
        // added to an interface) is the class's alone.
        $deleted = preg_match('/has been deleted|was removed/i', $description) === 1;
        if (!array_key_exists($class, $surface) && !$deleted) {
            return null; // a change to a class that is not promised itself (made final, say) changes no promised member
        }
        $keys = array_key_exists($class, $surface) ? ($deleted ? [$class, ...$members] : [$class]) : $members;
        $whole = $deleted || !array_key_exists($class, $surface);
    }
    $reasons = [];
    foreach ($keys as $key) {
        $written = $surface[$key];
        if ($written === null) {
            // Named only when the break is a whole class taking a promised member with it.
            return [false, ($whole && $key !== $class ? $key . ' ' : '') . '@api and never deprecated'];
        }
        [$in, $shipped] = $shippedIn($key);
        $days = (int) $shipped->diff($today)->format('%r%a');
        if ($days < WINDOW_DAYS) {
            return [false, ($whole && $key !== $class ? $key . ' ' : '') . "@api, deprecated {$written}, shipped in {$in}: only {$days} of " . WINDOW_DAYS . ' days ago'];
        }
        $reasons[] = "deprecated {$written}, shipped in {$in}, {$days} days ago";
    }

    return [true, implode('; ', array_unique($reasons))];
}

/** A tag opens a docblock line up to four spaces in, as semitexa.deprecatedApi reads it; further in is prose. */
function hasTag(?Doc $doc, string $tag): bool
{
    return $doc !== null && preg_match('/^[ \t]*(?:\*[ \t]{0,4}|\/\*\*[ \t]{0,4})@' . $tag . '\b/m', $doc->getText()) === 1;
}

/** The date a @deprecated tag gives ('(no date)' without one), or null without the tag. */
function deprecatedSince(?Doc $doc): ?string
{
    if ($doc === null || preg_match('/^[ \t]*(?:\*[ \t]{0,4}|\/\*\*[ \t]{0,4})@deprecated\b[ \t]*(\S*)/m', $doc->getText(), $m) !== 1) {
        return null;
    }

    return $m[1] !== '' && $m[1] !== '*/' ? $m[1] : '(no date)';
}

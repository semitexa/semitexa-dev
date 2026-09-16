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

    $unsatisfiable = checkFloorsAreSatisfiable($packagesDir);
    if ($unsatisfiable !== []) {
        fwrite(STDERR, "A floor names a release that does not contain what this package imports:\n");
        foreach ($unsatisfiable as $problem) {
            fwrite(STDERR, '- ' . $problem . "\n");
        }
        fwrite(STDERR, "\nRaise the floor to the release that first shipped the class, or stop importing it.\n");
        exit(1);
    }

    echo "[OK] Every internal floor names a release that actually contains the classes its package imports.\n";
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

/**
 * A FLOOR THAT NAMES A RELEASE WITHOUT THE CLASS IS WORSE THAN NO FLOOR.
 *
 * The form check above asks whether a constraint is spelled correctly. This
 * asks the question that actually matters: `semitexa/ssr` floored core at the
 * release before the one that introduced `Semitexa\Core\Support\Row`, while
 * importing Row in thirteen files. Composer resolves that happily and the
 * worker dies on `Class not found` at the first request — which is the exact
 * failure the floor exists to turn into a resolution error.
 *
 * A human found that in review. Nothing here would have.
 *
 * The check: for every `>=` floor on a sibling, read the classes this package
 * IMPORTS from that sibling's namespace, and confirm each one's file exists in
 * the sibling AT THAT TAG. One `git ls-tree` per (package, tag) rather than a
 * lookup per import — the tree is read once and membership tested in memory.
 *
 * @return list<string> one sentence per unsatisfiable import
 */
function checkFloorsAreSatisfiable(string $packagesDir): array
{
    $packages = indexPackages($packagesDir);
    $problems = [];

    foreach ($packages as $name => $package) {
        // A FLOOR and an EXACT PIN are both promises about a specific release,
        // so both are verified the same way. An exact pin used to be recorded
        // as neither floor nor wildcard and was therefore never inspected at
        // all — a package pinned to a release predating a class it imports
        // passed the gate and failed at the first request.
        foreach ($package['promises'] as $dependency => $promise) {
            $target = $packages[$dependency] ?? null;
            if ($target === null) {
                // Not in this workspace; nothing local to verify against.
                continue;
            }

            $problems = array_merge($problems, verifyAgainstTag(
                $name,
                $package['dir'],
                $dependency,
                $target,
                $promise['version'],
                $promise['kind'],
                $package['roots'],
                $package['files'],
            ));
        }

        // `*` IS THE ONE FORM THAT PROMISES NOTHING, which is why the checks
        // above skip it — and exactly why it needs one of its own.
        //
        // A package that calls a sibling's BRAND-NEW class while requiring it
        // at `*` resolves against every released version, including the ones
        // without that class. semitexa/mail called SandboxGuard while requiring
        // semitexa/core at `*`: composer accepts a lockfile with yesterday's
        // core, and every send fatals on `Class not found` — outside a sandbox
        // too. A reviewer found that; the floor check waved it through.
        //
        // The narrowest statement that is always true: if the class exists in
        // NO released version of the sibling, the constraint cannot be
        // satisfied by anything on Packagist today. That is a fact, not a
        // judgement about which versions a consumer might pick.
        foreach ($package['wildcards'] as $dependency) {
            $target = $packages[$dependency] ?? null;
            if ($target === null) {
                continue;
            }

            $latest = latestReleaseTag($target['dir']);
            if ($latest === null) {
                // Nothing released yet; there is no version to be wrong about.
                continue;
            }

            $problems = array_merge($problems, verifyAgainstTag(
                $name,
                $package['dir'],
                $dependency,
                $target,
                $latest,
                'wildcard',
                $package['roots'],
                $package['files'],
            ));
        }
    }

    return $problems;
}

/**
 * Check every class one package uses from another against one tag of it.
 *
 * @param array{dir: string, psr4: array<string, list<string>>} $target
 * @param 'floor'|'pin'|'wildcard' $kind
 * @return list<string>
 */
function verifyAgainstTag(
    string $name,
    string $packageDir,
    string $dependency,
    array $target,
    string $version,
    string $kind,
    array $consumerRoots = ['src'],
    array $consumerFiles = [],
): array {
    $problems = [];

    $tree = treeAtTag($target['dir'], $version);
    $psr4 = $tree === null ? null : psr4AtTag($target['dir'], $version);

    // THE RELEASE BEING CUT HAS NO TAG YET, and this gate runs in preflight —
    // before release-finalize.sh creates any. Without this, a package that
    // starts calling a sibling API introduced in the SAME coordinated release
    // has no constraint that can pass: `*` fails because no release contains
    // the class, and a floor at the planned version fails because that tag does
    // not exist. The release could not reach finalize at all.
    //
    // Only the version the release flow explicitly names in RELEASE_VERSION is
    // treated this way, and only against the synchronized master tree that is
    // about to BECOME that tag. A floor on any other absent tag still fails
    // closed — a typo must not be answered by "well, the class is here now".
    $plannedVersion = trim((string) getenv('RELEASE_VERSION'));
    if ($tree === null && $plannedVersion !== '' && $version === $plannedVersion) {
        $tree = workingTree($target['dir']);
        $psr4 = $target['psr4'];
        if ($tree !== null && $psr4 !== []) {
            return verifyAgainstTree($name, $packageDir, $dependency, $target, $version, 'planned', $tree, $psr4, $consumerRoots, $consumerFiles);
        }
        $tree = null;
    }

    if ($tree === null) {
        if ($kind === 'wildcard') {
            return [];
        }

        return [sprintf(
            '%s %s %s at %s, but that tag is not in %s — fetch tags, or it names a release that does not exist',
            $name,
            $kind === 'pin' ? 'pins' : 'floors',
            $dependency,
            $version,
            basename($target['dir']),
        )];
    }

    // THE MAP MUST COME FROM THE SAME TAG AS THE TREE. Today's autoload block
    // describes today's layout; if the provider moved its sources since that
    // release, mapping a class through the current map and looking it up in the
    // OLD tree compares two different layouts.
    //
    // And when the tagged map cannot be read, the gate FAILS rather than
    // falling back to the current one — falling back is the same mixing of
    // revisions in a quieter form. Something the gate cannot see is not
    // something it approves.
    if ($psr4 === null) {
        return [sprintf(
            '%s names %s at %s, but that tag has no readable autoload.psr-4 map — '
            . 'nothing can be verified against it',
            $name,
            $dependency,
            $version,
        )];
    }

    return verifyAgainstTree($name, $packageDir, $dependency, $target, $version, $kind, $tree, $psr4, $consumerRoots, $consumerFiles);
}

/**
 * The comparison itself, once a tree and a matching autoload map are in hand.
 *
 * @param array{dir: string, psr4: array<string, list<string>>} $target
 * @param list<string> $tree
 * @param array<string, list<string>> $psr4
 * @return list<string>
 */
function verifyAgainstTree(
    string $name,
    string $packageDir,
    string $dependency,
    array $target,
    string $version,
    string $kind,
    array $tree,
    array $psr4,
    array $consumerRoots = ['src'],
    array $consumerFiles = [],
): array {
    $problems = [];
    $declared = declaredClasses($target['dir'], $kind === 'planned' ? null : $version, $psr4);

    // DISCOVERY uses today's prefixes as well as the tagged ones. Which classes
    // belong to this dependency is a question about the namespaces a consumer
    // can be referencing NOW; a prefix the provider added after the floored tag
    // exists in no tagged map, so every reference under it would be skipped and
    // never compared with what that tag declares. Verification still happens
    // against the tagged declarations — only the "does this belong to them"
    // question is asked of the wider map.
    $discoveryPsr4 = $psr4;
    foreach ($target['psr4'] as $prefix => $dirs) {
        $discoveryPsr4[$prefix] ??= $dirs;
    }

    foreach (importsFrom($packageDir, $discoveryPsr4, $consumerRoots, $consumerFiles) as $class => $candidatePaths) {
        if (isset($declared[$class])) {
            continue;
        }

        $where = $candidatePaths[0] ?? '(unmapped)';

        $problems[] = match ($kind) {
            'wildcard' => sprintf(
                '%s uses %s but requires %s at "*", and NO released %s declares it '
                . '(newest is %s; expected %s) — floor it at the release that ships the class',
                $name,
                $class,
                $dependency,
                $dependency,
                $version,
                $where,
            ),
            'planned' => sprintf(
                '%s uses %s and floors %s at %s, the release being cut, but the tree that is about '
                . 'to become it does not declare it (expected %s)',
                $name,
                $class,
                $dependency,
                $version,
                $where,
            ),
            default => sprintf(
                '%s uses %s but %s %s at %s, which does not declare it (expected %s)',
                $name,
                $class,
                $kind === 'pin' ? 'pins' : 'floors',
                $dependency,
                $version,
                $where,
            ),
        };
    }

    foreach (templateContractProblems($name, $packageDir, $dependency, $target, $version, $kind) as $problem) {
        $problems[] = $problem;
    }

    return $problems;
}

/**
 * The same question, asked of the TEMPLATE contract.
 *
 * A provider owns more than classes. A Twig function is reached from a
 * template, with no import and no class name, so the PHP scan above walks past
 * it — and the first time that mattered the missing floor was found by hand,
 * not by this gate. The rule is identical: if a consumer calls a function the
 * dependency registers TODAY, the release it floored has to register it too.
 *
 * Ownership is decided by the provider, not guessed from the name: a call this
 * finds is only ever compared against the functions THIS dependency registers.
 * A Twig builtin, or the consumer's own helper, matches nothing and costs
 * nothing.
 *
 * @param array{dir: string, psr4: array<string, list<string>>} $target
 * @return list<string>
 */
function templateContractProblems(
    string $name,
    string $packageDir,
    string $dependency,
    array $target,
    string $version,
    string $kind,
): array {
    $calls = twigFunctionCalls($packageDir);
    if ($calls === []) {
        return [];
    }

    $ownedNow = registeredTwigFunctions($target['dir'], null);
    if ($ownedNow === []) {
        return [];
    }

    $ownedThen = registeredTwigFunctions($target['dir'], $kind === 'planned' ? null : $version);

    $problems = [];
    foreach (array_keys($calls) as $function) {
        if (!isset($ownedNow[$function]) || isset($ownedThen[$function])) {
            continue;
        }

        $problems[] = match ($kind) {
            'wildcard' => sprintf(
                '%s calls the Twig function %s() from a template but requires %s at "*", and no '
                . 'released %s registers it (newest is %s) — floor it at the release that ships the function',
                $name,
                $function,
                $dependency,
                $dependency,
                $version,
            ),
            'planned' => sprintf(
                '%s calls the Twig function %s() and floors %s at %s, the release being cut, but the '
                . 'tree that is about to become it does not register it',
                $name,
                $function,
                $dependency,
                $version,
            ),
            default => sprintf(
                '%s calls the Twig function %s() but %s %s at %s, which does not register it',
                $name,
                $function,
                $kind === 'pin' ? 'pins' : 'floors',
                $dependency,
                $version,
            ),
        };
    }

    return $problems;
}

/**
 * Twig functions a package REGISTERS at a revision.
 *
 * WHY THIS EXISTS. The gate's whole question is "does the release you floored
 * declare what you use", and until now "what you use" meant a PHP class. A
 * provider owns more than classes: a Twig function is a contract a consumer
 * reaches for from a TEMPLATE, with no import and no class name anywhere. The
 * first case cost a floor found by hand — semitexa-os reaching the prompt
 * package's guidance — and the second arrived the same week, with theme and
 * demo calling ssr's csp_nonce_attr(). Neither is visible to a PHP scan.
 *
 * A function is checkable because it has a REGISTRATION SITE: the provider
 * names it in a call the scan can find at any revision, exactly as a class
 * names itself in its declaration.
 *
 * WHAT IS STILL INVISIBLE, stated so nobody reads this as covering templates:
 * a Twig VARIABLE the provider binds into the render context has no
 * registration site at all — `guidance` is a string in one package and a
 * string in another, and nothing declares it. So is a template NAMESPACE a
 * consumer extends. Those remain a known limit of this gate rather than
 * something it silently half-checks.
 *
 * @param string|null $tag null reads the working tree — the release being cut
 * @return array<string, true> function name => true
 */
function registeredTwigFunctions(string $dir, ?string $tag): array
{
    // The provider's OWN production roots, read from its composer autoload
    // map at the revision being checked — not a hard-coded `src`. A package
    // that maps `lib/` registers its functions there, and a scan that never
    // looks finds no names at all, which makes this check skip that provider
    // in silence.
    //
    // Without any pathspec the scan also reads tests, fixtures and README
    // examples, and a name that appears only in one of those becomes a
    // runtime contract: a release blocked by a documentation snippet.
    $paths = autoloadRoots($dir, $tag);
    if ($paths === []) {
        return [];
    }

    $files = sprintf(
        'git -C %s grep -l %s %s-- %s 2>/dev/null',
        escapeshellarg($dir),
        escapeshellarg('registerFunction'),
        $tag === null ? '' : escapeshellarg($tag) . ' ',
        implode(' ', array_map('escapeshellarg', $paths)),
    );

    exec($files, $lines, $code);
    if ($code !== 0) {
        return [];
    }

    $functions = [];
    foreach ($lines as $line) {
        // With a tag, `git grep -l` prints `<tag>:<path>`.
        $path = $tag === null ? $line : substr($line, strpos($line, ':') + 1);
        if ($path === '' || !str_ends_with($path, '.php')) {
            continue;
        }

        // The whole FILE, not one line of it. A line-oriented scan misses a
        // registration whose name sits on the next line — which is how a long
        // argument list gets formatted — and the provider then looks as though
        // it registers nothing at all, which makes the gate skip it silently.
        $contents = $tag === null
            ? (string) @file_get_contents($dir . '/' . $path)
            : shellOutput(sprintf('git -C %s show %s 2>/dev/null', escapeshellarg($dir), escapeshellarg($tag . ':' . $path)));

        if ($contents === '') {
            continue;
        }

        foreach (registrationNames($contents) as $name) {
            $functions[$name] = true;
        }
    }

    return $functions;
}

/**
 * Names passed to a real `registerFunction('x', …)` CALL.
 *
 * Tokens rather than a regex over the file, because the regex counted the same
 * text in a comment or an ordinary string. That direction fails CLOSED in the
 * worst way: a name that only a docblock mentions is added to what the FLOORED
 * release "registers", so the gate decides the floor is satisfied and lets a
 * consumer call a function that release never shipped.
 *
 * @return list<string>
 */
function registrationNames(string $contents): array
{
    if (!str_contains($contents, 'registerFunction')) {
        return [];
    }

    $tokens = @token_get_all($contents);
    $count = count($tokens);
    $names = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'registerFunction') {
            continue;
        }

        // `(` then a quoted string, stepping over whitespace AND comments:
        // `registerFunction(/* the public name */ 'csp_nonce_attr', …)` is a
        // real registration, and skipping only whitespace missed it — which
        // credits the floored release with a function it does not have.
        $j = nextCodeToken($tokens, $i + 1, $count);
        if ($j >= $count || $tokens[$j] !== '(') {
            continue;
        }

        $j = nextCodeToken($tokens, $j + 1, $count);
        if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $name = trim($tokens[$j][1], "'\"");
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1) {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * The next token_get_all() entry that is neither whitespace nor a comment.
 *
 * Named apart from nextMeaningfulToken() further down, which answers the same
 * question about PhpToken objects for a different scan.
 */
function nextCodeToken(array $tokens, int $from, int $count): int
{
    for ($j = $from; $j < $count; $j++) {
        if (!is_array($tokens[$j])) {
            return $j;
        }
        if ($tokens[$j][0] !== T_WHITESPACE && $tokens[$j][0] !== T_COMMENT && $tokens[$j][0] !== T_DOC_COMMENT) {
            return $j;
        }
    }

    return $count;
}

/**
 * A package's own source roots at a revision, from its composer autoload map.
 *
 * `resources` is added because a package that ships assets rather than modules
 * keeps templates and registrations outside its PSR-4 roots, and that is the
 * case that raised the template contract in the first place.
 *
 * @return list<string>
 */
function autoloadRoots(string $dir, ?string $tag): array
{
    $raw = $tag === null
        ? (string) @file_get_contents($dir . '/composer.json')
        : shellOutput(sprintf('git -C %s show %s 2>/dev/null', escapeshellarg($dir), escapeshellarg($tag . ':composer.json')));

    $roots = [];
    $manifest = $raw === '' ? null : json_decode($raw, true);

    if (is_array($manifest)) {
        // `autoload` ONLY. A function registered from an autoload-dev root is
        // not loaded from a production dependency, so counting it would let a
        // consumer floor a release that cannot actually give it the function.
        foreach (['autoload'] as $section) {
            foreach ((array) ($manifest[$section]['psr-4'] ?? []) as $paths) {
                foreach ((array) $paths as $path) {
                    $roots[] = trim((string) $path, '/') ?: '.';
                }
            }
            foreach ((array) ($manifest[$section]['files'] ?? []) as $file) {
                $roots[] = trim((string) $file, '/');
            }
        }
    }

    $roots[] = 'src';
    $roots[] = 'resources';

    return array_values(array_unique(array_filter($roots, static fn (string $p): bool => $p !== '')));
}

/** stdout of a command, or '' when it fails. */
function shellOutput(string $command): string
{
    $out = [];
    $code = 0;
    exec($command, $out, $code);

    return $code === 0 ? implode("\n", $out) : '';
}

/**
 * Twig function names a package's own templates CALL.
 *
 * Scans `.twig` under both roots a package can keep templates in: `src` for a
 * module-shaped package and `resources` for one that ships assets — the second
 * is where the case that raised this lives.
 *
 * Deliberately generous about what looks like a call, and deliberately NOT
 * authoritative about whose call it is: the caller decides that by asking
 * which names the DEPENDENCY registers. A name this returns that nobody
 * registers is simply never matched, so a Twig builtin or the consumer's own
 * helper costs nothing here.
 *
 * Generous is not the same as indiscriminate, and the difference matters
 * because this gate BLOCKS A RELEASE. Text a template merely prints is not a
 * call: `{# use new_fn() after upgrading #}`, a name inside a string, a line
 * of JavaScript. Read as calls, each of those demands a floor for a function
 * the template never invokes, and the release stops on a comment.
 *
 * @return array<string, true> function name => true
 */
/**
 * A template with everything Twig does not EXECUTE blanked out.
 *
 * `{# … #}` emits nothing; text outside `{{ … }}` and `{% … %}` is printed
 * verbatim, whatever it spells. Replaced by spaces of the same length so
 * nothing shifts.
 */
function executableTwig(string $source): string
{
    $source = (string) preg_replace_callback(
        '/\{#.*?#\}/s',
        static fn (array $m): string => str_repeat(' ', strlen($m[0])),
        $source,
    );

    // Quoted runs are blanked BEFORE the block boundaries are found, not
    // after: `{{ "}}" ~ csp_nonce_attr() }}` ends its first block inside the
    // string otherwise, and the real call after it is discarded — an
    // insufficient floor then passes preflight.
    $masked = (string) preg_replace_callback(
        '/"[^"]*"|\'[^\']*\'/',
        static function (array $m): string {
            // A double-quoted Twig string can INTERPOLATE: `"nonce=#{fn()}"`
            // really does call fn(). Blanking the whole literal hid the call
            // and let a template use a function without the floor for it.
            // Single quotes do not interpolate, so they are blanked whole.
            if ($m[0][0] !== '"' || !str_contains($m[0], '#{')) {
                return preg_replace('/[^\n]/', ' ', $m[0]) ?? '';
            }

            return (string) preg_replace_callback(
                '/#\{[^}]*\}|[^\n]/',
                static fn (array $p): string => str_starts_with($p[0], '#{') ? $p[0] : ' ',
                $m[0],
            );
        },
        $source,
    );

    $kept = str_repeat(' ', strlen($source));
    if (preg_match_all('/\{\{.*?\}\}|\{%.*?%\}/s', $masked, $matches, PREG_OFFSET_CAPTURE) === false) {
        return $kept;
    }

    // The blocks are taken from the MASKED copy, so a string literal inside an
    // executable block is already blank: `{{ "use new_fn() after upgrading" }}`
    // prints a sentence and calls nothing, and left readable that sentence
    // demanded a floor and stopped a release.
    foreach ($matches[0] as [$block, $offset]) {
        $kept = substr_replace($kept, (string) $block, (int) $offset, strlen((string) $block));
    }

    return $kept;
}

function twigFunctionCalls(string $packageDir): array
{
    $calls = [];

    foreach (['src', 'resources'] as $root) {
        $dir = $packageDir . '/' . $root;
        if (!is_dir($dir)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }

            $contents = executableTwig((string) file_get_contents($file->getPathname()));

            // Not preceded by a `.`: `page.asset()` is a method on a value the
            // template was handed, not the global function of the same name,
            // and reading it as one demanded a floor for a dependency the
            // template never calls.
            if (preg_match_all('/(?<![\w.])([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $contents, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $name) {
                $calls[$name] = true;
            }
        }
    }

    return $calls;
}

/**
 * EVERY class a package declares at a revision, as fully qualified names.
 *
 * The gate used to ask "does the PSR-4 path exist in the tree", which is a
 * proxy, and each way the proxy was wrong needed its own patch: a class
 * declared in a sibling file (Semitexa\Core\Tenant\Layer\ThemeValue lives
 * inside ThemeLayer.php, so no ThemeValue.php exists in any release), a
 * same-named class in an unrelated namespace answering for the real one, and a
 * file that exists at that revision but does not yet declare the class it
 * later would. Composer loads the file and still raises `Class not found`.
 *
 * Asking what is DECLARED answers all three at once, and costs one `git grep`
 * per (package, revision) rather than one lookup per import.
 *
 * @param array<string, list<string>> $psr4
 * @param string|null $tag null reads the working tree — the release being cut
 * @return array<string, true> FQCN => true
 */
function declaredClasses(string $dir, ?string $tag, array $psr4): array
{
    $paths = [];
    foreach ($psr4 as $dirs) {
        foreach ($dirs as $sourceDir) {
            $paths[] = escapeshellarg($sourceDir);
        }
    }

    $command = sprintf(
        'git -C %s grep -nE %s %s--%s 2>/dev/null',
        escapeshellarg($dir),
        escapeshellarg('^ *(namespace |(final |abstract |readonly |final readonly )*(class|interface|trait|enum) )'),
        $tag === null ? '' : escapeshellarg($tag) . ' ',
        $paths === [] ? '' : ' ' . implode(' ', $paths),
    );

    exec($command, $lines, $code);
    if ($code !== 0) {
        return [];
    }

    $declared = [];
    $namespaceByFile = [];

    foreach ($lines as $line) {
        // `git grep -n` prints `<rev>:<path>:<line>:<text>`, or `<path>:<line>:<text>`
        // without a revision. Split from the LEFT by the known number of fields
        // so a colon inside the text cannot confuse it.
        $parts = explode(':', $line, $tag === null ? 3 : 4);
        if (count($parts) < ($tag === null ? 3 : 4)) {
            continue;
        }
        $path = $tag === null ? $parts[0] : $parts[1];
        $text = trim($tag === null ? $parts[2] : $parts[3]);

        // `\\\\` and not `\\`: PHP turns '\\' into a single backslash, which
        // then escapes the `]` and leaves an unterminated character class —
        // preg_match warns and returns false, so EVERY namespace line is
        // skipped and every class is recorded unqualified. Silent, and it
        // makes the whole index useless.
        if (preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+)\s*[;{]/', $text, $m) === 1) {
            $namespaceByFile[$path] = trim($m[1], '\\');
            continue;
        }

        if (preg_match(
            '/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/',
            $text,
            $m,
        ) !== 1) {
            continue;
        }

        $namespace = $namespaceByFile[$path] ?? '';
        $declared[$namespace === '' ? $m[1] : $namespace . '\\' . $m[1]] = true;
    }

    return $declared;
}

/**
 * Every tracked path in a package's current checkout, or null when it is not a
 * readable git repository.
 *
 * @return list<string>|null
 */
function workingTree(string $dir): ?array
{
    $command = sprintf('git -C %s ls-files 2>/dev/null', escapeshellarg($dir));
    exec($command, $lines, $code);

    return $code === 0 && $lines !== [] ? $lines : null;
}

/**
 * The newest release tag in a package, or null when nothing is released.
 *
 * Date-based tags (`YYYY.MM.DD.HHMM`) sort correctly as strings, so the newest
 * is the last one. Anything that is not a release tag is ignored rather than
 * ranked.
 */
function latestReleaseTag(string $dir): ?string
{
    $command = sprintf('git -C %s tag --list 2>/dev/null', escapeshellarg($dir));
    exec($command, $lines, $code);
    if ($code !== 0) {
        return null;
    }

    $releases = [];
    foreach ($lines as $tag) {
        $tag = trim($tag);
        if (preg_match('/^\d{4}\.\d{2}\.\d{2}\.\d{4}$/', $tag) === 1) {
            $releases[] = $tag;
        }
    }

    if ($releases === []) {
        return null;
    }

    sort($releases);

    return (string) end($releases);
}

/**
 * @return array<string, array{dir: string, psr4: array<string, list<string>>, roots: list<string>, files: list<string>, promises: array<string, array{version: string, kind: string}>, wildcards: list<string>}>
 */
function indexPackages(string $packagesDir): array
{
    $packages = [];

    foreach (glob($packagesDir . '/*/composer.json') ?: [] as $composerPath) {
        $json = json_decode((string) file_get_contents($composerPath), true);
        if (!is_array($json) || !is_string($json['name'] ?? null)) {
            continue;
        }

        $promises = [];
        $wildcards = [];
        // BOTH sections, matching the form check above. Reading only `require`
        // left a require-dev floor accepted as well-formed by one pass and
        // verified by neither.
        foreach (['require', 'require-dev'] as $section) {
            foreach ($json[$section] ?? [] as $dependency => $constraint) {
                if (!is_string($dependency) || !is_string($constraint) || !str_starts_with($dependency, 'semitexa/')) {
                    continue;
                }

                $version = '\d{4}\.\d{2}\.\d{2}\.\d{4}(?:-[a-z0-9]+)?';

                // TRIMMED first, like the form check does. A constraint with
                // leading whitespace is valid to composer and was accepted as
                // well-formed, but these anchored patterns missed it — so it
                // was recorded as neither promise nor wildcard and verified by
                // nothing at all.
                $constraint = trim($constraint);

                // The `|| dev-master` escape is not part of the promise being
                // checked; the floor is.
                if (preg_match('/^>=\s*(' . $version . ')/i', $constraint, $m) === 1) {
                    $promises[$dependency] = ['version' => $m[1], 'kind' => 'floor'];
                } elseif (preg_match('/^(' . $version . ')$/i', $constraint, $m) === 1) {
                    $promises[$dependency] = ['version' => $m[1], 'kind' => 'pin'];
                } elseif ($constraint === '*' && !isset($promises[$dependency])) {
                    $wildcards[] = $dependency;
                }
            }
        }

        $ownPsr4 = normalizePsr4($json['autoload']['psr-4'] ?? null) ?? [];

        // The package's OWN source roots, from its autoload block. Hardcoding
        // `src` missed production code a package autoloads from anywhere else,
        // and such code could use a sibling class absent from the promised
        // release while the gate reported success.
        // autoload-dev too, because promises are collected from require-dev as
        // well: a floor can predate a class the package's own tests use, and
        // scanning only the production roots would never see it.
        $devPsr4 = normalizePsr4($json['autoload-dev']['psr-4'] ?? null) ?? [];

        $roots = [];
        foreach ([$ownPsr4, $devPsr4] as $map) {
            foreach ($map as $dirs) {
                foreach ($dirs as $dir) {
                    $roots[] = $dir;
                }
            }
        }

        // `autoload.files` is PHP composer EXECUTES on load, and it need not
        // sit under any PSR-4 root — a root bootstrap.php that touches a
        // sibling class is the case. Scanning only the mapped directories left
        // such a file unread, so the promised tag could lack the class and the
        // gate still report success.
        $files = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach ($json[$section]['files'] ?? [] as $file) {
                if (is_string($file) && $file !== '') {
                    $files[] = $file;
                }
            }
        }

        $packages[$json['name']] = [
            'dir' => dirname($composerPath),
            'psr4' => $ownPsr4,
            'roots' => $roots === [] ? ['src'] : array_values(array_unique($roots)),
            'files' => array_values(array_unique($files)),
            'promises' => $promises,
            'wildcards' => $wildcards,
        ];
    }

    return $packages;
}

/**
 * A PSR-4 block as prefix => LIST of source directories.
 *
 * Composer lets one prefix map to several directories. Keeping only
 * string-valued entries silently dropped such a prefix, and every class under
 * it then went unmapped and unchecked — a fail-open in a gate that exists to
 * fail closed.
 *
 * @return array<string, list<string>>|null null when there is no usable map
 */
function normalizePsr4(mixed $map): ?array
{
    if (!is_array($map)) {
        return null;
    }

    $psr4 = [];
    foreach ($map as $prefix => $paths) {
        if (!is_string($prefix)) {
            continue;
        }
        foreach ((array) $paths as $path) {
            if (!is_string($path)) {
                continue;
            }
            // `"Semitexa\\Ssr\\": ""` is valid and means the PACKAGE ROOT.
            // Left as an empty string it was dropped as "no directory", so a
            // package that maps its namespace to its own root was scanned at
            // `src` instead — or not at all — and the files that actually hold
            // its code went unread.
            $psr4[$prefix][] = rtrim($path, '/') === '' ? '.' : rtrim($path, '/');
        }
    }

    return $psr4 === [] ? null : $psr4;
}

/**
 * Every path in a package at one tag, or null when the tag is not there.
 *
 * @return list<string>|null
 */
function treeAtTag(string $dir, string $tag): ?array
{
    $command = sprintf(
        'git -C %s ls-tree -r --name-only %s 2>/dev/null',
        escapeshellarg($dir),
        escapeshellarg($tag),
    );

    exec($command, $lines, $code);

    return $code === 0 && $lines !== [] ? $lines : null;
}

/**
 * The PSR-4 map as it stood AT a tag, or null when the tag has no readable
 * composer.json (in which case the caller keeps today's map rather than
 * treating every import as unresolvable).
 *
 * @return array<string, list<string>>|null namespace prefix => source directories
 */
function psr4AtTag(string $dir, string $tag): ?array
{
    $command = sprintf(
        'git -C %s show %s 2>/dev/null',
        escapeshellarg($dir),
        escapeshellarg($tag . ':composer.json'),
    );

    exec($command, $lines, $code);
    if ($code !== 0 || $lines === []) {
        return null;
    }

    $json = json_decode(implode("\n", $lines), true);
    if (!is_array($json)) {
        return null;
    }

    return normalizePsr4($json['autoload']['psr-4'] ?? null);
}

/**
 * Classes this package imports from another's namespaces, as tag-relative
 * paths.
 *
 * `use function` and `use const` are skipped: they are not class files, and a
 * floor that guarantees a class says nothing about them either way.
 *
 * @param array<string, list<string>> $psr4 namespace prefix => source directories
 * @return array<string, list<string>> FQCN => candidate paths in the target package
 */
function importsFrom(string $packageDir, array $psr4, array $scanRoots = ['src'], array $scanFiles = []): array
{
    $found = [];

    foreach (array_unique($scanFiles) as $relativeFile) {
        $path = $packageDir . '/' . $relativeFile;
        if (is_file($path)) {
            collectReferences((string) file_get_contents($path), $psr4, $found);
        }
    }

    foreach (array_unique($scanRoots) as $root) {
        $sourceDir = $packageDir . '/' . $root;
        if (!is_dir($sourceDir)) {
            continue;
        }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir));
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        collectReferences((string) file_get_contents($file->getPathname()), $psr4, $found);
    }
    }

    return $found;
}

/**
 * Map one file's references onto candidate paths in the target package.
 *
 * @param array<string, list<string>> $psr4
 * @param array<string, list<string>> $found accumulated by reference
 */
function collectReferences(string $contents, array $psr4, array &$found): void
{
    foreach (usedClasses($contents) as $class) {
        // LONGEST prefix wins, as composer resolves it. Taking the first match
        // in declaration order meant a generic `Semitexa\Core\` declared
        // before `Semitexa\Core\Special\` mapped a Special class through the
        // generic one — a path composer would never load, and a floor approved
        // on the strength of a file that is not the file.
        $bestPrefix = null;
        foreach ($psr4 as $prefix => $dirs) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            if ($bestPrefix === null || strlen($prefix) > strlen($bestPrefix)) {
                $bestPrefix = $prefix;
            }
        }
        if ($bestPrefix === null) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($bestPrefix)));
        foreach ($psr4[$bestPrefix] as $dir) {
            $found[$class][] = ($dir === '.' ? '' : $dir . '/') . $relative . '.php';
        }
    }
}

/**
 * Every class of another package this file names — imported OR written out.
 *
 * An import is not the only way to reach a sibling's class.
 * `new \Semitexa\Core\Support\Row()` names it just as surely, and this
 * workspace has 140 distinct cross-package references written that way. A scan
 * that only entered on `use` was blind to every one of them, so a class added
 * after the floored release could be called from a fully-qualified reference
 * and the gate would still report success.
 *
 * @return list<string>
 */
function usedClasses(string $contents): array
{
    $imports = importedClasses($contents);
    $aliasesByBlock = $imports['aliases'];
    $block = 0;
    $classes = [];
    $tokens = PhpToken::tokenize($contents);
    $usedAsPrefix = [];
    $fileNamespace = '';

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];

        if ($token->is(T_NAMESPACE)) {
            // The same counter importedClasses() keeps, over the same token
            // sequence, so a block's aliases are the ones its own imports set.
            $block++;
            $fileNamespace = '';
            for ($j = $i + 1, $n2 = count($tokens); $j < $n2; $j++) {
                $text = $tokens[$j]->text;
                if ($text === ';' || $text === '{') {
                    break;
                }
                if ($tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                    continue;
                }
                $fileNamespace .= $text;
            }
            $fileNamespace = trim($fileNamespace, '\\');
            continue;
        }

        if ($token->is(T_NAME_QUALIFIED)) {
            // `CoreSupport\Row` after `use Semitexa\Core\Support as CoreSupport;`
            // — a NAMESPACE alias. Recording the import itself would look for
            // Support.php, which does not exist and never did; the class the
            // file actually reaches is the resolved one.
            $segments = explode('\\', $token->text);
            $head = strtolower((string) array_shift($segments));
            $aliases = $aliasesByBlock[$block] ?? [];
            if (isset($aliases[$head]) && $segments !== []) {
                $usedAsPrefix[$aliases[$head]] = true;
                $classes[$aliases[$head] . '\\' . implode('\\', $segments)] = true;
                continue;
            }

            // In the GLOBAL namespace a qualified name resolves exactly as
            // written, so `new Semitexa\Core\Support\Row()` with no leading
            // slash names the sibling class. Inside a namespace it would mean
            // something relative to that namespace instead, which is never the
            // sibling — so this applies only when there is no namespace.
            if ($fileNamespace === '' && str_contains($token->text, '\\')) {
                $classes[ltrim($token->text, '\\')] = true;
            }
            continue;
        }

        // T_NAME_FULLY_QUALIFIED is `\Foo\Bar` wherever it appears — a `new`,
        // a static call, a type, an attribute, a catch. Relative names are not
        // collected beyond the alias case above: they resolve through the
        // file's own imports, which this already has.
        if (!$token->is(T_NAME_FULLY_QUALIFIED)) {
            continue;
        }

        $name = ltrim($token->text, '\\');
        if ($name === '' || !str_contains($name, '\\')) {
            continue;
        }

        // `\Semitexa\Core\helper()` is a FUNCTION call and emits the same
        // token. Recording it would send the gate looking for helper.php and
        // fail a release that is perfectly satisfiable. `new \Foo\Bar()` and
        // `#[\Foo\Bar()]` are also followed by `(`, so what PRECEDES the name
        // is what tells them apart.
        if (nextMeaningfulText($tokens, $i) === '(' && !precededByClassContext($tokens, $i)) {
            continue;
        }

        // `\Semitexa\Core\VERSION` is a namespaced CONSTANT and is followed
        // by no parenthesis at all, so the call test above does not see it and
        // the gate went looking for a class named VERSION.
        //
        // Excluded only on the shapes that cannot be a class reference: an
        // all-caps last segment, with none of the positive signals that a name
        // really is a class. The bias is deliberate — an ambiguous name is kept
        // as a class, because missing one is silent while flagging one is loud.
        if (looksLikeConstantReference($tokens, $i, $name)) {
            continue;
        }

        $classes[$name] = true;
    }

    foreach ($imports['classes'] as $class) {
        if (!isset($usedAsPrefix[$class])) {
            $classes[$class] = true;
        }
    }

    return array_keys($classes);
}

/**
 * Whether a fully qualified name is a CONSTANT read rather than a class.
 *
 * True only when the last segment carries no lowercase letter AND none of the
 * positive class signals is present: a following `::` (static access or
 * `::class`), a following variable / `...` / `&` (a type position), or a
 * preceding `new`, attribute, `instanceof`, `extends`, `implements` or `:`
 * (a return type).
 */
function looksLikeConstantReference(array $tokens, int $at, string $name): bool
{
    $separator = strrpos($name, '\\');
    $last = $separator === false ? $name : substr($name, $separator + 1);
    if ($last === '' || strtoupper($last) !== $last) {
        return false;
    }

    $next = nextMeaningfulToken($tokens, $at);
    if ($next !== null) {
        if ($next->text === '::' || $next->text === '...' || $next->text === '&') {
            return false;
        }
        if ($next->is(T_VARIABLE)) {
            return false;
        }
    }

    $previous = previousMeaningfulToken($tokens, $at);
    if ($previous !== null) {
        if ($previous->is([T_NEW, T_ATTRIBUTE, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS])) {
            return false;
        }
        if ($previous->text === ':' || $previous->text === '?' || $previous->text === '|') {
            return false;
        }
    }

    return true;
}

/** The next token that is not whitespace or a comment, or null. */
function nextMeaningfulToken(array $tokens, int $from): ?PhpToken
{
    for ($i = $from + 1, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            continue;
        }

        return $tokens[$i];
    }

    return null;
}

/** The previous token that is not whitespace or a comment, or null. */
function previousMeaningfulToken(array $tokens, int $from): ?PhpToken
{
    for ($i = $from - 1; $i >= 0; $i--) {
        if ($tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            continue;
        }

        return $tokens[$i];
    }

    return null;
}

/** The text of the next token that is not whitespace or a comment. */
function nextMeaningfulText(array $tokens, int $from): string
{
    for ($i = $from + 1, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            continue;
        }

        return $tokens[$i]->text;
    }

    return '';
}

/**
 * Whether the nearest preceding meaningful token introduces a CLASS.
 *
 * `new \Foo\Bar()` and `#[\Foo\Bar()]` are both a name followed by `(`, and
 * both name a class. Only `new` was recognised, so a fully qualified attribute
 * with arguments was discarded as a function call and a floor predating the
 * attribute class could pass.
 */
function precededByClassContext(array $tokens, int $from): bool
{
    for ($i = $from - 1; $i >= 0; $i--) {
        if ($tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            continue;
        }

        return $tokens[$i]->is([T_NEW, T_ATTRIBUTE]);
    }

    return false;
}

/**
 * Every class name a file imports at namespace level.
 *
 * TOKENIZED, not matched. A regex over `use ...;` lines gets three things
 * wrong, and all three break a gate that is supposed to fail closed:
 *
 *  - GROUPED imports — `use Semitexa\Core\Support\{Row, Other};` — match
 *    nothing, because `{` is not part of a class name. The gate then reports
 *    success for a floor whose tag has no Row.php, which is the exact runtime
 *    "class not found" it exists to prevent.
 *  - COMMA-SEPARATED imports — `use A\B, C\D;` — yield only the first name.
 *  - The word `use` inside a comment, a string, or a closure's `use (...)`
 *    clause is matched as though it were an import.
 *
 * The alias is dropped at TOKEN level, on T_AS. Doing it by string surgery is
 * its own trap: searching the joined text for "as" turns
 * `use Semitexa\Orm\Metadata\HasColumnReferences;` into
 * `Semitexa\Orm\Metadata\H`, and the gate then fails releases over a file
 * nobody ever imported. Measured, in this repository, on six packages.
 *
 * `use function` and `use const` are skipped, and so are trait `use` statements
 * inside a class body: only namespace-level imports name a class file.
 *
 * @return array{classes: list<string>, aliases: array<int, array<string, string>>} aliases keyed by namespace block
 */
function importedClasses(string $contents): array
{
    $tokens = PhpToken::tokenize($contents);
    $classes = [];
    $aliases = [];
    $depth = 0;
    $block = 0;
    $namespaceBraceDepths = [];
    $inNamespaceDeclaration = false;

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];

        // A BRACED namespace — `namespace Semitexa\Ssr { use ...; }` — opens a
        // brace that is not a class body, and counting it would skip every
        // genuine import inside. Its depth is remembered so the matching `}`
        // closes it rather than looking like a class ending.
        if ($token->is(T_NAMESPACE)) {
            $inNamespaceDeclaration = true;
            $block++;
            continue;
        }
        if ($inNamespaceDeclaration && ($token->text === ';' || $token->text === '{')) {
            if ($token->text === '{') {
                $depth++;
                $namespaceBraceDepths[$depth] = true;
            }
            $inNamespaceDeclaration = false;
            continue;
        }

        // Trait `use` lives inside a class body; namespace-level `use` does not.
        if ($token->text === '{') {
            $depth++;
            continue;
        }
        if ($token->text === '}') {
            unset($namespaceBraceDepths[$depth]);
            $depth--;
            continue;
        }

        $classBodyDepth = $depth - count($namespaceBraceDepths);
        if (!$token->is(T_USE) || $classBodyDepth > 0) {
            continue;
        }

        $prefix = '';
        $current = '';
        $skippingAlias = false;
        $skippingItem = false;
        $isClassImport = null;
        /** @var array<string, string> $found */
        $found = [];

        $alias = '';
        $flush = static function () use (&$current, &$prefix, &$found, &$alias): void {
            $name = ltrim($prefix . $current, '\\');
            if ($name !== '') {
                $shortName = $alias;
                if ($shortName === '') {
                    $separator = strrpos($name, '\\');
                    $shortName = $separator === false ? $name : substr($name, $separator + 1);
                }
                // Lowercased: PHP resolves namespace and class names
                // case-insensitively, so `use ... as CoreSupport` is reached by
                // `coresupport\Row`. An exact-key lookup missed that, recorded
                // the IMPORT as a class instead, and failed a valid floor for
                // lacking Support.php.
                $found[strtolower($shortName)] = $name;
            }
            $current = '';
            $alias = '';
        };

        $j = $i + 1;
        for (; $j < $n; $j++) {
            $piece = $tokens[$j];
            $text = $piece->text;

            if ($text === ';') {
                break;
            }
            if ($piece->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            if ($isClassImport === null) {
                // The first meaningful token decides what kind of `use` this is.
                // `(` is a closure capture, which imports no class at all.
                $isClassImport = !$piece->is([T_FUNCTION, T_CONST]) && $text !== '(';
                if ($isClassImport === false) {
                    break;
                }
            }

            if ($piece->is(T_AS)) {
                $skippingAlias = true;
                $alias = '';
                continue;
            }
            if ($skippingAlias && $piece->is(T_STRING)) {
                // The alias is the NAME an unqualified reference in this file
                // will use, so it has to be kept, not merely skipped: a
                // namespace alias is how `new CoreSupport\Row()` reaches
                // Semitexa\Core\Support\Row.
                $alias = $text;
                continue;
            }
            if ($piece->is([T_FUNCTION, T_CONST])) {
                // `use Foo\{Bar, function helper};` — the kind may also appear
                // per ITEM inside a group, not only before the whole statement.
                $skippingItem = true;
                continue;
            }
            if ($text === ',') {
                $skippingAlias = false;
                if ($skippingItem) {
                    $skippingItem = false;
                    $current = '';
                    $alias = '';
                    continue;
                }
                $flush();
                continue;
            }
            if ($text === '{') {
                $prefix = $current;
                $current = '';
                continue;
            }
            if ($text === '}') {
                $skippingAlias = false;
                if ($skippingItem) {
                    $skippingItem = false;
                    $current = '';
                    $alias = '';
                } else {
                    $flush();
                }
                $prefix = '';
                continue;
            }
            if ($skippingAlias || $skippingItem) {
                continue;
            }

            $current .= $text;
        }

        $i = $j;

        if ($isClassImport !== true) {
            continue;
        }

        if ($skippingItem) {
            $current = '';
        }
        $flush();

        foreach ($found as $shortName => $class) {
            // Collected as a LIST, so two blocks in one file that import
            // different classes under the same local name both survive.
            // Keying only by the local name let the later one overwrite the
            // earlier, and the first went unchecked.
            $classes[] = $class;
            // Aliases are scoped to their BLOCK for the same reason: a
            // file-wide map resolved `Dup\Row` in the first block through the
            // second block's import, checking a class the first block never
            // names while the one it does name goes unchecked.
            $aliases[$block][$shortName] = $class;
        }
    }

    return ['classes' => $classes, 'aliases' => $aliases];
}


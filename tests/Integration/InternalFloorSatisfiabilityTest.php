<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A floor that names a release WITHOUT the class is worse than no floor.
 *
 * The form check in {@see InternalConstraintFormsTest} asks whether a
 * constraint is spelled correctly. This asks the question that matters:
 * semitexa/ssr floored core at the release before the one that introduced
 * `Semitexa\Core\Support\Row`, while importing Row in thirteen files. Composer
 * resolves that happily and the worker dies on `Class not found` at the first
 * request — the exact failure the floor exists to turn into a resolution error.
 *
 * A reviewer found that. Nothing in the release did.
 *
 * Against real git repositories, because the check reads a tag's tree: a
 * fixture of strings would only test the fixture.
 */
final class InternalFloorSatisfiabilityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            self::markTestSkipped('needs git');
        }

        $this->root = sys_get_temp_dir() . '/semitexa-floors-' . uniqid('', true);
        mkdir($this->root . '/packages', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * A provider package at one tag, carrying exactly the classes named.
     *
     * @param list<string> $classesAtTag relative paths under src/
     */
    private function provider(string $tag, array $classesAtTag): void
    {
        $dir = $this->root . '/packages/semitexa-core';
        mkdir($dir . '/src', 0777, true);
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/core',
            'autoload' => ['psr-4' => ['Semitexa\\Core\\' => 'src/']],
        ]));

        foreach ($classesAtTag as $relative) {
            @mkdir($dir . '/src/' . dirname($relative), 0777, true);
            // A REAL declaration, not an empty file. The gate asks what a
            // revision DECLARES, not which paths exist — a file that is there
            // but declares nothing is exactly the case where composer loads it
            // and still raises `Class not found`.
            $namespace = 'Semitexa\\Core';
            if (dirname($relative) !== '.') {
                $namespace .= '\\' . str_replace('/', '\\', dirname($relative));
            }
            file_put_contents(
                $dir . '/src/' . $relative,
                "<?php\n\nnamespace {$namespace};\n\nclass " . basename($relative, '.php') . " {}\n",
            );
        }

        $q = escapeshellarg($dir);
        exec("git -C {$q} init -q 2>&1");
        exec("git -C {$q} config user.email probe@example.com 2>&1");
        exec("git -C {$q} config user.name Probe 2>&1");
        exec("git -C {$q} -c safe.directory={$q} add -A 2>&1");
        exec("git -C {$q} -c safe.directory={$q} -c commit.gpgsign=false commit -q -m base 2>&1");
        exec("git -C {$q} -c safe.directory={$q} tag " . escapeshellarg($tag) . " 2>&1");
    }

    /** A consumer that floors core and imports one class from it. */
    private function consumer(string $floor, string $importedClass): void
    {
        $dir = $this->root . '/packages/semitexa-ssr';
        mkdir($dir . '/src/Application', 0777, true);
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/ssr',
            'require' => ['php' => '^8.4', 'semitexa/core' => $floor],
            'autoload' => ['psr-4' => ['Semitexa\\Ssr\\' => 'src/']],
        ]));
        file_put_contents(
            $dir . '/src/Application/Reader.php',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\nuse {$importedClass};\n\nfinal class Reader {}\n",
        );
    }

    /** A consumer whose one source file is written verbatim. */
    private function consumerWithSource(string $floor, string $body): void
    {
        $dir = $this->root . '/packages/semitexa-ssr';
        mkdir($dir . '/src/Application', 0777, true);
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/ssr',
            'require' => ['php' => '^8.4', 'semitexa/core' => $floor],
            'autoload' => ['psr-4' => ['Semitexa\\Ssr\\' => 'src/']],
        ]));
        file_put_contents($dir . '/src/Application/Reader.php', $body);
    }

    /**
     * @param array<string, string> $env extra environment for the gate process
     * @return array{exit: int, output: string}
     */
    private function gate(array $env = []): array
    {
        $script = dirname(__DIR__, 2)
            . '/resources/skills/release-readiness/scripts/release-check-internal-constraints.php';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, $script],
            $descriptors,
            $pipes,
            null,
            $env + ['RELEASE_ROOT' => $this->root, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );
        self::assertIsResource($process);

        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'output' => $output];
    }

    /** The case review caught, reproduced: the class is not in the floored release. */
    #[Test]
    public function a_floor_without_the_imported_class_fails_the_release(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);
        $this->consumer('>=2026.09.13.0749 || dev-master', 'Semitexa\\Core\\Support\\Row');

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Support\\Row', $result['output']);
        self::assertStringContainsString('src/Support/Row.php', $result['output'], 'name the file, not just the class');
        self::assertStringContainsString('2026.09.13.0749', $result['output'], 'name the floor that is wrong');
    }

    /** And passes once the floor names a release that has it. */
    #[Test]
    public function a_floor_that_contains_the_class_passes(): void
    {
        $this->provider('2026.09.13.1330', ['Support/Row.php']);
        $this->consumer('>=2026.09.13.1330 || dev-master', 'Semitexa\\Core\\Support\\Row');

        $result = $this->gate();

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('actually contains', $result['output']);
    }

    /**
     * A floor naming a tag nobody has cannot be judged, so it is not passed.
     * Something the gate cannot see is not something it approves.
     */
    #[Test]
    public function a_floor_on_a_tag_that_does_not_exist_fails_closed(): void
    {
        $this->provider('2026.09.13.1330', ['Support/Row.php']);
        $this->consumer('>=2099.01.01.0000 || dev-master', 'Semitexa\\Core\\Support\\Row');

        $result = $this->gate();

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('2099.01.01.0000', $result['output']);
        self::assertStringContainsString('not in', $result['output']);
    }

    /**
     * `*` makes no VERSION promise, so there is no floor to verify — but it is
     * not therefore unfalsifiable. If the class exists in no released version
     * at all, the requirement cannot be met by anything on Packagist today,
     * whichever version a consumer resolves to.
     *
     * semitexa/mail called SandboxGuard while requiring semitexa/core at `*`.
     * Composer accepts a lockfile with yesterday's core and every send fatals
     * on `Class not found` — outside a sandbox too. The floor check waved it
     * through, because `*` was the one form nothing looked at.
     */
    #[Test]
    public function a_wildcard_still_fails_when_no_release_contains_the_class(): void
    {
        $this->provider('2026.09.13.1330', ['Support/Other.php']);
        $this->consumer('*', 'Semitexa\\Core\\Support\\Row');

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('NO released semitexa/core', $result['output']);
        self::assertStringContainsString('2026.09.13.1330', $result['output'], 'name the newest release checked');
    }

    /** And a wildcard whose class IS released stays unchecked, as before. */
    #[Test]
    public function a_wildcard_whose_class_is_released_is_left_alone(): void
    {
        $this->provider('2026.09.13.1330', ['Support/Row.php']);
        $this->consumer('*', 'Semitexa\\Core\\Support\\Row');

        self::assertSame(0, $this->gate()['exit'], 'a wildcard is not a floor and must not be treated as one');
    }

    /**
     * The path check is a PROXY for "is this class resolvable", and a proxy
     * needs a second opinion before it fails a release.
     *
     * `Semitexa\Core\Tenant\Layer\ThemeValue` is a second class declared
     * inside ThemeLayer.php, so no ThemeValue.php exists in any release — yet
     * the class resolves fine. Reporting it would fail every release over
     * something that has worked for months, and a gate that cries wolf is a
     * gate someone switches off.
     */
    #[Test]
    public function a_class_declared_in_another_file_is_not_reported_missing(): void
    {
        $dir = $this->root . '/packages/semitexa-core';
        mkdir($dir . '/src/Tenant', 0777, true);
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/core',
            'autoload' => ['psr-4' => ['Semitexa\\Core\\' => 'src/']],
        ]));
        // Two classes, one file — no SideCar.php anywhere in the tree.
        file_put_contents(
            $dir . '/src/Tenant/Holder.php',
            "<?php\n\nnamespace Semitexa\\Core\\Tenant;\n\n"
            . "class Holder {}\n\nreadonly class SideCar {}\n",
        );

        $q = escapeshellarg($dir);
        exec("git -C {$q} init -q 2>&1");
        exec("git -C {$q} config user.email probe@example.com 2>&1");
        exec("git -C {$q} config user.name Probe 2>&1");
        exec("git -C {$q} -c safe.directory={$q} add -A 2>&1");
        exec("git -C {$q} -c safe.directory={$q} -c commit.gpgsign=false commit -q -m base 2>&1");
        exec("git -C {$q} -c safe.directory={$q} tag 2026.09.13.1330 2>&1");

        $this->consumer('>=2026.09.13.1330 || dev-master', 'Semitexa\\Core\\Tenant\\SideCar');

        $result = $this->gate();

        self::assertSame(0, $result['exit'], $result['output']);
    }

    /** An import from outside the floored package is none of this check's business. */
    #[Test]
    public function an_unrelated_import_is_ignored(): void
    {
        $this->provider('2026.09.13.1330', ['Support/Row.php']);
        $this->consumer('>=2026.09.13.1330 || dev-master', 'Psr\\Log\\LoggerInterface');

        self::assertSame(0, $this->gate()['exit']);
    }

    /**
     * A GROUPED import is an import. The regex this check used to run matched
     * nothing here, because `{` is not part of a class name — so the gate
     * reported success for a floor whose release has no Row.php, which is the
     * runtime "class not found" it exists to prevent. Fail-open in a
     * fail-closed gate.
     */
    #[Test]
    public function a_grouped_import_is_read_like_any_other(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);
        $this->consumerWithSource(
            '>=2026.09.13.0749 || dev-master',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\n"
            . "use Semitexa\\Core\\Support\\{Other, Row};\n\nfinal class Reader {}\n",
        );

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Support\\Row', $result['output']);
    }

    /** So is a comma-separated one, of which the regex saw only the first name. */
    #[Test]
    public function a_comma_separated_import_is_read_past_the_first_name(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);
        $this->consumerWithSource(
            '>=2026.09.13.0749 || dev-master',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\n"
            . "use Semitexa\\Core\\Support\\Other, Semitexa\\Core\\Support\\Row;\n\nfinal class Reader {}\n",
        );

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Support\\Row', $result['output']);
    }

    /** An alias does not change which file has to exist. */
    #[Test]
    public function an_aliased_import_still_names_its_own_file(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);
        $this->consumerWithSource(
            '>=2026.09.13.0749 || dev-master',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\n"
            . "use Semitexa\\Core\\Support\\Row as CoreRow;\n\nfinal class Reader {}\n",
        );

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('src/Support/Row.php', $result['output']);
    }

    /**
     * And the other direction, which matters just as much: the word `use` in a
     * comment, a string, a closure capture or a trait import is NOT an import.
     * A gate that invents imports fails releases that are fine, and the fastest
     * way to get a gate switched off is to have it cry wolf.
     */
    #[Test]
    public function use_that_is_not_an_import_is_not_treated_as_one(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);
        $this->consumerWithSource(
            '>=2026.09.13.0749 || dev-master',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\n"
            . "use Semitexa\\Core\\Support\\Other;\n\n"
            . "// use Semitexa\\Core\\Support\\Row;\n"
            . "trait Helper { public function help(): string { return 'x'; } }\n\n"
            . "final class Reader\n{\n"
            . "    use Helper;\n\n"
            . "    public function run(string \$row): callable\n    {\n"
            . "        return static function () use (\$row): string { return \$row; };\n"
            . "    }\n}\n",
        );

        $result = $this->gate();

        self::assertSame(0, $result['exit'], $result['output']);
    }

    /**
     * The autoload map is read at the FLOORED TAG, not from today's
     * composer.json. A provider that moved its sources between the two would
     * otherwise have the old tree searched with the new layout — rejecting a
     * floor that is fine.
     */
    #[Test]
    public function the_autoload_map_is_read_at_the_floored_tag(): void
    {
        $dir = $this->root . '/packages/semitexa-core';
        mkdir($dir . '/lib/Support', 0777, true);
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/core',
            'autoload' => ['psr-4' => ['Semitexa\\Core\\' => 'lib/']],
        ]));
        file_put_contents(
            $dir . '/lib/Support/Row.php',
            "<?php\n\nnamespace Semitexa\\Core\\Support;\n\nclass Row {}\n",
        );

        $q = escapeshellarg($dir);
        exec("git -C {$q} init -q 2>&1");
        exec("git -C {$q} config user.email probe@example.com 2>&1");
        exec("git -C {$q} config user.name Probe 2>&1");
        exec("git -C {$q} -c safe.directory={$q} add -A 2>&1");
        exec("git -C {$q} -c safe.directory={$q} -c commit.gpgsign=false commit -q -m base 2>&1");
        exec("git -C {$q} -c safe.directory={$q} tag 2026.09.13.1330 2>&1");

        // TODAY the package autoloads from src/ — the tag says lib/.
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/core',
            'autoload' => ['psr-4' => ['Semitexa\\Core\\' => 'src/']],
        ]));

        $this->consumer('>=2026.09.13.1330 || dev-master', 'Semitexa\\Core\\Support\\Row');

        $result = $this->gate();

        self::assertSame(0, $result['exit'], $result['output']);
    }

    /**
     * A BRACED namespace still has namespace-level imports.
     *
     * `namespace X { use Y; }` opens a brace that is not a class body. Counting
     * it as one skipped every genuine import inside the block, and the gate
     * reported success while the floored tag had no such file — the fail-open
     * again, one level deeper.
     */
    #[Test]
    public function an_import_inside_a_braced_namespace_is_still_an_import(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);
        $this->consumerWithSource(
            '>=2026.09.13.0749 || dev-master',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application {\n"
            . "    use Semitexa\\Core\\Support\\Row;\n\n"
            . "    final class Reader\n    {\n        use Nothing;\n    }\n}\n",
        );

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Support\\Row', $result['output']);
    }

    /** A lowercase alias is still an alias — capitalization proves nothing. */
    #[Test]
    public function a_lowercase_alias_does_not_corrupt_the_class_name(): void
    {
        $this->provider('2026.09.13.1330', ['Support/Row.php']);
        $this->consumerWithSource(
            '>=2026.09.13.1330 || dev-master',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\n"
            . "use Semitexa\\Core\\Support\\Row as row;\n\nfinal class Reader {}\n",
        );

        $result = $this->gate();

        self::assertSame(0, $result['exit'], $result['output']);
    }

    /**
     * And a class whose own name contains "as" survives. Stripping the alias by
     * string search turned `HasColumnReferences` into `H` and reported six
     * packages here for a file nobody imports.
     */
    #[Test]
    public function a_class_name_containing_as_is_not_truncated(): void
    {
        $this->provider('2026.09.13.1330', ['Metadata/HasColumnReferences.php']);
        $this->consumer('>=2026.09.13.1330 || dev-master', 'Semitexa\\Core\\Metadata\\HasColumnReferences');

        $result = $this->gate();

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringNotContainsString('Metadata/H.php', $result['output']);
    }

    /**
     * A FULLY QUALIFIED reference names a class as surely as an import does.
     *
     * `new \Semitexa\Core\Support\Row()` needs no `use`, and this workspace
     * writes 140 distinct cross-package references that way. A scan that only
     * entered on `use` was blind to all of them, so a class added after the
     * floored release could be called and the gate still report success.
     */
    #[Test]
    public function a_fully_qualified_reference_counts_as_using_the_class(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);
        $this->consumerWithSource(
            '>=2026.09.13.0749 || dev-master',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\n"
            . "final class Reader\n{\n"
            . "    public function run(): object\n    {\n"
            . "        return new \\Semitexa\\Core\\Support\\Row();\n"
            . "    }\n}\n",
        );

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Support\\Row', $result['output']);
    }

    /**
     * An EXACT PIN is a promise about a specific release too, and was checked
     * by nothing: recorded as neither floor nor wildcard, so a package pinned
     * to a release predating a class it uses passed the gate.
     */
    #[Test]
    public function an_exact_pin_is_verified_like_a_floor(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);
        $this->consumer('2026.09.13.0749', 'Semitexa\\Core\\Support\\Row');

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('pins semitexa/core', $result['output']);
    }

    /**
     * Composer lets one prefix map to several directories. Keeping only
     * string-valued entries dropped such a prefix entirely, and every class
     * under it went unmapped and unchecked.
     */
    #[Test]
    public function an_array_valued_psr4_prefix_is_still_mapped(): void
    {
        $dir = $this->root . '/packages/semitexa-core';
        mkdir($dir . '/lib/Support', 0777, true);
        mkdir($dir . '/src/Support', 0777, true);
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/core',
            'autoload' => ['psr-4' => ['Semitexa\\Core\\' => ['src/', 'lib/']]],
        ]));
        // Present in the SECOND directory only — the candidate list must reach it.
        file_put_contents(
            $dir . '/lib/Support/Row.php',
            "<?php\n\nnamespace Semitexa\\Core\\Support;\n\nclass Row {}\n",
        );

        $q = escapeshellarg($dir);
        exec("git -C {$q} init -q 2>&1");
        exec("git -C {$q} config user.email probe@example.com 2>&1");
        exec("git -C {$q} config user.name Probe 2>&1");
        exec("git -C {$q} -c safe.directory={$q} add -A 2>&1");
        exec("git -C {$q} -c safe.directory={$q} -c commit.gpgsign=false commit -q -m base 2>&1");
        exec("git -C {$q} -c safe.directory={$q} tag 2026.09.13.1330 2>&1");

        $this->consumer('>=2026.09.13.1330 || dev-master', 'Semitexa\\Core\\Support\\Row');

        self::assertSame(0, $this->gate()['exit'], 'a second mapped directory is still a mapped directory');
    }

    /**
     * And when the tagged autoload block cannot be read at all, the gate fails
     * rather than falling back to today's map — falling back is the same mixing
     * of revisions in a quieter form.
     */
    #[Test]
    public function an_unreadable_tagged_autoload_map_fails_closed(): void
    {
        $dir = $this->root . '/packages/semitexa-core';
        mkdir($dir . '/src/Support', 0777, true);
        // No autoload block at the tag at all.
        file_put_contents($dir . '/composer.json', (string) json_encode(['name' => 'semitexa/core']));
        file_put_contents(
            $dir . '/src/Support/Row.php',
            "<?php\n\nnamespace Semitexa\\Core\\Support;\n\nclass Row {}\n",
        );

        $q = escapeshellarg($dir);
        exec("git -C {$q} init -q 2>&1");
        exec("git -C {$q} config user.email probe@example.com 2>&1");
        exec("git -C {$q} config user.name Probe 2>&1");
        exec("git -C {$q} -c safe.directory={$q} add -A 2>&1");
        exec("git -C {$q} -c safe.directory={$q} -c commit.gpgsign=false commit -q -m base 2>&1");
        exec("git -C {$q} -c safe.directory={$q} tag 2026.09.13.1330 2>&1");

        $this->consumer('>=2026.09.13.1330 || dev-master', 'Semitexa\\Core\\Support\\Row');

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('no readable autoload.psr-4 map', $result['output']);
    }

    /** A function imported inside a GROUP is not a class file. */
    #[Test]
    public function a_grouped_function_import_is_not_taken_for_a_class(): void
    {
        $this->provider('2026.09.13.1330', ['Support/Row.php']);
        $this->consumerWithSource(
            '>=2026.09.13.1330 || dev-master',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\n"
            . "use Semitexa\\Core\\Support\\{Row, function helper};\n\nfinal class Reader {}\n",
        );

        $result = $this->gate();

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringNotContainsString('helper', $result['output']);
    }

    /**
     * A SHORT NAME IS NOT A CLASS.
     *
     * The declaration escape hatch greps the tag for `class Row`, which a `Row`
     * in an unrelated namespace also answers. Accepting it would approve a floor
     * that still produces `Class not found` — the one thing this check exists
     * to prevent, defeated by the thing added to stop it crying wolf.
     */
    #[Test]
    public function a_same_named_class_in_another_namespace_does_not_satisfy_the_import(): void
    {
        $dir = $this->root . '/packages/semitexa-core';
        mkdir($dir . '/src/Other', 0777, true);
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/core',
            'autoload' => ['psr-4' => ['Semitexa\\Core\\' => 'src/']],
        ]));
        // A Row exists — in the wrong namespace.
        file_put_contents(
            $dir . '/src/Other/Row.php',
            "<?php\n\nnamespace Semitexa\\Core\\Other;\n\nclass Row {}\n",
        );

        $q = escapeshellarg($dir);
        exec("git -C {$q} init -q 2>&1");
        exec("git -C {$q} config user.email probe@example.com 2>&1");
        exec("git -C {$q} config user.name Probe 2>&1");
        exec("git -C {$q} -c safe.directory={$q} add -A 2>&1");
        exec("git -C {$q} -c safe.directory={$q} -c commit.gpgsign=false commit -q -m base 2>&1");
        exec("git -C {$q} -c safe.directory={$q} tag 2026.09.13.1330 2>&1");

        $this->consumer('>=2026.09.13.1330 || dev-master', 'Semitexa\\Core\\Newer\\Row');

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Newer\\Row', $result['output']);
    }

    /** A mixed grouped import with a nested path and a function reads correctly. */
    #[Test]
    public function a_mixed_group_with_a_nested_path_reads_only_the_class(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);
        $this->consumerWithSource(
            '>=2026.09.13.0749 || dev-master',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\n"
            . "use Semitexa\\Core\\{Support\\Row, function helper};\n\nfinal class Reader {}\n",
        );

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Support\\Row', $result['output']);
        self::assertStringNotContainsString('helper', $result['output'], 'the function is not a class file');
    }

    /**
     * A NAMESPACE alias resolves to the class, not to the namespace.
     *
     * `use Semitexa\Core\Support as CoreSupport;` then `new CoreSupport\Row()`
     * recorded the import itself, so the gate looked for Support.php — absent
     * in every release — and failed a release that was fine, while never
     * checking Row at all.
     */
    #[Test]
    public function a_namespace_alias_resolves_to_the_class_it_reaches(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);
        $this->consumerWithSource(
            '>=2026.09.13.0749 || dev-master',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\n"
            . "use Semitexa\\Core\\Support as CoreSupport;\n\n"
            . "final class Reader\n{\n"
            . "    public function run(): object\n    {\n"
            . "        return new CoreSupport\\Row();\n"
            . "    }\n}\n",
        );

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Support\\Row', $result['output']);
        self::assertStringNotContainsString('Support.php', $result['output'], 'the namespace is not a class file');
    }

    /**
     * A fully qualified FUNCTION call emits the same token as a class name.
     * Recording it sent the gate looking for helper.php and failed a release
     * that was perfectly satisfiable.
     */
    #[Test]
    public function a_fully_qualified_function_call_is_not_a_class(): void
    {
        $this->provider('2026.09.13.1330', ['Support/Row.php']);
        $this->consumerWithSource(
            '>=2026.09.13.1330 || dev-master',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\n"
            . "final class Reader\n{\n"
            . "    public function run(): mixed\n    {\n"
            . "        return \\Semitexa\\Core\\helper();\n"
            . "    }\n}\n",
        );

        $result = $this->gate();

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringNotContainsString('helper', $result['output']);
    }

    /** But `new \Foo\Bar()` is followed by `(` too, and IS a class. */
    #[Test]
    public function a_fully_qualified_constructor_is_still_a_class(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);
        $this->consumerWithSource(
            '>=2026.09.13.0749 || dev-master',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\n"
            . "final class Reader\n{\n"
            . "    public function run(): object\n    {\n"
            . "        return new \\Semitexa\\Core\\Support\\Row();\n"
            . "    }\n}\n",
        );

        self::assertSame(1, $this->gate()['exit'], 'the `new` is what tells it from a function call');
    }

    /**
     * THE RELEASE BEING CUT HAS NO TAG YET, and this gate runs in preflight,
     * before finalize creates any. A package that starts calling a sibling API
     * introduced in the SAME release otherwise had no constraint that could
     * pass: `*` fails because no release contains the class, and a floor at the
     * planned version fails because that tag does not exist. The release could
     * not reach finalize at all.
     */
    #[Test]
    public function a_floor_on_the_release_being_cut_is_verified_against_the_tree(): void
    {
        // Released WITHOUT Row; the working tree has it, as it would during prep.
        $this->provider('2026.09.13.1330', ['Support/Other.php']);
        $dir = $this->root . '/packages/semitexa-core';
        file_put_contents(
            $dir . '/src/Support/Row.php',
            "<?php\n\nnamespace Semitexa\\Core\\Support;\n\nfinal class Row {}\n",
        );
        $q = escapeshellarg($dir);
        exec("git -C {$q} -c safe.directory={$q} add -A 2>&1");
        exec("git -C {$q} -c safe.directory={$q} -c commit.gpgsign=false commit -q -m row 2>&1");

        $this->consumer('>=2026.09.13.1900 || dev-master', 'Semitexa\\Core\\Support\\Row');

        $withPlanned = $this->gate(['RELEASE_VERSION' => '2026.09.13.1900']);
        self::assertSame(0, $withPlanned['exit'], $withPlanned['output']);

        // And ONLY for the version the release flow names. Any other absent tag
        // still fails closed — a typo must not be answered by "the class is
        // here now".
        $withoutPlanned = $this->gate();
        self::assertSame(1, $withoutPlanned['exit'], $withoutPlanned['output']);
        self::assertStringContainsString('that tag is not in', $withoutPlanned['output']);
    }

    /** And a class that is NOT in the tree still fails, planned version or not. */
    #[Test]
    public function a_floor_on_the_release_being_cut_still_fails_for_a_class_that_is_not_there(): void
    {
        $this->provider('2026.09.13.1330', ['Support/Other.php']);
        $this->consumer('>=2026.09.13.1900 || dev-master', 'Semitexa\\Core\\Support\\Row');

        $result = $this->gate(['RELEASE_VERSION' => '2026.09.13.1900']);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('does not declare it', $result['output']);
    }

    /**
     * LONGEST PREFIX WINS, as composer resolves it.
     *
     * The verdict comes from what the revision DECLARES, so the prefix no
     * longer decides pass or fail — but it still decides the path the failure
     * NAMES, and a message pointing at a file composer would never load sends
     * whoever reads it to the wrong place.
     */
    #[Test]
    public function the_longest_matching_psr4_prefix_decides_the_path(): void
    {
        $dir = $this->root . '/packages/semitexa-core';
        mkdir($dir . '/src/Special', 0777, true);
        mkdir($dir . '/special', 0777, true);
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/core',
            'autoload' => ['psr-4' => [
                'Semitexa\\Core\\' => 'src/',
                'Semitexa\\Core\\Special\\' => 'special/',
            ]],
        ]));
        // Declared nowhere; only the two mapped directories exist.
        file_put_contents(
            $dir . '/src/Other.php',
            "<?php\n\nnamespace Semitexa\\Core;\n\nclass Other {}\n",
        );

        $q = escapeshellarg($dir);
        exec("git -C {$q} init -q 2>&1");
        exec("git -C {$q} config user.email probe@example.com 2>&1");
        exec("git -C {$q} config user.name Probe 2>&1");
        exec("git -C {$q} -c safe.directory={$q} add -A 2>&1");
        exec("git -C {$q} -c safe.directory={$q} -c commit.gpgsign=false commit -q -m base 2>&1");
        exec("git -C {$q} -c safe.directory={$q} tag 2026.09.13.1330 2>&1");

        $this->consumer('>=2026.09.13.1330 || dev-master', 'Semitexa\\Core\\Special\\Row');

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString(
            'special/Row.php',
            $result['output'],
            'the longest matching prefix names the path, as composer would resolve it',
        );
        self::assertStringNotContainsString('src/Special/Row.php', $result['output']);
    }

    /**
     * A promise in require-dev is a promise. The form check already covered
     * both sections; the satisfiability check read only `require`, so a
     * require-dev floor was accepted as well-formed by one pass and verified
     * by neither.
     */
    #[Test]
    public function a_floor_in_require_dev_is_verified_too(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);

        $dir = $this->root . '/packages/semitexa-ssr';
        mkdir($dir . '/src/Application', 0777, true);
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/ssr',
            'require' => ['php' => '^8.4'],
            'require-dev' => ['semitexa/core' => '>=2026.09.13.0749 || dev-master'],
            'autoload' => ['psr-4' => ['Semitexa\\Ssr\\' => 'src/']],
        ]));
        file_put_contents(
            $dir . '/src/Application/Reader.php',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\n"
            . "use Semitexa\\Core\\Support\\Row;\n\nfinal class Reader {}\n",
        );

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Support\\Row', $result['output']);
    }

    /**
     * A FILE THAT EXISTS BUT DECLARES NOTHING is the case a path check cannot
     * see. Composer includes it and still raises `Class not found`, so the gate
     * would have approved the exact failure it exists to prevent.
     */
    #[Test]
    public function a_path_that_exists_without_the_class_is_not_enough(): void
    {
        $dir = $this->root . '/packages/semitexa-core';
        mkdir($dir . '/src/Support', 0777, true);
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/core',
            'autoload' => ['psr-4' => ['Semitexa\\Core\\' => 'src/']],
        ]));
        // Right path, wrong contents — the class arrives in a later release.
        file_put_contents(
            $dir . '/src/Support/Row.php',
            "<?php\n\nnamespace Semitexa\\Core\\Support;\n\nclass SomethingElse {}\n",
        );

        $q = escapeshellarg($dir);
        exec("git -C {$q} init -q 2>&1");
        exec("git -C {$q} config user.email probe@example.com 2>&1");
        exec("git -C {$q} config user.name Probe 2>&1");
        exec("git -C {$q} -c safe.directory={$q} add -A 2>&1");
        exec("git -C {$q} -c safe.directory={$q} -c commit.gpgsign=false commit -q -m base 2>&1");
        exec("git -C {$q} -c safe.directory={$q} tag 2026.09.13.1330 2>&1");

        $this->consumer('>=2026.09.13.1330 || dev-master', 'Semitexa\\Core\\Support\\Row');

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('does not declare it', $result['output']);
    }

    /**
     * Two bracketed namespace blocks in one file may import different classes
     * under the same local name. Keyed only by that name, the later import
     * overwrote the earlier and the first went unchecked.
     */
    #[Test]
    public function two_blocks_importing_the_same_local_name_are_both_checked(): void
    {
        $this->provider('2026.09.13.1330', ['Existing/Row.php']);
        $this->consumerWithSource(
            '>=2026.09.13.1330 || dev-master',
            "<?php\n\nnamespace A {\n"
            . "    use Semitexa\\Core\\Missing\\Row;\n\n    class One {}\n}\n\n"
            . "namespace B {\n"
            . "    use Semitexa\\Core\\Existing\\Row;\n\n    class Two {}\n}\n",
        );

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Missing\\Row', $result['output']);
    }

    /**
     * Production code a package autoloads from somewhere other than src/ is
     * still production code. Hardcoding the scan root meant such a file could
     * use a class absent from the promised release unnoticed.
     */
    #[Test]
    public function a_consumer_source_root_outside_src_is_scanned(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);

        $dir = $this->root . '/packages/semitexa-ssr';
        mkdir($dir . '/lib/Application', 0777, true);
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/ssr',
            'require' => ['php' => '^8.4', 'semitexa/core' => '>=2026.09.13.0749 || dev-master'],
            'autoload' => ['psr-4' => ['Semitexa\\Ssr\\' => 'lib/']],
        ]));
        file_put_contents(
            $dir . '/lib/Application/Reader.php',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\n"
            . "use Semitexa\\Core\\Support\\Row;\n\nfinal class Reader {}\n",
        );

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Support\\Row', $result['output']);
    }

    /**
     * A fully qualified ATTRIBUTE with arguments is a class reference, not a
     * function call — it is a name followed by `(`, exactly like `new`, and
     * only `new` was recognised.
     */
    #[Test]
    public function a_fully_qualified_attribute_with_arguments_is_a_class(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);
        $this->consumerWithSource(
            '>=2026.09.13.0749 || dev-master',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\n"
            . "#[\\Semitexa\\Core\\Support\\Row(live: true)]\nfinal class Reader {}\n",
        );

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Support\\Row', $result['output']);
    }

    /**
     * In the GLOBAL namespace a qualified name resolves exactly as written, so
     * `new Semitexa\Core\Support\Row()` with no leading slash names the
     * sibling class. Inside a namespace the same text would mean something
     * relative to it, which is why this is conditioned on there being none.
     */
    #[Test]
    public function a_qualified_name_in_the_global_namespace_is_resolved(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);
        $this->consumerWithSource(
            '>=2026.09.13.0749 || dev-master',
            "<?php\n\nfinal class GlobalReader\n{\n"
            . "    public function run(): object\n    {\n"
            . "        return new Semitexa\\Core\\Support\\Row();\n"
            . "    }\n}\n",
        );

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Support\\Row', $result['output']);
    }

    /** A constraint with leading whitespace is valid, and must still be verified. */
    #[Test]
    public function a_constraint_with_leading_whitespace_is_still_indexed(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);
        $this->consumer('  >=2026.09.13.0749 || dev-master', 'Semitexa\\Core\\Support\\Row');

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Support\\Row', $result['output']);
    }

    /**
     * A prefix the provider added AFTER the floored tag exists in no tagged
     * map, so every reference under it was skipped — never compared with what
     * that tag declares, and the gate exited successfully.
     *
     * Discovery asks the wider map which classes belong to the dependency;
     * verification still happens against the tagged declarations.
     */
    #[Test]
    public function a_prefix_added_after_the_floored_tag_is_still_discovered(): void
    {
        $dir = $this->root . '/packages/semitexa-core';
        mkdir($dir . '/src/Support', 0777, true);
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/core',
            'autoload' => ['psr-4' => ['Semitexa\\Core\\' => 'src/']],
        ]));
        file_put_contents(
            $dir . '/src/Support/Other.php',
            "<?php\n\nnamespace Semitexa\\Core\\Support;\n\nclass Other {}\n",
        );

        $q = escapeshellarg($dir);
        exec("git -C {$q} init -q 2>&1");
        exec("git -C {$q} config user.email probe@example.com 2>&1");
        exec("git -C {$q} config user.name Probe 2>&1");
        exec("git -C {$q} -c safe.directory={$q} add -A 2>&1");
        exec("git -C {$q} -c safe.directory={$q} -c commit.gpgsign=false commit -q -m base 2>&1");
        exec("git -C {$q} -c safe.directory={$q} tag 2026.09.13.0749 2>&1");

        // TODAY the provider also maps a second prefix; the floored tag did not.
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/core',
            'autoload' => ['psr-4' => [
                'Semitexa\\Core\\' => 'src/',
                'Semitexa\\Contracts\\' => 'contracts/',
            ]],
        ]));

        $this->consumer('>=2026.09.13.0749 || dev-master', 'Semitexa\\Contracts\\NewApi');

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Contracts\\NewApi', $result['output']);
    }

    /**
     * A class used only from the package's own tests still has to exist in the
     * release the package promises. Promises are read from require-dev; the
     * roots have to come from autoload-dev to match.
     */
    #[Test]
    public function an_autoload_dev_root_is_scanned_for_a_require_dev_promise(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);

        $dir = $this->root . '/packages/semitexa-ssr';
        mkdir($dir . '/tests/Unit', 0777, true);
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/ssr',
            'require' => ['php' => '^8.4'],
            'require-dev' => ['semitexa/core' => '>=2026.09.13.0749 || dev-master'],
            'autoload' => ['psr-4' => ['Semitexa\\Ssr\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['Semitexa\\Ssr\\Tests\\' => 'tests/']],
        ]));
        mkdir($dir . '/src', 0777, true);
        file_put_contents(
            $dir . '/tests/Unit/ReaderTest.php',
            "<?php\n\nnamespace Semitexa\\Ssr\\Tests\\Unit;\n\n"
            . "use Semitexa\\Core\\Support\\Row;\n\nfinal class ReaderTest {}\n",
        );

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Support\\Row', $result['output']);
    }

    /**
     * `"Semitexa\\Ssr\\": ""` is a valid mapping meaning the PACKAGE ROOT.
     * Dropped as "no directory", the files that actually hold the package's
     * code were never read, and a class absent from the promised release went
     * unnoticed.
     */
    #[Test]
    public function a_psr4_mapping_to_the_package_root_is_scanned(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);

        $dir = $this->root . '/packages/semitexa-ssr';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/ssr',
            'require' => ['php' => '^8.4', 'semitexa/core' => '>=2026.09.13.0749 || dev-master'],
            'autoload' => ['psr-4' => ['Semitexa\\Ssr\\' => '']],
        ]));
        file_put_contents(
            $dir . '/Reader.php',
            "<?php\n\nnamespace Semitexa\\Ssr;\n\n"
            . "use Semitexa\\Core\\Support\\Row;\n\nfinal class Reader {}\n",
        );

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Support\\Row', $result['output']);
    }

    /**
     * And the same mapping on the PROVIDER side names a path without a leading
     * slash. Concatenating an empty directory produced `/Support/Row.php`,
     * which matches nothing in a git tree and reads like an absolute path.
     */
    #[Test]
    public function a_provider_mapped_to_its_root_names_a_relative_path(): void
    {
        $dir = $this->root . '/packages/semitexa-core';
        mkdir($dir . '/Support', 0777, true);
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/core',
            'autoload' => ['psr-4' => ['Semitexa\\Core\\' => '']],
        ]));
        file_put_contents(
            $dir . '/Support/Other.php',
            "<?php\n\nnamespace Semitexa\\Core\\Support;\n\nclass Other {}\n",
        );

        $q = escapeshellarg($dir);
        exec("git -C {$q} init -q 2>&1");
        exec("git -C {$q} config user.email probe@example.com 2>&1");
        exec("git -C {$q} config user.name Probe 2>&1");
        exec("git -C {$q} -c safe.directory={$q} add -A 2>&1");
        exec("git -C {$q} -c safe.directory={$q} -c commit.gpgsign=false commit -q -m base 2>&1");
        exec("git -C {$q} -c safe.directory={$q} tag 2026.09.13.0749 2>&1");

        $this->consumer('>=2026.09.13.0749 || dev-master', 'Semitexa\\Core\\Support\\Row');

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('expected Support/Row.php', $result['output']);
    }

    /**
     * PHP resolves namespace names case-insensitively, so an alias declared as
     * CORESUPPORT is reached by `coresupport\Row`. An exact-key lookup missed
     * it, recorded the IMPORT as a class, and failed a valid floor for lacking
     * Support.php.
     */
    #[Test]
    public function a_namespace_alias_is_matched_regardless_of_case(): void
    {
        $this->provider('2026.09.13.1330', ['Support/Row.php']);
        $this->consumerWithSource(
            '>=2026.09.13.1330 || dev-master',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\n"
            . "use Semitexa\\Core\\Support as CORESUPPORT;\n\n"
            . "final class Reader\n{\n"
            . "    public function run(): object\n    {\n"
            . "        return new coresupport\\Row();\n"
            . "    }\n}\n",
        );

        $result = $this->gate();

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringNotContainsString('Support.php', $result['output'], 'the namespace is not a class file');
    }

    /**
     * An ALIAS belongs to its namespace block. A file-wide map resolved both
     * `Dup\Row` references through the second block's import, so the class the
     * first block actually names was never checked — and it is the one that
     * fails at runtime.
     */
    #[Test]
    public function an_alias_is_resolved_through_its_own_block(): void
    {
        $this->provider('2026.09.13.1330', ['Existing/Row.php']);
        $this->consumerWithSource(
            '>=2026.09.13.1330 || dev-master',
            "<?php\n\nnamespace A {\n"
            . "    use Semitexa\\Core\\Missing as Dup;\n\n"
            . "    class One { public function f(): object { return new Dup\\Row(); } }\n}\n\n"
            . "namespace B {\n"
            . "    use Semitexa\\Core\\Existing as Dup;\n\n"
            . "    class Two { public function g(): object { return new Dup\\Row(); } }\n}\n",
        );

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Missing\\Row', $result['output']);
    }

    /**
     * A namespaced CONSTANT is followed by no parenthesis, so the function-call
     * test never saw it and the gate looked for a class named VERSION.
     */
    #[Test]
    public function a_fully_qualified_constant_is_not_a_class(): void
    {
        $this->provider('2026.09.13.1330', ['Support/Row.php']);
        $this->consumerWithSource(
            '>=2026.09.13.1330 || dev-master',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\n"
            . "final class Reader\n{\n"
            . "    public function run(): string\n    {\n"
            . "        return \\Semitexa\\Core\\VERSION;\n"
            . "    }\n}\n",
        );

        $result = $this->gate();

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringNotContainsString('VERSION', $result['output']);
    }

    /**
     * But an ALL-CAPS name with a class signal is still a class — the bias is
     * deliberate, since missing a reference is silent and flagging one is loud.
     */
    #[Test]
    public function an_all_caps_class_used_statically_is_still_checked(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);
        $this->consumerWithSource(
            '>=2026.09.13.0749 || dev-master',
            "<?php\n\nnamespace Semitexa\\Ssr\\Application;\n\n"
            . "final class Reader\n{\n"
            . "    public function run(): string\n    {\n"
            . "        return \\Semitexa\\Core\\Support\\API::name();\n"
            . "    }\n}\n",
        );

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Support\\API', $result['output']);
    }

    /**
     * `autoload.files` is PHP composer EXECUTES on load, and it need not sit
     * under any PSR-4 root. Scanning only the mapped directories left a root
     * bootstrap unread, so the promised tag could lack a class the file
     * instantiates while the gate reported success.
     */
    #[Test]
    public function an_autoload_files_entry_is_scanned(): void
    {
        $this->provider('2026.09.13.0749', ['Support/Other.php']);

        $dir = $this->root . '/packages/semitexa-ssr';
        mkdir($dir . '/src', 0777, true);
        file_put_contents($dir . '/composer.json', (string) json_encode([
            'name' => 'semitexa/ssr',
            'require' => ['php' => '^8.4', 'semitexa/core' => '>=2026.09.13.0749 || dev-master'],
            'autoload' => [
                'psr-4' => ['Semitexa\\Ssr\\' => 'src/'],
                'files' => ['bootstrap.php'],
            ],
        ]));
        file_put_contents(
            $dir . '/bootstrap.php',
            "<?php\n\n\$row = new \\Semitexa\\Core\\Support\\Row();\n",
        );

        $result = $this->gate();

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('Semitexa\\Core\\Support\\Row', $result['output']);
    }
}

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
            file_put_contents($dir . '/src/' . $relative, "<?php\n");
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

    /** @return array{exit: int, output: string} */
    private function gate(): array
    {
        $script = dirname(__DIR__, 2)
            . '/resources/skills/release-readiness/scripts/release-check-internal-constraints.php';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, $script],
            $descriptors,
            $pipes,
            null,
            ['RELEASE_ROOT' => $this->root, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
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
        file_put_contents($dir . '/lib/Support/Row.php', "<?php\n");

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
}

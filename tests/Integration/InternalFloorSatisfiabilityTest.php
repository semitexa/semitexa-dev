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

    /** `*` promises nothing, so there is nothing to verify against. */
    #[Test]
    public function a_wildcard_is_not_checked(): void
    {
        $this->provider('2026.09.13.1330', ['Support/Other.php']);
        $this->consumer('*', 'Semitexa\\Core\\Support\\Row');

        self::assertSame(0, $this->gate()['exit'], 'a wildcard makes no promise to break');
    }

    /** An import from outside the floored package is none of this check's business. */
    #[Test]
    public function an_unrelated_import_is_ignored(): void
    {
        $this->provider('2026.09.13.1330', ['Support/Row.php']);
        $this->consumer('>=2026.09.13.1330 || dev-master', 'Psr\\Log\\LoggerInterface');

        self::assertSame(0, $this->gate()['exit']);
    }
}

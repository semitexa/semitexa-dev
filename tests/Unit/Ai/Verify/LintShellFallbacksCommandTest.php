<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\LintShellFallbacksCommand;
use Symfony\Component\Console\Tester\CommandTester;

/** lint:shell-fallbacks as it is run: what it reads for which options, and what it prints (review, dev#282). */
final class LintShellFallbacksCommandTest extends TestCase
{
    private const BAD = "#!/bin/sh\n: > \"\$log\" || echo fallback\n";

    private string $root;

    private string|false $cwd;

    /** What ProjectRoot held before this test: put back as it was, since another test may have left it there on purpose. */
    private ?string $rootBefore = null;

    protected function setUp(): void
    {
        $before = (new \ReflectionProperty(ProjectRoot::class, 'root'))->getValue();
        $this->rootBefore = is_string($before) ? $before : null;
        $this->root = sys_get_temp_dir() . '/semitexa-lint-shell-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src/modules', 0777, true);
        mkdir($this->root . '/bin');
        file_put_contents($this->root . '/composer.json', '{}');
        $this->cwd = getcwd();
        chdir($this->root);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        if ($this->cwd !== false) {
            chdir($this->cwd);
        }
        (new \ReflectionProperty(ProjectRoot::class, 'root'))->setValue(null, $this->rootBefore);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** Review round 2, dev#282: the root an earlier test left in the static is put back, not cleared. */
    #[Test]
    public function the_project_root_it_found_is_the_one_it_leaves(): void
    {
        $property = new \ReflectionProperty(ProjectRoot::class, 'root');
        $this->tearDown();
        $before = $property->getValue();
        $property->setValue(null, '/a-root-an-earlier-test-left');

        try {
            $this->setUp();
            self::assertSame($this->root, ProjectRoot::get(), 'inside the test the root is its own');
            $this->tearDown();
            self::assertSame('/a-root-an-earlier-test-left', $property->getValue());
        } finally {
            $property->setValue(null, $before);
            // PHPUnit's own tearDown still runs: give it a fixture to end.
            $this->setUp();
        }
    }

    #[Test]
    public function a_given_file_outside_the_walked_directories_is_read(): void
    {
        file_put_contents($this->root . '/deploy.sh', self::BAD);
        file_put_contents($this->root . '/bin/tool', self::BAD);

        $alone = new CommandTester(new LintShellFallbacksCommand());
        self::assertSame(0, (new CommandTester(new LintShellFallbacksCommand()))->execute(['--file' => ['composer.json']]), 'a file that is no sh script has nothing to find');
        self::assertSame(1, $alone->execute(['--file' => ['deploy.sh'], '--json' => true]));
        self::assertSame(['deploy.sh'], array_keys(self::problems($alone)));

        $beside = new CommandTester(new LintShellFallbacksCommand());
        self::assertSame(1, $beside->execute(['--file' => ['deploy.sh'], '--all' => true, '--json' => true]));
        self::assertSame(['bin/tool', 'deploy.sh'], array_keys(self::problems($beside)));
    }

    #[Test]
    public function an_empty_file_value_fails_and_scans_nothing_else(): void
    {
        file_put_contents($this->root . '/bin/tool', "#!/bin/sh\necho fine\n");

        $tester = new CommandTester(new LintShellFallbacksCommand());

        self::assertSame(1, $tester->execute(['--file' => ['']]));
        self::assertStringContainsString('(empty --file) no path was given', $tester->getDisplay());
    }

    #[Test]
    public function a_path_that_is_not_utf8_still_gets_its_json_diagnostic(): void
    {
        $name = "bin/bad\xFFname";
        self::assertNotFalse(@file_put_contents($this->root . '/' . $name, self::BAD), 'this filesystem takes the byte');

        $tester = new CommandTester(new LintShellFallbacksCommand());

        self::assertSame(1, $tester->execute(['--json' => true]));
        $problems = self::problems($tester);
        self::assertCount(1, $problems);
        self::assertStringStartsWith('bin/bad', (string) array_key_first($problems));
        self::assertStringStartsWith('line 2: `:` with a file redirection', $problems[array_key_first($problems)][0]);
    }

    /** @return array<string, list<string>> */
    private static function problems(CommandTester $tester): array
    {
        $envelope = json_decode($tester->getDisplay(), true);
        self::assertIsArray($envelope, 'the command printed JSON: ' . $tester->getDisplay());
        self::assertFalse($envelope['ok']);
        self::assertIsArray($envelope['problems']);

        /** @var array<string, list<string>> */
        return $envelope['problems'];
    }
}

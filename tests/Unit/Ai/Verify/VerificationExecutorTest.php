<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Ai\Verify\ChangedFile;
use Semitexa\Dev\Ai\Verify\ProcessRunner;
use Semitexa\Dev\Ai\Verify\VerificationExecutor;
use Semitexa\Dev\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Ai\Verify\VerificationResult;
use Semitexa\Dev\Ai\Verify\VerificationTarget;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VerificationExecutorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-verify-exec-' . uniqid();
        mkdir($this->root . '/src', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function test_lint_target_passes_when_command_exits_zero(): void
    {
        $app = new Application();
        $app->add($this->fakeLintCommand('lint:fake', 0, "Linting...\n[OK] all checks pass\n"));

        $plan = $this->planWith([
            new VerificationTarget(VerificationTarget::TYPE_LINT, 'lint:fake', 'r', [], commandName: 'lint:fake'),
        ]);
        $results = (new VerificationExecutor($app, $this->root, new RecordingProcessRunner()))->execute($plan);

        $this->assertCount(1, $results);
        $this->assertSame(VerificationResult::STATUS_PASS, $results[0]->status);
        $this->assertSame(0, $results[0]->exitCode);
        $this->assertStringContainsString('all checks pass', $results[0]->signal);
    }

    public function test_lint_target_fails_with_signal_line_from_output(): void
    {
        $app = new Application();
        $app->add($this->fakeLintCommand('lint:fake', 1, "Linting...\n[ERROR] missing handler\n"));

        $plan = $this->planWith([
            new VerificationTarget(VerificationTarget::TYPE_LINT, 'lint:fake', 'r', [], commandName: 'lint:fake'),
        ]);
        $results = (new VerificationExecutor($app, $this->root, new RecordingProcessRunner()))->execute($plan);

        $this->assertSame(VerificationResult::STATUS_FAIL, $results[0]->status);
        $this->assertSame(1, $results[0]->exitCode);
        $this->assertStringContainsString('missing handler', $results[0]->signal);
    }

    public function test_lint_target_skipped_when_command_not_registered(): void
    {
        $plan = $this->planWith([
            new VerificationTarget(VerificationTarget::TYPE_LINT, 'lint:nope', 'r', [], commandName: 'lint:nope'),
        ]);
        $results = (new VerificationExecutor(new Application(), $this->root, new RecordingProcessRunner()))->execute($plan);

        $this->assertSame(VerificationResult::STATUS_SKIPPED, $results[0]->status);
        $this->assertStringContainsString('not registered', $results[0]->signal);
    }

    public function test_syntax_target_invokes_php_l_against_real_file_path(): void
    {
        $rel = 'src/Sample.php';
        file_put_contents($this->root . '/' . $rel, "<?php\necho 1;\n");

        $runner = new RecordingProcessRunner(['exit' => 0, 'output' => "No syntax errors detected in {$rel}"]);
        $plan = $this->planWith([
            new VerificationTarget(VerificationTarget::TYPE_SYNTAX, "syntax:{$rel}", 'r', [$rel], filePath: $rel),
        ]);
        $results = (new VerificationExecutor(new Application(), $this->root, $runner, '/usr/bin/php-fake'))->execute($plan);

        $this->assertSame(VerificationResult::STATUS_PASS, $results[0]->status);
        $this->assertSame('/usr/bin/php-fake', $runner->calls[0]['command'][0]);
        $this->assertSame('-l', $runner->calls[0]['command'][1]);
        $this->assertSame($this->root . '/' . $rel, $runner->calls[0]['command'][2]);
        $this->assertSame($this->root, $runner->calls[0]['cwd']);
    }

    public function test_syntax_target_skipped_when_file_missing(): void
    {
        $runner = new RecordingProcessRunner();
        $plan = $this->planWith([
            new VerificationTarget(VerificationTarget::TYPE_SYNTAX, 'syntax:gone.php', 'r', ['gone.php'], filePath: 'gone.php'),
        ]);
        $results = (new VerificationExecutor(new Application(), $this->root, $runner))->execute($plan);

        $this->assertSame(VerificationResult::STATUS_SKIPPED, $results[0]->status);
        $this->assertStringContainsString('no longer exists', $results[0]->signal);
        $this->assertSame([], $runner->calls);
    }

    public function test_phpunit_target_skipped_without_binary(): void
    {
        $plan = $this->planWith([
            new VerificationTarget(VerificationTarget::TYPE_PHPUNIT, 'phpunit:X', 'r', [], testFilter: 'XTest'),
        ]);
        $results = (new VerificationExecutor(new Application(), $this->root, new RecordingProcessRunner()))->execute($plan);

        $this->assertSame(VerificationResult::STATUS_SKIPPED, $results[0]->status);
        $this->assertStringContainsString('phpunit binary not found', $results[0]->signal);
    }

    public function test_phpunit_target_runs_with_filter_when_binary_present(): void
    {
        $bin = $this->root . '/vendor/bin';
        mkdir($bin, 0755, true);
        $binFile = $bin . '/phpunit';
        file_put_contents($binFile, "#!/bin/sh\nexit 0\n");
        chmod($binFile, 0755);

        $runner = new RecordingProcessRunner(['exit' => 0, 'output' => "OK (3 tests, 5 assertions)"]);
        $plan = $this->planWith([
            new VerificationTarget(VerificationTarget::TYPE_PHPUNIT, 'phpunit:Foo', 'r', [], testFilter: 'FooTest'),
        ]);
        $results = (new VerificationExecutor(new Application(), $this->root, $runner))->execute($plan);

        $this->assertSame(VerificationResult::STATUS_PASS, $results[0]->status);
        $this->assertContains('--filter', $runner->calls[0]['command']);
        $this->assertContains('FooTest', $runner->calls[0]['command']);
    }

    public function test_phpunit_target_passes_file_path_positionally_when_planner_supplies_it(): void
    {
        $bin = $this->root . '/vendor/bin';
        mkdir($bin, 0755, true);
        $binFile = $bin . '/phpunit';
        file_put_contents($binFile, "#!/bin/sh\nexit 0\n");
        chmod($binFile, 0755);
        mkdir($this->root . '/packages/foo/tests', 0755, true);
        file_put_contents($this->root . '/packages/foo/tests/FooTest.php', '<?php class FooTest {}');

        $runner = new RecordingProcessRunner(['exit' => 0, 'output' => 'OK (1 test, 1 assertion)']);
        $plan = $this->planWith([
            new VerificationTarget(
                VerificationTarget::TYPE_PHPUNIT,
                'phpunit:Foo',
                'r',
                [],
                filePath: 'packages/foo/tests/FooTest.php',
                testFilter: 'FooTest',
            ),
        ]);
        (new VerificationExecutor(new Application(), $this->root, $runner))->execute($plan);

        $this->assertContains('packages/foo/tests/FooTest.php', $runner->calls[0]['command']);
    }

    public function test_phpunit_target_fails_when_no_tests_executed_despite_zero_exit(): void
    {
        $bin = $this->root . '/vendor/bin';
        mkdir($bin, 0755, true);
        $binFile = $bin . '/phpunit';
        file_put_contents($binFile, "#!/bin/sh\nexit 0\n");
        chmod($binFile, 0755);

        $runner = new RecordingProcessRunner([
            'exit' => 0,
            'output' => "PHPUnit 10.5.x by Sebastian Bergmann.\n\nNo tests executed!\n",
        ]);
        $plan = $this->planWith([
            new VerificationTarget(VerificationTarget::TYPE_PHPUNIT, 'phpunit:Foo', 'r', [], testFilter: 'FooTest'),
        ]);
        $results = (new VerificationExecutor(new Application(), $this->root, $runner))->execute($plan);

        $this->assertNotContains('--no-output', $runner->calls[0]['command']);
        $this->assertSame(VerificationResult::STATUS_FAIL, $results[0]->status);
        $this->assertStringContainsString('matched no tests', $results[0]->signal);
    }

    public function test_phpunit_target_runs_on_directory_without_filter_when_planner_supplies_dir_only(): void
    {
        // Phase 6f.5: fixture-suite targets carry a directory in
        // `filePath` and no `testFilter`. The executor must run
        // `phpunit <dir>` (no `--filter`) so phpunit walks the whole
        // sub-tree and resolves any cross-file helper class
        // declarations sibling tests rely on.
        $bin = $this->root . '/vendor/bin';
        mkdir($bin, 0755, true);
        $binFile = $bin . '/phpunit';
        file_put_contents($binFile, "#!/bin/sh\nexit 0\n");
        chmod($binFile, 0755);
        mkdir($this->root . '/packages/foo/tests/Unit/Resource', 0755, true);

        $runner = new RecordingProcessRunner(['exit' => 0, 'output' => 'OK (3 tests, 5 assertions)']);
        $plan = $this->planWith([
            new VerificationTarget(
                VerificationTarget::TYPE_PHPUNIT,
                'phpunit:packages/foo/tests/Unit/Resource',
                'fixture changed — running enclosing suite',
                [],
                filePath: 'packages/foo/tests/Unit/Resource',
                testFilter: null,
            ),
        ]);
        $results = (new VerificationExecutor(new Application(), $this->root, $runner))->execute($plan);

        $this->assertSame(VerificationResult::STATUS_PASS, $results[0]->status);
        $this->assertNotContains('--filter', $runner->calls[0]['command'], 'directory-scoped target must not pass --filter');
        $this->assertContains('packages/foo/tests/Unit/Resource', $runner->calls[0]['command']);
    }

    public function test_phpunit_directory_target_skipped_when_directory_missing(): void
    {
        $bin = $this->root . '/vendor/bin';
        mkdir($bin, 0755, true);
        $binFile = $bin . '/phpunit';
        file_put_contents($binFile, "#!/bin/sh\nexit 0\n");
        chmod($binFile, 0755);

        $runner = new RecordingProcessRunner();
        $plan = $this->planWith([
            new VerificationTarget(
                VerificationTarget::TYPE_PHPUNIT,
                'phpunit:vanished/dir',
                'r',
                [],
                filePath: 'vanished/dir',
                testFilter: null,
            ),
        ]);
        $results = (new VerificationExecutor(new Application(), $this->root, $runner))->execute($plan);

        $this->assertSame(VerificationResult::STATUS_SKIPPED, $results[0]->status);
        $this->assertStringContainsString('no longer exists', $results[0]->signal);
        $this->assertSame([], $runner->calls);
    }

    public function test_unknown_target_type_is_skipped(): void
    {
        $plan = $this->planWith([
            new VerificationTarget('mystery', 'mystery:1', 'r', []),
        ]);
        $results = (new VerificationExecutor(new Application(), $this->root, new RecordingProcessRunner()))->execute($plan);

        $this->assertSame(VerificationResult::STATUS_SKIPPED, $results[0]->status);
    }

    /**
     * @param list<VerificationTarget> $targets
     */
    private function planWith(array $targets): VerificationPlan
    {
        return new VerificationPlan(
            scope: VerificationPlan::SCOPE_STANDARD,
            effectiveScope: VerificationPlan::SCOPE_STANDARD,
            changedFiles: [new ChangedFile('whatever.php', ChangedFile::KIND_PHP_OTHER)],
            targets: $targets,
        );
    }

    private function fakeLintCommand(string $name, int $exit, string $output): Command
    {
        return new class($name, $exit, $output) extends Command {
            public function __construct(string $name, private readonly int $exit, private readonly string $output) {
                parent::__construct($name);
            }
            protected function execute(InputInterface $input, OutputInterface $sink): int {
                $sink->write($this->output);
                return $this->exit;
            }
        };
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

final class RecordingProcessRunner implements ProcessRunner
{
    /** @var list<array{command: list<string>, cwd: string}> */
    public array $calls = [];

    public function __construct(private readonly array $stub = ['exit' => 0, 'output' => ''])
    {
    }

    public function run(array $command, string $cwd): array
    {
        $this->calls[] = ['command' => $command, 'cwd' => $cwd];
        return $this->stub;
    }
}

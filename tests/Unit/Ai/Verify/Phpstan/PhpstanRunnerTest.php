<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Phpstan;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Ai\Verify\Phpstan\PhpstanRunner;
use Semitexa\Dev\Ai\Verify\Phpstan\PhpstanRunResult;
use Semitexa\Dev\Ai\Verify\ProcessRunner;

class PhpstanRunnerTest extends TestCase
{
    public function test_clean_phpstan_output_yields_pass_with_zero_diagnostics(): void
    {
        $cleanJson = json_encode([
            'totals' => ['errors' => 0, 'file_errors' => 0],
            'files'  => new \stdClass(),
            'errors' => [],
        ]);

        $runner = $this->runnerWithFakeProcess(0, (string) $cleanJson);
        $result = $runner->run(['packages/foo/src/Foo.php']);

        $this->assertSame(PhpstanRunResult::STATUS_PASS, $result->status);
        $this->assertSame([], $result->diagnostics);
    }

    public function test_phpstan_message_is_re_emitted_with_identifier_verbatim(): void
    {
        // Mirrors the real `semitexa.injectionViaConstructor` shape — the
        // identifier must flow through verbatim so NDJSON consumers can route
        // on the same key the project's `composer phpstan` uses.
        $json = json_encode([
            'totals' => ['errors' => 0, 'file_errors' => 1],
            'files'  => [
                '/var/www/html/packages/semitexa-api/src/Application/Console/Command/DumpOpenApiCommand.php' => [
                    'errors'   => 1,
                    'messages' => [
                        [
                            'message'    => 'Constructor injection is not the DI channel on container-managed Foo. ...',
                            'line'       => 26,
                            'ignorable'  => true,
                            'identifier' => 'semitexa.injectionViaConstructor',
                            'tip'        => null,
                        ],
                    ],
                ],
            ],
            'errors' => [],
        ]);

        $runner = $this->runnerWithFakeProcess(1, (string) $json, projectRoot: '/var/www/html');
        $result = $runner->run(['packages/semitexa-api/src/Application/Console/Command/DumpOpenApiCommand.php']);

        $this->assertSame(PhpstanRunResult::STATUS_FAIL, $result->status);
        $this->assertCount(1, $result->diagnostics);
        $diag = $result->diagnostics[0];
        $this->assertSame('phpstan_di', $diag['check']);
        $this->assertSame('error', $diag['severity']);
        $this->assertSame('semitexa.injectionViaConstructor', $diag['rule']);
        $this->assertSame('semitexa.injectionViaConstructor', $diag['identifier']);
        $this->assertSame(
            'packages/semitexa-api/src/Application/Console/Command/DumpOpenApiCommand.php',
            $diag['path'],
        );
        $this->assertSame(26, $diag['line']);
        $this->assertStringContainsString('Constructor injection', (string) $diag['message']);
        $this->assertStringContainsString('#[InjectAsReadonly]', (string) $diag['suggested_fix']);
    }

    public function test_static_container_access_identifier_is_recognised(): void
    {
        $json = json_encode([
            'totals' => ['errors' => 0, 'file_errors' => 1],
            'files'  => [
                '/root/Foo.php' => [
                    'errors'   => 1,
                    'messages' => [
                        [
                            'message'    => 'Static ContainerFactory:: access is forbidden in application code (App\\Foo). Use #[InjectAs*] property injection instead.',
                            'line'       => 12,
                            'identifier' => 'semitexa.staticContainerAccess',
                        ],
                    ],
                ],
            ],
            'errors' => [],
        ]);

        $runner = $this->runnerWithFakeProcess(1, (string) $json, projectRoot: '/root');
        $result = $runner->run(['Foo.php']);

        $this->assertSame(PhpstanRunResult::STATUS_FAIL, $result->status);
        $this->assertSame('semitexa.staticContainerAccess', $result->diagnostics[0]['identifier']);
        $this->assertStringContainsString('ContainerFactory::', (string) $result->diagnostics[0]['suggested_fix']);
    }

    public function test_top_level_phpstan_errors_are_propagated(): void
    {
        $json = json_encode([
            'totals' => ['errors' => 1, 'file_errors' => 0],
            'files'  => new \stdClass(),
            'errors' => ['Configuration option foo is not defined.'],
        ]);

        $runner = $this->runnerWithFakeProcess(1, (string) $json);
        $result = $runner->run(['Foo.php']);

        $this->assertSame(PhpstanRunResult::STATUS_FAIL, $result->status);
        $this->assertCount(1, $result->diagnostics);
        $this->assertSame('phpstan.error', $result->diagnostics[0]['identifier']);
    }

    public function test_unparseable_output_is_reported_as_skipped(): void
    {
        $runner = $this->runnerWithFakeProcess(0, "not JSON at all\nsome garbage\n");
        $result = $runner->run(['Foo.php']);

        $this->assertSame(PhpstanRunResult::STATUS_SKIPPED, $result->status);
        $this->assertSame([], $result->diagnostics);
        $this->assertStringContainsString('not valid JSON', $result->rawSignal);
    }

    public function test_empty_path_list_returns_immediate_pass(): void
    {
        $process = new class implements ProcessRunner {
            public bool $called = false;
            public function run(array $command, string $cwd): array
            {
                $this->called = true;
                return ['exit' => 0, 'output' => '{}'];
            }
        };
        $runner = new PhpstanRunner(
            projectRoot: '/var/www/html',
            processRunner: $process,
            phpstanBinary: '/var/www/html/vendor/bin/phpstan-fake',
            configPath: __FILE__, // any extant file — just to satisfy the existence check
        );

        $result = $runner->run([]);

        $this->assertSame(PhpstanRunResult::STATUS_PASS, $result->status);
        $this->assertFalse($process->called, 'PHPStan must not be spawned for an empty file list');
    }

    public function test_missing_phpstan_binary_yields_skipped_with_actionable_signal(): void
    {
        $runner = new PhpstanRunner(
            projectRoot: sys_get_temp_dir() . '/no-phpstan-here',
            processRunner: $this->stubProcess(0, '{}'),
            // explicit null → discoverPhpstanBinary will fail to find one
            phpstanBinary: null,
            configPath: __FILE__,
        );

        $result = $runner->run(['Foo.php']);
        $this->assertSame(PhpstanRunResult::STATUS_SKIPPED, $result->status);
        $this->assertStringContainsString('phpstan binary not found', $result->rawSignal);
    }

    private function runnerWithFakeProcess(int $exit, string $output, string $projectRoot = '/var/www/html'): PhpstanRunner
    {
        return new PhpstanRunner(
            projectRoot: $projectRoot,
            processRunner: $this->stubProcess($exit, $output),
            // pretend the binary exists by pointing at __FILE__ — PhpstanRunner
            // only checks file_exists + executable, so any extant file works
            // for the unit-test-process that never actually shells out.
            phpstanBinary: __FILE__,
            configPath: __FILE__,
        );
    }

    private function stubProcess(int $exit, string $output): ProcessRunner
    {
        return new class($exit, $output) implements ProcessRunner {
            public function __construct(
                private readonly int $exit,
                private readonly string $output,
            ) {}

            public function run(array $command, string $cwd): array
            {
                return ['exit' => $this->exit, 'output' => $this->output];
            }
        };
    }
}

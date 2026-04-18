<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Ai\Trace\TraceStore;
use Semitexa\Dev\Console\Command\AiVerifyCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end exercise of `ai:verify`: temp project root, fake lint commands
 * registered to the application, paths fed through `--files`, NDJSON + JSON
 * output shapes verified.
 *
 * We never let the executor actually shell out: every changed file is a
 * fictional path that fails the syntax existence check (skipped) so the
 * lint-target dispatch is what we observe end-to-end.
 */
class AiVerifyCommandTest extends TestCase
{
    private string $tmpRoot;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/semitexa-ai-verify-' . uniqid();
        mkdir($this->tmpRoot . '/src/modules', 0755, true);
        file_put_contents($this->tmpRoot . '/composer.json', '{"name":"temp/project"}');

        $this->originalCwd = getcwd() ?: null;
        chdir($this->tmpRoot);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            chdir($this->originalCwd);
        }
        ProjectRoot::reset();
        $this->removeDir($this->tmpRoot);
    }

    public function test_ndjson_envelope_for_a_handler_change(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute([
            '--files' => 'src/modules/Foo/Application/Handler/PayloadHandler/GetThingHandler.php',
            '--scope' => 'standard',
        ]);

        $this->assertSame(0, $exit);
        $lines = $this->ndjsonLines($tester->getDisplay());

        $summary = $lines[0];
        $this->assertSame('summary', $summary['kind']);
        $this->assertSame('standard', $summary['effective_scope']);
        $this->assertSame(1, $summary['changed_files']);

        $kinds = array_column($lines, 'kind');
        $this->assertContains('target', $kinds);
        $this->assertContains('result', $kinds);
        $this->assertContains('verdict', $kinds);

        $verdict = end($lines);
        $this->assertSame('verdict', $verdict['kind']);
        $this->assertSame('pass', $verdict['verdict']);
    }

    public function test_lint_failures_propagate_to_verdict_and_exit_code(): void
    {
        $tester = $this->newTester(failingLints: ['semitexa:lint:handlers']);
        $exit = $tester->execute([
            '--files' => 'src/modules/Foo/Application/Handler/PayloadHandler/GetThingHandler.php',
        ]);

        $this->assertSame(1, $exit);
        $lines = $this->ndjsonLines($tester->getDisplay());
        $verdict = end($lines);
        $this->assertSame('fail', $verdict['verdict']);
        $this->assertSame(1, $verdict['counts']['fail']);

        $resultLines = array_values(array_filter($lines, static fn($l) => $l['kind'] === 'result'));
        $failingLint = array_filter($resultLines, static fn($l) => $l['result']['id'] === 'lint:semitexa:lint:handlers');
        $this->assertNotEmpty($failingLint);
        $failingLint = array_values($failingLint)[0];
        $this->assertSame('fail', $failingLint['result']['status']);
    }

    public function test_contract_change_emits_expansion_event(): void
    {
        $tester = $this->newTester();
        $tester->execute([
            '--files' => 'src/modules/Foo/Domain/Contract/UserRepoInterface.php',
        ]);

        $lines = $this->ndjsonLines($tester->getDisplay());
        $expansions = array_filter($lines, static fn($l) => $l['kind'] === 'expansion');
        $this->assertNotEmpty($expansions);
        $expansion = array_values($expansions)[0];
        $this->assertStringContainsString('contract changed', $expansion['note']);

        $summary = $lines[0];
        $this->assertSame('broad', $summary['effective_scope']);
    }

    public function test_json_mode_emits_single_envelope_with_artifact_id(): void
    {
        $tester = $this->newTester();
        $tester->execute([
            '--files' => 'src/modules/Foo/Application/Payload/Request/Get.php',
            '--json'  => true,
        ]);

        $payload = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame('semitexa-dev.verify-report/v1', $payload['artifact']);
        $this->assertSame('standard', $payload['effective_scope']);
        $this->assertNotEmpty($payload['targets']);
        $this->assertNotEmpty($payload['results']);
        $this->assertArrayHasKey('verdict', $payload);
        $this->assertArrayHasKey('counts', $payload);
    }

    public function test_minimal_scope_runs_only_syntax(): void
    {
        $tester = $this->newTester();
        $tester->execute([
            '--files' => 'src/modules/Foo/Application/Handler/PayloadHandler/GetThingHandler.php',
            '--scope' => 'minimal',
            '--json'  => true,
        ]);

        $payload = json_decode(trim($tester->getDisplay()), true);
        $types = array_column($payload['targets'], 'type');
        $this->assertSame(['syntax'], array_unique($types));
    }

    public function test_no_inputs_returns_error_envelope(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute([]);

        $this->assertSame(1, $exit);
        $line = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame('error', $line['kind']);
        $this->assertStringContainsString('no changed files supplied', $line['error']);
    }

    public function test_trace_option_appends_verify_result_event(): void
    {
        $store = new TraceStore($this->tmpRoot);
        $store->openOrCreate('ship-x', 'Ship feature X');

        $tester = $this->newTester();
        $exit = $tester->execute([
            '--files' => 'src/modules/Foo/Application/Handler/PayloadHandler/A.php',
            '--trace' => 'ship-x',
            '--json'  => true,
        ]);

        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('"trace_appended"', $display);

        $trace = $store->read('ship-x');
        $this->assertCount(1, $trace->events);
        $event = $trace->events[0];
        $this->assertSame(TraceEventKind::VERIFY_RESULT, $event->eventKind);
        $this->assertStringStartsWith('verify ', $event->summary);
        $this->assertArrayHasKey('verdict', $event->payload);
        $this->assertArrayHasKey('counts', $event->payload);
        $this->assertSame('semitexa-dev.verify-report/v1', $event->payload['artifact']);
    }

    public function test_trace_option_on_missing_trace_emits_trace_skipped(): void
    {
        $tester = $this->newTester();
        $tester->execute([
            '--files' => 'src/modules/Foo/Application/Payload/Request/Get.php',
            '--trace' => 'does-not-exist',
        ]);

        $this->assertStringContainsString('"trace_skipped"', $tester->getDisplay());
    }

    public function test_trace_fallback_env_var_is_honoured(): void
    {
        $store = new TraceStore($this->tmpRoot);
        $store->openOrCreate('env-trace');
        putenv('SEMITEXA_AI_TRACE_ID=env-trace');
        try {
            $tester = $this->newTester();
            $tester->execute([
                '--files' => 'src/modules/Foo/Application/Payload/Request/Get.php',
            ]);
            $this->assertStringContainsString('"trace_appended"', $tester->getDisplay());
            $trace = $store->read('env-trace');
            $this->assertCount(1, $trace->events);
        } finally {
            putenv('SEMITEXA_AI_TRACE_ID');
        }
    }

    public function test_no_trace_option_means_no_trace_events_emitted(): void
    {
        $tester = $this->newTester();
        $tester->execute([
            '--files' => 'src/modules/Foo/Application/Payload/Request/Get.php',
        ]);
        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('"trace_appended"', $display);
        $this->assertStringNotContainsString('"trace_skipped"', $display);
    }

    public function test_files_option_accepts_multiple_comma_separated_paths(): void
    {
        $tester = $this->newTester();
        $tester->execute([
            '--files' => 'src/modules/Foo/Application/Handler/PayloadHandler/A.php,src/modules/Foo/Application/Payload/Request/B.php',
            '--json'  => true,
        ]);

        $payload = json_decode(trim($tester->getDisplay()), true);
        $this->assertCount(2, $payload['changed_files']);
        $kinds = array_column($payload['changed_files'], 'kind');
        $this->assertContains('handler', $kinds);
        $this->assertContains('payload', $kinds);
    }

    /**
     * @param list<string> $failingLints
     */
    private function newTester(array $failingLints = []): CommandTester
    {
        $app = new Application();
        $app->add(new AiVerifyCommand());

        foreach ([
            'semitexa:lint:handlers',
            'semitexa:lint:di',
            'semitexa:lint:scoping',
            'semitexa:lint:responses',
            'semitexa:lint:templates',
        ] as $name) {
            $app->add(new class($name, in_array($name, $failingLints, true)) extends Command {
                public function __construct(string $name, private readonly bool $shouldFail) {
                    parent::__construct($name);
                }
                protected function execute(InputInterface $input, OutputInterface $sink): int {
                    $sink->writeln($this->shouldFail ? "[ERROR] {$this->getName()} found problems" : "[OK] {$this->getName()} clean");
                    return $this->shouldFail ? 1 : 0;
                }
            });
        }

        return new CommandTester($app->find('ai:verify'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ndjsonLines(string $output): array
    {
        $lines = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $lines[] = $decoded;
            }
        }
        return $lines;
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

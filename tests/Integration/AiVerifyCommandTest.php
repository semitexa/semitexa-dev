<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceAutoAppender;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
use Semitexa\Dev\Application\Console\Command\AiVerifyCommand;
use Semitexa\Dev\Tests\Support\ArrayContainer;
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
        // Hermeticity against an inherited SEMITEXA_AI_TRACE_ID is handled once,
        // process-wide, by the shared PHPUnit bootstrap (semitexa-testing).
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
            '--files' => 'src/modules/Foo/src/Application/Handler/PayloadHandler/GetThingHandler.php',
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
        $tester = $this->newTester(failingLints: ['lint:handlers']);
        $exit = $tester->execute([
            '--files' => 'src/modules/Foo/src/Application/Handler/PayloadHandler/GetThingHandler.php',
        ]);

        $this->assertSame(1, $exit);
        $lines = $this->ndjsonLines($tester->getDisplay());
        $verdict = end($lines);
        $this->assertSame('fail', $verdict['verdict']);
        $this->assertSame(1, $verdict['counts']['fail']);

        $resultLines = array_values(array_filter($lines, static fn($l) => $l['kind'] === 'result'));
        $failingLint = array_filter($resultLines, static fn($l) => $l['result']['id'] === 'lint:lint:handlers');
        $this->assertNotEmpty($failingLint);
        $failingLint = array_values($failingLint)[0];
        $this->assertSame('fail', $failingLint['result']['status']);
    }

    public function test_contract_change_emits_expansion_event(): void
    {
        $tester = $this->newTester();
        $tester->execute([
            '--files' => 'src/modules/Foo/src/Domain/Contract/UserRepoInterface.php',
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
            '--files' => 'src/modules/Foo/src/Application/Payload/Request/Get.php',
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

    /**
     * The non-negotiable gate must never silently drop files. Both input forms —
     * repeated flags and a comma-separated batch — must verify the full set. The
     * repeated-flag form previously kept only the last value (VALUE_REQUIRED) and
     * returned a false-green partial verification.
     *
     * @param list<string>|string $files
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('multiFileForms')]
    public function test_every_files_input_form_verifies_the_full_set(array|string $files): void
    {
        $tester = $this->newTester();
        $tester->execute(['--files' => $files, '--json' => true]);

        $payload = json_decode(trim($tester->getDisplay()), true);
        $paths = array_column($payload['changed_files'], 'path');

        $this->assertContains('src/modules/Foo/src/Application/Payload/Request/Get.php', $paths);
        $this->assertContains('src/modules/Bar/src/Application/Handler/PayloadHandler/RunHandler.php', $paths);
        $this->assertCount(2, $paths);
    }

    /** @return iterable<string, array{list<string>|string}> */
    public static function multiFileForms(): iterable
    {
        $a = 'src/modules/Foo/src/Application/Payload/Request/Get.php';
        $b = 'src/modules/Bar/src/Application/Handler/PayloadHandler/RunHandler.php';

        yield 'repeated flags' => [[$a, $b]];
        yield 'comma-separated' => ["{$a},{$b}"];
        // One entry is comma-batched ("a,b") AND repeated alongside a plain "a",
        // so this case actually exercises mixed parsing (the previous two plain
        // entries just duplicated the repeated-flags case).
        yield 'comma batch + repeated flag combined' => [["{$a},{$b}", $a]];
    }

    public function test_minimal_scope_runs_syntax_and_structure_but_no_production_lints(): void
    {
        $tester = $this->newTester();
        $tester->execute([
            '--files' => 'src/modules/Foo/src/Application/Handler/PayloadHandler/GetThingHandler.php',
            '--scope' => 'minimal',
            '--json'  => true,
        ]);

        $payload = json_decode(trim($tester->getDisplay()), true);
        $types = array_unique(array_column($payload['targets'], 'type'));
        sort($types);
        // `live_tenancy` joins `module_structure` in the every-scope tier:
        // both are cheap scans guarding drift that must never wait for a
        // wider scope (structure rot / cross-tenant live-grid leaks). The
        // expensive production lints (phpstan_di) stay excluded here.
        //
        // `skill_copies` is in the same tier and is scheduled on every run rather
        // than by a changed path: the copies it guards live outside any git
        // repository, so the edit it exists to catch can never appear in a
        // changed-file list. It is a cmp over ~26 small files.
        $this->assertSame(['live_tenancy', 'module_structure', 'skill_copies', 'syntax'], $types);
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
        $store = new TraceStore();
        $store->openOrCreate('ship-x', 'Ship feature X');

        $tester = $this->newTester();
        $exit = $tester->execute([
            '--files' => 'src/modules/Foo/src/Application/Handler/PayloadHandler/A.php',
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
            '--files' => 'src/modules/Foo/src/Application/Payload/Request/Get.php',
            '--trace' => 'does-not-exist',
        ]);

        $this->assertStringContainsString('"trace_skipped"', $tester->getDisplay());
    }

    public function test_trace_fallback_env_var_is_honoured(): void
    {
        $store = new TraceStore();
        $store->openOrCreate('env-trace');
        putenv('SEMITEXA_AI_TRACE_ID=env-trace');
        try {
            $tester = $this->newTester();
            $tester->execute([
                '--files' => 'src/modules/Foo/src/Application/Payload/Request/Get.php',
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
            '--files' => 'src/modules/Foo/src/Application/Payload/Request/Get.php',
        ]);
        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('"trace_appended"', $display);
        $this->assertStringNotContainsString('"trace_skipped"', $display);
    }

    public function test_module_structure_check_emits_violation_events_and_fails_verdict(): void
    {
        // Plant a real module with an undeclared Application/ child on the
        // temp root. (Application/Db is canonical; Application/Endpoint is not.)
        // The validator scans the whole module — touching one file in it must
        // surface the structure error even though only that file is changed.
        $this->scaffoldModule('SomeApp', [
            'Application/Handler/PayloadHandler',
            'Application/Endpoint/Bogus', // not in Application allowlist
        ]);

        $tester = $this->newTester();
        $exit = $tester->execute([
            '--files' => 'src/modules/SomeApp/src/Application/Handler/PayloadHandler/IndexHandler.php',
            '--json'  => true,
        ]);

        $this->assertSame(1, $exit, 'verdict must be fail when structure violations exist');
        $payload = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame('fail', $payload['verdict']);

        $this->assertNotEmpty($payload['violations'] ?? [], 'violations array must be populated');
        $first = $payload['violations'][0];
        $this->assertSame('module_structure', $first['check']);
        $this->assertSame('error', $first['severity']);
        $this->assertSame('module_structure.unknown_directory', $first['rule']);
        $this->assertSame('src/modules/SomeApp', $first['module']);
        $this->assertSame('src/modules/SomeApp/src/Application/Endpoint', $first['path']);
        $this->assertSame('packages/semitexa-docs/docs/MODULE_STRUCTURE.md', $first['doc_ref']);

        // The module_structure target is added at standard scope (and broad).
        $types = array_unique(array_column($payload['targets'], 'type'));
        $this->assertContains('module_structure', $types);

        // next_command should point at the spec when structure failed.
        $nextCmds = array_column($payload['next_command'], 'cmd');
        $this->assertContains('cat', $nextCmds);
    }

    public function test_module_structure_check_passes_for_canonical_layout(): void
    {
        $this->scaffoldModule('OkApp', [
            'Application/Handler/PayloadHandler',
            'Application/Payload/Request',
            'Application/Resource/Response',
            'Domain/Model',
        ]);

        $tester = $this->newTester();
        $exit = $tester->execute([
            '--files' => 'src/modules/OkApp/src/Application/Handler/PayloadHandler/Idx.php',
            '--json'  => true,
        ]);

        $payload = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame(0, $exit, 'verdict must be pass for a canonical module');
        $this->assertSame('pass', $payload['verdict']);
        $this->assertEmpty($payload['violations'] ?? []);

        // Module structure target was scheduled and resulted in pass.
        $structureResults = array_filter(
            $payload['results'],
            static fn(array $r) => $r['type'] === 'module_structure',
        );
        $this->assertCount(1, $structureResults);
        $this->assertSame('pass', array_values($structureResults)[0]['status']);
    }

    public function test_module_structure_target_emits_violation_ndjson_events(): void
    {
        // NDJSON mode (no --json) emits one `violation` event per diagnostic
        // between the corresponding `result` event and `verdict`.
        $this->scaffoldModule('SomeApp', [
            'Application/Handler/PayloadHandler',
            'Endpoint/Bogus',
        ]);

        $tester = $this->newTester();
        $tester->execute([
            '--files' => 'src/modules/SomeApp/src/Application/Handler/PayloadHandler/X.php',
        ]);

        $lines = $this->ndjsonLines($tester->getDisplay());
        $violations = array_values(array_filter($lines, static fn(array $l) => ($l['kind'] ?? null) === 'violation'));
        $this->assertNotEmpty($violations);
        $first = $violations[0];
        $this->assertSame('module_structure', $first['check']);
        $this->assertSame('module_structure.unknown_directory', $first['rule']);
        $this->assertSame('src/modules/SomeApp/Endpoint', $first['path']);
        $this->assertSame('error', $first['severity']);
        // target_id ties the event back to the structure target.
        $this->assertStringStartsWith('module_structure:', $first['target_id']);
    }

    public function test_changed_files_outside_modules_skip_module_structure_target(): void
    {
        // Top-level docs/config files outside src/modules/* and
        // packages/semitexa-* do not produce a module_structure target.
        $tester = $this->newTester();
        $tester->execute([
            '--files' => 'docs/NOTES.md,composer.json',
            '--json'  => true,
        ]);

        $payload = json_decode(trim($tester->getDisplay()), true);
        $types = array_column($payload['targets'], 'type');
        $this->assertNotContains('module_structure', $types);
    }

    public function test_package_root_schedules_module_structure_target(): void
    {
        $base = $this->tmpRoot . '/packages/semitexa-api';
        mkdir($base . '/src/Application', 0755, true);
        mkdir($base . '/tests', 0755, true);
        file_put_contents($base . '/composer.json', '{}');
        file_put_contents($base . '/LICENSE', '');
        file_put_contents($base . '/README.md', '');

        $tester = $this->newTester();
        $exit = $tester->execute([
            '--files' => 'packages/semitexa-api',
            '--json'  => true,
        ]);

        $payload = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame(0, $exit);
        $types = array_column($payload['targets'], 'type');
        $this->assertContains('module_structure', $types);
        $structure = array_values(array_filter(
            $payload['results'],
            static fn(array $r) => $r['type'] === 'module_structure',
        ))[0] ?? null;
        $this->assertNotNull($structure);
        $this->assertSame('pass', $structure['status']);
    }

    public function test_full_module_is_validated_when_only_one_file_changes(): void
    {
        // The hard requirement: even if only one file is in the diff,
        // validating that file's module must surface drift in any other
        // part of the module. Demonstrates that the structure check is
        // module-scoped, not file-scoped.
        $this->scaffoldModule('DriftedApp', [
            'Application/Payload/Request',
            'Domain/Bogus', // R003 lives in a different sub-tree from the changed file
        ]);

        $tester = $this->newTester();
        $tester->execute([
            // Touch a file under Application/Payload/Request — far away
            // from the offending Domain/Bogus directory.
            '--files' => 'src/modules/DriftedApp/src/Application/Payload/Request/IndexPayload.php',
            '--json'  => true,
        ]);

        $payload = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame('fail', $payload['verdict']);
        $rules = array_column($payload['violations'] ?? [], 'rule');
        $this->assertContains(
            'module_structure.unknown_directory',
            $rules,
            'unrelated drift in the same module must be surfaced',
        );
    }

    public function test_files_option_accepts_multiple_comma_separated_paths(): void
    {
        $tester = $this->newTester();
        $tester->execute([
            '--files' => 'src/modules/Foo/src/Application/Handler/PayloadHandler/A.php,src/modules/Foo/src/Application/Payload/Request/B.php',
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
        $traceStore = new TraceStore();
        $traceAppender = new TraceAutoAppender();
        PropertyInjector::inject($traceAppender, new ArrayContainer([
            TraceStore::class => $traceStore,
        ]));

        $verifyCommand = new AiVerifyCommand();
        PropertyInjector::inject($verifyCommand, new ArrayContainer([
            TraceAutoAppender::class => $traceAppender,
        ]));

        $app = new Application();
        $app->add($verifyCommand);

        foreach ([
            'lint:handlers',
            'lint:di',
            'lint:scoping',
            'lint:responses',
            'lint:templates',
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

    /**
     * @param list<string> $relativeDirs
     */
    private function scaffoldModule(string $name, array $relativeDirs): void
    {
        $base = $this->tmpRoot . '/src/modules/' . $name;
        if (!is_dir($base)) {
            mkdir($base, 0755, true);
        }
        foreach ($relativeDirs as $rel) {
            $dir = $base . '/' . $this->codeRelative($rel);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    private function codeRelative(string $rel): string
    {
        $top = explode('/', $rel, 2)[0] ?? '';
        $codeRoots = ['Application', 'Domain', 'Context', 'Configuration', 'Exception'];
        return in_array($top, $codeRoots, true) ? 'src/' . $rel : $rel;
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

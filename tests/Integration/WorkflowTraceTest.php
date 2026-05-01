<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceAutoAppender;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
use Semitexa\Dev\Application\Console\Command\AiContextCommand;
use Semitexa\Dev\Application\Console\Command\AiTaskCommand;
use Semitexa\Dev\Application\Console\Command\AiTraceCommand;
use Semitexa\Dev\Application\Console\Command\AiVerifyCommand;
use Semitexa\Dev\Application\Console\Command\MakeCommand;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end sanity that the Phase 4.3 trace propagation story holds across
 * the full agent workflow: ai:task → ai:context → make → ai:verify all write
 * into one shared trace, in order, with the expected event kinds.
 *
 * Every command is fed `--trace=<id>` explicitly (the primary activation
 * path). A second test exercises the `$SEMITEXA_AI_TRACE_ID` env fallback so
 * a future workflow layer can pin a trace without threading the option.
 */
class WorkflowTraceTest extends TestCase
{
    private string $tmpRoot;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/semitexa-workflow-trace-' . uniqid();
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

    public function test_explicit_trace_option_threads_events_through_task_context_make_verify(): void
    {
        $app = $this->buildApplication();
        $traceId = 'workflow';

        $this->runCommand($app, 'ai:trace', ['action' => 'start', '--id' => $traceId, '--topic' => 'Ship billing endpoint']);

        $this->runCommand($app, 'ai:task', [
            'description' => 'add authenticated json endpoint for invoices',
            '--module'    => 'billing',
            '--trace'     => $traceId,
        ]);

        $this->runCommand($app, 'ai:context', [
            'recipe'  => 'add_authenticated_json_route',
            '--trace' => $traceId,
        ]);

        $this->runCommand($app, 'make', [
            '--recipe' => 'add_authenticated_json_route',
            '--arg'    => ['module=billing', 'name=GetInvoice'],
            '--trace'  => $traceId,
            '--json'   => true,
        ]);

        $this->runCommand($app, 'ai:verify', [
            '--files' => 'src/modules/Billing/Application/Handler/PayloadHandler/GetInvoiceHandler.php',
            '--trace' => $traceId,
            '--json'  => true,
        ]);

        $trace = (new TraceStore())->read($traceId);

        $this->assertCount(4, $trace->events, 'each command should append exactly one event');
        $this->assertSame([1, 2, 3, 4], array_map(static fn($e) => $e->eventId, $trace->events));
        $this->assertSame(
            [
                TraceEventKind::TASK_RESULT,
                TraceEventKind::CONTEXT_SUMMARY,
                TraceEventKind::SCAFFOLD_ACTION,
                TraceEventKind::VERIFY_RESULT,
            ],
            array_map(static fn($e) => $e->eventKind, $trace->events),
        );

        $this->assertSame('semitexa.ai-task/v1', $trace->events[0]->payload['artifact'] ?? null);
        $this->assertSame('add_authenticated_json_route', $trace->events[0]->payload['recipe'] ?? null);

        $this->assertSame('semitexa-dev.ai-context/v1', $trace->events[1]->payload['artifact'] ?? null);
        $this->assertSame('add_authenticated_json_route', $trace->events[1]->payload['recipe'] ?? null);

        $this->assertSame('semitexa-dev.make-dispatch/v1', $trace->events[2]->payload['artifact'] ?? null);
        $this->assertSame('ok', $trace->events[2]->payload['status'] ?? null);

        $this->assertSame('semitexa-dev.verify-report/v1', $trace->events[3]->payload['artifact'] ?? null);
        $this->assertArrayHasKey('verdict', $trace->events[3]->payload);
    }

    public function test_env_var_fallback_activates_trace_without_explicit_option(): void
    {
        $app = $this->buildApplication();
        $traceId = 'env-workflow';

        $this->runCommand($app, 'ai:trace', ['action' => 'start', '--id' => $traceId]);

        putenv("SEMITEXA_AI_TRACE_ID={$traceId}");
        try {
            $this->runCommand($app, 'ai:task', [
                'description' => 'add authenticated json endpoint',
                '--module'    => 'billing',
            ]);
            $this->runCommand($app, 'ai:verify', [
                '--files' => 'src/modules/Billing/Application/Handler/PayloadHandler/A.php',
                '--json'  => true,
            ]);
        } finally {
            putenv('SEMITEXA_AI_TRACE_ID');
        }

        $trace = (new TraceStore())->read($traceId);
        $this->assertCount(2, $trace->events);
        $this->assertSame(
            [TraceEventKind::TASK_RESULT, TraceEventKind::VERIFY_RESULT],
            array_map(static fn($e) => $e->eventKind, $trace->events),
        );
    }

    public function test_workflow_without_any_trace_writes_no_events(): void
    {
        $app = $this->buildApplication();

        $this->runCommand($app, 'ai:task', ['description' => 'add page', '--module' => 'billing']);
        $this->runCommand($app, 'ai:verify', [
            '--files' => 'src/modules/Billing/Application/Payload/Request/Get.php',
            '--json'  => true,
        ]);

        $this->assertFalse(
            is_dir($this->tmpRoot . '/var/ai-traces'),
            'no trace dir should be created when no trace id is active',
        );
    }

    private function buildApplication(): Application
    {
        $traceStore = new TraceStore();
        $traceAppender = new TraceAutoAppender();
        PropertyInjector::inject($traceAppender, new ArrayContainer([
            TraceStore::class => $traceStore,
        ]));

        $traceCmdContainer = new ArrayContainer([TraceStore::class => $traceStore]);
        $appenderContainer = new ArrayContainer([TraceAutoAppender::class => $traceAppender]);

        $traceCommand = new AiTraceCommand();
        PropertyInjector::inject($traceCommand, $traceCmdContainer);

        $taskCommand = new AiTaskCommand();
        PropertyInjector::inject($taskCommand, $appenderContainer);

        $contextCommand = new AiContextCommand();
        PropertyInjector::inject($contextCommand, $appenderContainer);

        $makeCommand = new MakeCommand();
        PropertyInjector::inject($makeCommand, $appenderContainer);

        $verifyCommand = new AiVerifyCommand();
        PropertyInjector::inject($verifyCommand, $appenderContainer);

        $app = new Application();
        $app->add($traceCommand);
        $app->add($taskCommand);
        $app->add($contextCommand);
        $app->add($makeCommand);
        $app->add($verifyCommand);

        foreach (['make:payload', 'make:handler', 'make:resource'] as $name) {
            $app->add($this->fakeMakeCommand($name));
        }
        foreach ([
            'lint:handlers',
            'lint:di',
            'lint:scoping',
            'lint:responses',
            'lint:templates',
        ] as $name) {
            $app->add($this->fakeLintCommand($name));
        }
        return $app;
    }

    private function fakeMakeCommand(string $name): Command
    {
        return new class($name) extends Command {
            public function __construct(string $name) {
                parent::__construct($name);
            }
            protected function configure(): void {
                $this
                    ->addOption('module', null, InputOption::VALUE_REQUIRED)
                    ->addOption('name', null, InputOption::VALUE_REQUIRED)
                    ->addOption('write', null, InputOption::VALUE_NONE)
                    ->addOption('json', null, InputOption::VALUE_NONE);
            }
            protected function execute(InputInterface $input, OutputInterface $output): int {
                $output->writeln(json_encode([
                    'artifact' => 'semitexa-dev.generation-result/v1',
                    'result'   => ['command' => $this->getName(), 'status' => 'dry_run'],
                ]));
                return self::SUCCESS;
            }
        };
    }

    private function fakeLintCommand(string $name): Command
    {
        return new class($name) extends Command {
            public function __construct(string $name) {
                parent::__construct($name);
            }
            protected function execute(InputInterface $input, OutputInterface $output): int {
                $output->writeln("[OK] {$this->getName()} clean");
                return self::SUCCESS;
            }
        };
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(Application $app, string $name, array $input): CommandTester
    {
        $tester = new CommandTester($app->find($name));
        $tester->execute($input);
        return $tester;
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

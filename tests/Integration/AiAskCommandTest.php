<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Console\Command\AiAskCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `ai:ask` is a thin subject dispatcher. These tests register a fake target
 * for every subject (dev:graph:*, logs:app) that records
 * which options reached it, so we can prove the forwarding contract without
 * dragging in full project discovery.
 */
class AiAskCommandTest extends TestCase
{
    public function test_unknown_subject_errors_with_subject_list(): void
    {
        $app = new Application();
        $app->add(new AiAskCommand());

        $tester = new CommandTester($app->find('ai:ask'));
        $exit = $tester->execute(['subject' => 'meaning-of-life']);

        $this->assertSame(1, $exit);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame('error', $decoded['kind']);
        $this->assertStringContainsString('meaning-of-life', $decoded['error']);
        $this->assertContains('capabilities', $decoded['subjects']);
        $this->assertContains('logs', $decoded['subjects']);
    }

    public function test_capabilities_subject_delegates_to_dev_graph_capabilities(): void
    {
        $log = new \ArrayObject();
        $tester = $this->newAskWithFakes($log);
        $exit = $tester->execute(['subject' => 'capabilities', '--json' => true]);

        $this->assertSame(0, $exit);
        $this->assertCount(1, $log);
        $this->assertSame('dev:graph:capabilities', $log[0]['command']);
        $this->assertTrue($log[0]['options']['json']);
    }

    public function test_project_subject_delegates_to_dev_graph_project(): void
    {
        $log = new \ArrayObject();
        $tester = $this->newAskWithFakes($log);
        $tester->execute(['subject' => 'project']);

        $this->assertSame('dev:graph:project', $log[0]['command']);
    }

    public function test_module_subject_forwards_name_option(): void
    {
        $log = new \ArrayObject();
        $tester = $this->newAskWithFakes($log);
        $tester->execute(['subject' => 'module', '--name' => 'Billing', '--json' => true]);

        $this->assertSame('dev:graph:module', $log[0]['command']);
        $this->assertSame('Billing', $log[0]['options']['name']);
        $this->assertTrue($log[0]['options']['json']);
    }

    public function test_route_subject_forwards_path_and_method(): void
    {
        $log = new \ArrayObject();
        $tester = $this->newAskWithFakes($log);
        $tester->execute([
            'subject'  => 'route',
            '--path'   => '/billing/invoices/{id}',
            '--method' => 'POST',
            '--json'   => true,
        ]);

        $this->assertSame('dev:graph:route', $log[0]['command']);
        $this->assertSame('/billing/invoices/{id}', $log[0]['options']['path']);
        $this->assertSame('POST', $log[0]['options']['method']);
    }

    public function test_event_subject_forwards_name(): void
    {
        $log = new \ArrayObject();
        $tester = $this->newAskWithFakes($log);
        $tester->execute(['subject' => 'event', '--name' => 'InvoicePaid']);

        $this->assertSame('dev:graph:event', $log[0]['command']);
        $this->assertSame('InvoicePaid', $log[0]['options']['name']);
    }

    public function test_logs_subject_forwards_file_lines_grep_level_since_around_context_list(): void
    {
        $log = new \ArrayObject();
        $tester = $this->newAskWithFakes($log);
        $tester->execute([
            'subject'   => 'logs',
            '--file'    => 'app',
            '--lines'   => '200',
            '--grep'    => 'fatal',
            '--level'   => 'ERROR',
            '--since'   => '-1h',
        ]);

        $got = $log[0];
        $this->assertSame('logs:app', $got['command']);
        $this->assertSame('app', $got['options']['file']);
        $this->assertSame('200', $got['options']['lines']);
        $this->assertSame('fatal', $got['options']['grep']);
        $this->assertSame('ERROR', $got['options']['level']);
        $this->assertSame('-1h', $got['options']['since']);
    }

    public function test_options_not_declared_by_target_are_silently_dropped(): void
    {
        $log = new \ArrayObject();
        $tester = $this->newAskWithFakes($log);
        // --path is logs-irrelevant; fake describe:* takes --json; subject=capabilities target only takes --json.
        $tester->execute([
            'subject' => 'capabilities',
            '--path'  => '/ignored',
            '--json'  => true,
        ]);

        $this->assertArrayNotHasKey('path', $log[0]['options']);
        $this->assertTrue($log[0]['options']['json']);
    }

    public function test_path_subject_delegates_to_dev_graph_path(): void
    {
        $log = new \ArrayObject();
        $tester = $this->newAskWithFakes($log);
        $tester->execute([
            'subject' => 'path',
            '--path'  => 'packages/semitexa-orm/src/Adapter',
            '--json'  => true,
        ]);

        $this->assertSame('dev:graph:path', $log[0]['command']);
        $this->assertSame('packages/semitexa-orm/src/Adapter', $log[0]['options']['path']);
        $this->assertTrue($log[0]['options']['json']);
    }

    public function test_subject_auto_defaults_to_path_when_only_path_provided(): void
    {
        $log = new \ArrayObject();
        $tester = $this->newAskWithFakes($log);
        $tester->execute([
            // subject deliberately omitted; --path alone must auto-select 'path'
            '--path' => 'packages/semitexa-orm/src/OrmManager.php',
            '--json' => true,
        ]);

        $this->assertSame('dev:graph:path', $log[0]['command'], '--path without subject must route to dev:graph:path');
        $this->assertSame('packages/semitexa-orm/src/OrmManager.php', $log[0]['options']['path']);
    }

    public function test_missing_subject_and_no_path_errors_clearly(): void
    {
        $app = new Application();
        $app->add(new AiAskCommand());

        $tester = new CommandTester($app->find('ai:ask'));
        $exit = $tester->execute([]); // no subject, no --path

        $this->assertSame(1, $exit);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame('error', $decoded['kind']);
        $this->assertStringContainsString('missing subject', $decoded['error']);
        $this->assertContains('path', $decoded['subjects']);
    }

    public function test_target_command_exit_code_is_propagated(): void
    {
        $app = new Application();
        $app->add(new AiAskCommand());
        $app->add(new class('dev:graph:project') extends Command {
            public function __construct(string $name) { parent::__construct($name); }
            protected function configure(): void {
                $this->addOption('json', null, InputOption::VALUE_NONE);
            }
            protected function execute(InputInterface $input, OutputInterface $output): int {
                return 7;
            }
        });

        $tester = new CommandTester($app->find('ai:ask'));
        $exit = $tester->execute(['subject' => 'project']);
        $this->assertSame(7, $exit);
    }

    private function newAskWithFakes(\ArrayObject $log): CommandTester
    {
        $app = new Application();
        $app->add(new AiAskCommand());

        foreach (self::targetOptionMap() as $name => $opts) {
            $app->add($this->fakeTarget($name, $opts, $log));
        }

        return new CommandTester($app->find('ai:ask'));
    }

    /**
     * @return array<string, array<string, int>> name → option → Symfony mode constant
     */
    private static function targetOptionMap(): array
    {
        return [
            'dev:graph:capabilities' => ['json' => InputOption::VALUE_NONE],
            'dev:graph:project' => ['json' => InputOption::VALUE_NONE],
            'dev:graph:module' => [
                'name' => InputOption::VALUE_REQUIRED,
                'json' => InputOption::VALUE_NONE,
            ],
            'dev:graph:route' => [
                'path'   => InputOption::VALUE_REQUIRED,
                'method' => InputOption::VALUE_OPTIONAL,
                'json'   => InputOption::VALUE_NONE,
            ],
            'dev:graph:event' => [
                'name' => InputOption::VALUE_OPTIONAL,
                'json' => InputOption::VALUE_NONE,
            ],
            'logs:app' => [
                'file'    => InputOption::VALUE_REQUIRED,
                'lines'   => InputOption::VALUE_REQUIRED,
                'grep'    => InputOption::VALUE_REQUIRED,
                'level'   => InputOption::VALUE_REQUIRED,
                'since'   => InputOption::VALUE_REQUIRED,
                'around'  => InputOption::VALUE_REQUIRED,
                'context' => InputOption::VALUE_REQUIRED,
                'list'    => InputOption::VALUE_NONE,
                'json'    => InputOption::VALUE_NONE,
            ],
            'dev:graph:path' => [
                'path' => InputOption::VALUE_REQUIRED,
                'json' => InputOption::VALUE_NONE,
            ],
        ];
    }

    /**
     * @param array<string, int> $options
     */
    private function fakeTarget(string $name, array $options, \ArrayObject $log): Command
    {
        return new class($name, $options, $log) extends Command {
            /**
             * @param array<string, int> $options
             */
            public function __construct(string $name, private array $options, private \ArrayObject $log) {
                parent::__construct($name);
            }
            protected function configure(): void {
                foreach ($this->options as $opt => $mode) {
                    $this->addOption($opt, null, $mode);
                }
            }
            protected function execute(InputInterface $input, OutputInterface $output): int {
                $captured = [];
                foreach ($this->options as $opt => $_mode) {
                    $captured[$opt] = $input->getOption($opt);
                }
                $this->log->append(['command' => $this->getName(), 'options' => $captured]);
                return self::SUCCESS;
            }
        };
    }
}

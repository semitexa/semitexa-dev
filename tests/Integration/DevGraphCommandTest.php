<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Console\Command\DevGraph\DevGraphEventCommand;
use Semitexa\Dev\Console\Command\DevGraph\DevGraphMineConventionsCommand;
use Semitexa\Dev\Console\Command\DevGraph\DevGraphModuleCommand;
use Semitexa\Dev\Console\Command\DevGraph\DevGraphProjectCommand;
use Semitexa\Dev\Console\Command\DevGraph\DevGraphRouteCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The dev:graph:* family is a 1:1 rename of the legacy describe:* /
 * ai:mine-conventions commands. Same options in, same exit code out, same
 * output (because the underlying command produces it verbatim).
 *
 * We stub the target commands so we can prove the forward without needing
 * full AttributeDiscovery / ModuleRegistry state.
 */
class DevGraphCommandTest extends TestCase
{
    public function test_dev_graph_project_delegates_to_describe_project(): void
    {
        $log = new \ArrayObject();
        $app = $this->applicationWithFakes($log, [
            new DevGraphProjectCommand(),
        ], [
            'describe:project' => ['json' => InputOption::VALUE_NONE],
        ]);

        $tester = new CommandTester($app->find('dev:graph:project'));
        $exit = $tester->execute(['--json' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame('describe:project', $log[0]['command']);
        $this->assertTrue($log[0]['options']['json']);
    }

    public function test_dev_graph_module_forwards_name(): void
    {
        $log = new \ArrayObject();
        $app = $this->applicationWithFakes($log, [
            new DevGraphModuleCommand(),
        ], [
            'describe:module' => ['name' => InputOption::VALUE_REQUIRED, 'json' => InputOption::VALUE_NONE],
        ]);

        $tester = new CommandTester($app->find('dev:graph:module'));
        $tester->execute(['--name' => 'Billing', '--json' => true]);

        $this->assertSame('describe:module', $log[0]['command']);
        $this->assertSame('Billing', $log[0]['options']['name']);
    }

    public function test_dev_graph_route_forwards_path_and_method(): void
    {
        $log = new \ArrayObject();
        $app = $this->applicationWithFakes($log, [
            new DevGraphRouteCommand(),
        ], [
            'describe:route' => [
                'path'   => InputOption::VALUE_REQUIRED,
                'method' => InputOption::VALUE_OPTIONAL,
                'json'   => InputOption::VALUE_NONE,
            ],
        ]);

        $tester = new CommandTester($app->find('dev:graph:route'));
        $tester->execute(['--path' => '/a/b', '--method' => 'PATCH']);

        $this->assertSame('describe:route', $log[0]['command']);
        $this->assertSame('/a/b', $log[0]['options']['path']);
        $this->assertSame('PATCH', $log[0]['options']['method']);
    }

    public function test_dev_graph_event_forwards_name(): void
    {
        $log = new \ArrayObject();
        $app = $this->applicationWithFakes($log, [
            new DevGraphEventCommand(),
        ], [
            'describe:event' => [
                'name' => InputOption::VALUE_OPTIONAL,
                'json' => InputOption::VALUE_NONE,
            ],
        ]);

        $tester = new CommandTester($app->find('dev:graph:event'));
        $tester->execute(['--name' => 'Foo']);

        $this->assertSame('describe:event', $log[0]['command']);
        $this->assertSame('Foo', $log[0]['options']['name']);
    }

    public function test_dev_graph_mine_conventions_forwards_dry_run_and_module(): void
    {
        $log = new \ArrayObject();
        $app = $this->applicationWithFakes($log, [
            new DevGraphMineConventionsCommand(),
        ], [
            'ai:mine-conventions' => [
                'dry-run' => InputOption::VALUE_NONE,
                'module'  => InputOption::VALUE_OPTIONAL,
            ],
        ]);

        $tester = new CommandTester($app->find('dev:graph:mine-conventions'));
        $tester->execute(['--dry-run' => true, '--module' => 'Billing']);

        $this->assertSame('ai:mine-conventions', $log[0]['command']);
        $this->assertTrue($log[0]['options']['dry-run']);
        $this->assertSame('Billing', $log[0]['options']['module']);
    }

    public function test_wrapper_propagates_target_exit_code(): void
    {
        $app = new Application();
        $app->add(new DevGraphProjectCommand());
        $app->add(new class('describe:project') extends Command {
            public function __construct(string $name) { parent::__construct($name); }
            protected function configure(): void {
                $this->addOption('json', null, InputOption::VALUE_NONE);
            }
            protected function execute(InputInterface $input, OutputInterface $output): int {
                return 3;
            }
        });

        $tester = new CommandTester($app->find('dev:graph:project'));
        $exit = $tester->execute([]);
        $this->assertSame(3, $exit);
    }

    /**
     * @param list<Command>                        $wrappers
     * @param array<string, array<string, int>>    $targets
     */
    private function applicationWithFakes(\ArrayObject $log, array $wrappers, array $targets): Application
    {
        $app = new Application();
        foreach ($wrappers as $w) {
            $app->add($w);
        }
        foreach ($targets as $name => $options) {
            $app->add($this->fakeTarget($name, $options, $log));
        }
        return $app;
    }

    /**
     * @param array<string, int> $options
     */
    private function fakeTarget(string $name, array $options, \ArrayObject $log): Command
    {
        return new class($name, $options, $log) extends Command {
            /** @param array<string, int> $options */
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

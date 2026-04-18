<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Console\Command\MakeCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Verifies the recipe-driven `make` dispatcher forwards shared args to every
 * step in the chain, dispatches in order, and emits a structured summary.
 *
 * We register fake stand-ins for `make:payload`, `make:handler`, `make:resource`
 * (the chain for `add_authenticated_json_route`) so the test doesn't depend on
 * project state.
 */
class MakeDispatcherTest extends TestCase
{
    public function test_runs_recipe_chain_in_order_with_shared_args(): void
    {
        $invocations = [];
        $app = new Application();
        $app->add(new MakeCommand());
        foreach (['make:payload', 'make:handler', 'make:resource'] as $name) {
            $app->add(new class($name, $invocations) extends Command {
                public function __construct(string $name, private array &$log) {
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
                    $this->log[] = [
                        'command' => $this->getName(),
                        'module'  => $input->getOption('module'),
                        'name'    => $input->getOption('name'),
                        'write'   => (bool) $input->getOption('write'),
                    ];
                    $output->writeln(json_encode([
                        'artifact' => 'semitexa-dev.generation-result/v1',
                        'result'   => ['command' => $this->getName(), 'status' => 'dry_run'],
                    ]));
                    return self::SUCCESS;
                }
            });
        }

        $tester = new CommandTester($app->find('make'));
        $exit = $tester->execute([
            '--recipe' => 'add_authenticated_json_route',
            '--arg'    => ['module=billing', 'name=get-invoice'],
            '--json'   => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame('semitexa-dev.make-dispatch/v1', $decoded['artifact']);
        $this->assertSame('add_authenticated_json_route', $decoded['recipe']);
        $this->assertSame('ok', $decoded['status']);
        $this->assertCount(3, $decoded['steps']);

        $commandsInOrder = array_column($decoded['steps'], 'command');
        $this->assertSame(['make:payload', 'make:handler', 'make:resource'], $commandsInOrder);
    }

    public function test_rejects_unknown_recipe(): void
    {
        $app = new Application();
        $app->add(new MakeCommand());
        $tester = new CommandTester($app->find('make'));
        $exit = $tester->execute(['--recipe' => 'does_not_exist']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown recipe', $tester->getDisplay());
    }

    public function test_rejects_recipe_with_empty_generator_chain(): void
    {
        $app = new Application();
        $app->add(new MakeCommand());
        $tester = new CommandTester($app->find('make'));
        $exit = $tester->execute(['--recipe' => 'rename_symbol']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no generator_chain', $tester->getDisplay());
    }

    public function test_stops_chain_on_first_non_zero_exit(): void
    {
        $invocations = 0;
        $app = new Application();
        $app->add(new MakeCommand());
        $app->add(new class('make:payload', $invocations) extends Command {
            public function __construct(string $name, private int &$count) {
                parent::__construct($name);
            }
            protected function configure(): void {
                $this->addOption('json', null, InputOption::VALUE_NONE);
            }
            protected function execute(InputInterface $input, OutputInterface $output): int {
                $this->count++;
                $output->writeln('{"artifact":"x"}');
                return 1;
            }
        });
        // Handler/resource would be next — should not run.
        $app->add(new class('make:handler', $invocations) extends Command {
            public function __construct(string $name, private int &$count) {
                parent::__construct($name);
            }
            protected function configure(): void {
                $this->addOption('json', null, InputOption::VALUE_NONE);
            }
            protected function execute(InputInterface $input, OutputInterface $output): int {
                $this->count++;
                return 0;
            }
        });
        $app->add(new class('make:resource') extends Command {
            protected function configure(): void {
                $this->setName('make:resource');
            }
            protected function execute(InputInterface $input, OutputInterface $output): int {
                return 0;
            }
        });

        $tester = new CommandTester($app->find('make'));
        $exit = $tester->execute([
            '--recipe' => 'add_authenticated_json_route',
            '--json'   => true,
        ]);

        $this->assertSame(1, $exit);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame('failed', $decoded['status']);
        $this->assertCount(1, $decoded['steps']);
        $this->assertSame('make:payload', $decoded['steps'][0]['command']);
    }
}

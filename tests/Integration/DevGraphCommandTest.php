<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Console\Command\DevGraph\DevGraphCapabilitiesCommand;
use Semitexa\Dev\Application\Console\Command\DevGraph\DevGraphEventCommand;
use Semitexa\Dev\Application\Console\Command\DevGraph\DevGraphMineConventionsCommand;
use Semitexa\Dev\Application\Console\Command\DevGraph\DevGraphModuleCommand;
use Semitexa\Dev\Application\Console\Command\DevGraph\DevGraphProjectCommand;
use Semitexa\Dev\Application\Console\Command\DevGraph\DevGraphRouteCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The dev:graph:* family are first-class introspection commands (no longer
 * delegators). Behaviors are exercised by the `ai:ask` dispatcher tests and
 * end-to-end suites that wire up full AttributeDiscovery / ModuleRegistry.
 * Here we verify registration, names, and declared options — the wiring
 * contract `ai:ask` depends on.
 */
class DevGraphCommandTest extends TestCase
{
    public function test_dev_graph_capabilities_is_registered_with_json_option(): void
    {
        $app = new Application();
        $app->add(new DevGraphCapabilitiesCommand());

        $cmd = $app->find('dev:graph:capabilities');
        $this->assertSame('dev:graph:capabilities', $cmd->getName());
        $this->assertTrue($cmd->getDefinition()->hasOption('json'));
    }

    public function test_dev_graph_project_is_registered_with_json_option(): void
    {
        $app = new Application();
        $app->add(new DevGraphProjectCommand());

        $cmd = $app->find('dev:graph:project');
        $this->assertSame('dev:graph:project', $cmd->getName());
        $this->assertTrue($cmd->getDefinition()->hasOption('json'));
    }

    public function test_dev_graph_module_is_registered_with_name_and_json(): void
    {
        $app = new Application();
        $app->add(new DevGraphModuleCommand());

        $cmd = $app->find('dev:graph:module');
        $this->assertSame('dev:graph:module', $cmd->getName());
        $this->assertTrue($cmd->getDefinition()->hasOption('name'));
        $this->assertTrue($cmd->getDefinition()->hasOption('json'));
    }

    public function test_dev_graph_route_is_registered_with_path_method_json(): void
    {
        $app = new Application();
        $app->add(new DevGraphRouteCommand());

        $cmd = $app->find('dev:graph:route');
        $this->assertSame('dev:graph:route', $cmd->getName());
        $this->assertTrue($cmd->getDefinition()->hasOption('path'));
        $this->assertTrue($cmd->getDefinition()->hasOption('method'));
        $this->assertTrue($cmd->getDefinition()->hasOption('json'));
    }

    public function test_dev_graph_event_is_registered_with_name_and_json(): void
    {
        $app = new Application();
        $app->add(new DevGraphEventCommand());

        $cmd = $app->find('dev:graph:event');
        $this->assertSame('dev:graph:event', $cmd->getName());
        $this->assertTrue($cmd->getDefinition()->hasOption('name'));
        $this->assertTrue($cmd->getDefinition()->hasOption('json'));
    }

    public function test_dev_graph_mine_conventions_is_registered_with_dry_run_and_module(): void
    {
        $app = new Application();
        $app->add(new DevGraphMineConventionsCommand());

        $cmd = $app->find('dev:graph:mine-conventions');
        $this->assertSame('dev:graph:mine-conventions', $cmd->getName());
        $this->assertTrue($cmd->getDefinition()->hasOption('dry-run'));
        $this->assertTrue($cmd->getDefinition()->hasOption('module'));
    }

    public function test_dev_graph_capabilities_emits_json_manifest(): void
    {
        $app = new Application();
        $app->add(new DevGraphCapabilitiesCommand());

        $tester = new CommandTester($app->find('dev:graph:capabilities'));
        $exit = $tester->execute(['--json' => true]);

        $this->assertSame(0, $exit);
        $data = json_decode(trim($tester->getDisplay()), true);
        $this->assertIsArray($data);
        $this->assertSame('semitexa.ai-capabilities/v1', $data['artifact']);
        $this->assertIsArray($data['commands']);
        $this->assertNotEmpty($data['commands']);
    }
}

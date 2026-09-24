<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\MakeEventListenerCommand;
use Semitexa\Dev\Application\Console\Command\MakePageCommand;
use Semitexa\Dev\Application\Console\Command\MakeServiceCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What make:* refuses, and that it refuses in the format the caller asked for.
 *
 * A typo in --module used to plan cleanly, recommend --write, and then start a
 * second module, because a directory under src/modules/ is all discovery needs.
 * And every refusal was a Symfony error block even under --json, leaving an
 * agent that parses stdout with nothing to parse.
 */
final class GenerationPreflightTest extends TestCase
{
    private string $tmpRoot;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/semitexa-dev-preflight-' . uniqid();
        mkdir($this->tmpRoot . '/src/modules/Playground', 0755, true);
        mkdir($this->tmpRoot . '/src/modules/Billing', 0755, true);
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

    public function test_a_misspelt_module_is_refused_with_the_likely_one_named(): void
    {
        $tester = new CommandTester(new MakePageCommand());
        $exit = $tester->execute([
            '--module' => 'Playgound', '--name' => 'Report', '--path' => '/report', '--method' => 'GET', '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertIsArray($decoded, 'a refusal under --json must still be JSON');
        $this->assertSame('semitexa-dev.generation-result/v1', $decoded['artifact']);
        $this->assertSame('rejected', $decoded['result']['status']);
        $this->assertSame([], $decoded['result']['created'], 'nothing may be planned into a module that does not exist');
        $this->assertSame('unknown_module', $decoded['result']['errors'][0]['reason']);
        $this->assertStringContainsString("Did you mean 'Playground'?", $decoded['result']['errors'][0]['detail']);
        $this->assertSame('make:module', $decoded['next_command'][0]['cmd']);
        $this->assertStringContainsString('--module=Playground', $decoded['next_command'][0]['why']);
        $this->assertDirectoryDoesNotExist($this->tmpRoot . '/src/modules/Playgound');
    }

    public function test_an_existing_module_in_any_case_still_plans(): void
    {
        $tester = new CommandTester(new MakeServiceCommand());
        $exit = $tester->execute(['--module' => 'billing', '--name' => 'InvoiceTotals', '--json' => true]);

        $this->assertSame(0, $exit, $tester->getDisplay());
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame('dry_run', $decoded['result']['status']);
    }

    public function test_a_missing_option_under_json_is_a_json_refusal(): void
    {
        $tester = new CommandTester(new MakeServiceCommand());
        $exit = $tester->execute(['--module' => 'Billing', '--json' => true]);

        $this->assertSame(1, $exit);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertIsArray($decoded);
        $this->assertSame('missing_option', $decoded['result']['errors'][0]['reason']);
        $this->assertStringContainsString('--name', $decoded['result']['errors'][0]['detail']);
    }

    public function test_an_invalid_value_under_json_is_a_json_refusal(): void
    {
        $tester = new CommandTester(new MakeEventListenerCommand());
        $exit = $tester->execute([
            '--module' => 'Billing', '--name' => 'X', '--event' => 'App\\Evt', '--execution' => 'Later', '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame('invalid_option', $decoded['result']['errors'][0]['reason']);
    }

    public function test_without_json_the_refusal_is_for_a_human(): void
    {
        $tester = new CommandTester(new MakeServiceCommand());
        $exit = $tester->execute(['--module' => 'Nowhere', '--name' => 'X']);

        $this->assertSame(1, $exit);
        $this->assertNull(json_decode(trim($tester->getDisplay()), true));
        $this->assertStringContainsString('make:module --name=Nowhere', $tester->getDisplay());
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

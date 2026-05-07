<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\MakeHandlerCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end sanity check that make:handler's duplicate gate refuses and
 * emits the correct JSON envelope when a handler with the same class already
 * exists, and that --override-duplicate lets the command proceed.
 *
 * Stays in dry-run mode so nothing is written to disk.
 */
class DuplicateRefusalTest extends TestCase
{
    private string $tmpRoot;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/semitexa-dup-refusal-' . uniqid();
        mkdir($this->tmpRoot . '/src/modules/Foo/src/Application/Handler/PayloadHandler', 0755, true);
        file_put_contents($this->tmpRoot . '/composer.json', '{"name":"temp/project"}');

        file_put_contents(
            $this->tmpRoot . '/src/modules/Foo/src/Application/Handler/PayloadHandler/GetThingHandler.php',
            <<<PHP
<?php
namespace Semitexa\\Modules\\Foo\\Application\\Handler\\PayloadHandler;
use Semitexa\\Core\\Attribute\\AsPayloadHandler;
#[AsPayloadHandler(payload: GetThingPayload::class, resource: GetThingResponse::class)]
final class GetThingHandler {}
PHP,
        );

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

    public function test_refuses_with_json_envelope_when_handler_already_exists(): void
    {
        $tester = new CommandTester(new MakeHandlerCommand());
        $exit = $tester->execute([
            '--module'   => 'foo',
            '--name'     => 'get-thing',
            '--payload'  => 'get-thing',
            '--resource' => 'get-thing',
            '--dry-run'  => true,
            '--json'     => true,
        ]);

        $this->assertSame(1, $exit);

        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertIsArray($decoded, 'expected JSON envelope on stdout');
        $this->assertSame('semitexa-dev.duplicate-refusal/v1', $decoded['artifact']);
        $this->assertSame('refused', $decoded['status']);
        $this->assertSame('handler', $decoded['kind']);
        $this->assertSame('Foo', $decoded['module']);
        $this->assertSame('GetThingHandler', $decoded['proposed']['className']);
        $this->assertNotEmpty($decoded['findings']);
        $this->assertSame('handler.same_class_in_module', $decoded['findings'][0]['rule']);
        $this->assertStringContainsString('GetThingHandler.php', $decoded['findings'][0]['prior_art_path']);
    }

    public function test_override_bypasses_refusal_and_returns_plan(): void
    {
        $tester = new CommandTester(new MakeHandlerCommand());
        $exit = $tester->execute([
            '--module'             => 'foo',
            '--name'               => 'get-thing',
            '--payload'            => 'get-thing',
            '--resource'           => 'get-thing',
            '--dry-run'            => true,
            '--json'               => true,
            '--override-duplicate' => true,
        ]);

        $this->assertSame(0, $exit);

        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertIsArray($decoded);
        $this->assertSame('semitexa-dev.generation-result/v1', $decoded['artifact']);
        $this->assertSame('dry_run', $decoded['result']['status']);
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

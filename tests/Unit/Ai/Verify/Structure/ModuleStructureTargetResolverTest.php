<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Structure;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Ai\Verify\ChangedFile;
use Semitexa\Dev\Ai\Verify\Structure\DetectedModule;
use Semitexa\Dev\Ai\Verify\Structure\ModuleStructureTargetResolver;

class ModuleStructureTargetResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-resolver-' . uniqid();
        mkdir($this->root . '/packages/semitexa-api', 0755, true);
        file_put_contents($this->root . '/packages/semitexa-api/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function test_resolves_application_module_root_from_a_changed_file(): void
    {
        // ACCEPTANCE 7: a single file resolves to the full owning module.
        $resolver = new ModuleStructureTargetResolver($this->root);
        $modules = $resolver->resolve([
            new ChangedFile(
                'src/modules/Hello/Application/Console/Command/SyncCommand.php',
                ChangedFile::KIND_PHP_OTHER,
            ),
        ]);

        $this->assertCount(1, $modules);
        $this->assertSame('Hello', $modules[0]->name);
        $this->assertSame('src/modules/Hello', $modules[0]->relativePath);
        $this->assertSame(DetectedModule::KIND_APPLICATION, $modules[0]->kind);
    }

    public function test_resolves_package_module_root_from_a_changed_file(): void
    {
        $resolver = new ModuleStructureTargetResolver($this->root);
        $modules = $resolver->resolve([
            new ChangedFile(
                'packages/semitexa-api/src/Application/Console/Command/DumpOpenApiCommand.php',
                ChangedFile::KIND_PHP_OTHER,
            ),
        ]);

        $this->assertCount(1, $modules);
        $this->assertSame('api', $modules[0]->name);
        $this->assertSame('packages/semitexa-api', $modules[0]->relativePath);
        $this->assertSame(DetectedModule::KIND_PACKAGE, $modules[0]->kind);
    }

    public function test_files_from_two_modules_resolve_to_two_modules(): void
    {
        mkdir($this->root . '/packages/semitexa-other', 0755, true);
        file_put_contents($this->root . '/packages/semitexa-other/composer.json', '{}');
        $resolver = new ModuleStructureTargetResolver($this->root);
        $modules = $resolver->resolve([
            new ChangedFile('packages/semitexa-api/src/X.php', ChangedFile::KIND_PHP_OTHER),
            new ChangedFile('packages/semitexa-other/src/Y.php', ChangedFile::KIND_PHP_OTHER),
            new ChangedFile('src/modules/Hello/X.php', ChangedFile::KIND_PHP_OTHER),
        ]);

        $rels = array_map(static fn(DetectedModule $m) => $m->relativePath, $modules);
        $this->assertSame(
            ['packages/semitexa-api', 'packages/semitexa-other', 'src/modules/Hello'],
            $rels,
            'modules returned in stable lexicographic order',
        );
    }

    public function test_packages_without_composer_json_are_not_resolved(): void
    {
        $resolver = new ModuleStructureTargetResolver($this->root);
        $modules = $resolver->resolve([
            new ChangedFile('packages/semitexa-no-composer/src/X.php', ChangedFile::KIND_PHP_OTHER),
        ]);
        $this->assertSame([], $modules);
    }

    public function test_files_outside_module_shapes_are_ignored(): void
    {
        $resolver = new ModuleStructureTargetResolver($this->root);
        $modules = $resolver->resolve([
            new ChangedFile('var/cache/x.json', ChangedFile::KIND_NON_PHP),
            new ChangedFile('docker/etc/nginx.conf', ChangedFile::KIND_NON_PHP),
            new ChangedFile('composer.json', ChangedFile::KIND_NON_PHP),
        ]);
        $this->assertSame([], $modules);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Structure;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\FilePlacementRule;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureRule;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureSpec;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureSpecLoader;

class ModuleStructureSpecLoaderTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 7);
    }

    public function test_loads_real_executable_spec(): void
    {
        $spec = (new ModuleStructureSpecLoader($this->projectRoot))->load();

        // Top-level layers are explicit and finite. Application modules use
        // the canonical core (plus the global `Exception/` leaf for package-
        // wide exception classes); packages additionally declare named
        // framework layers (Attribute, Auth, Discovery, OpenApi, Pipeline)
        // — each gated to packages-only via `packageOnlyDirectories`.
        $top = $spec->ruleFor(ModuleStructureSpec::TOP_LEVEL_KEY);
        $this->assertNotNull($top);
        $this->assertSame(
            [
                'Application', 'Domain', 'Context', 'Configuration', 'Update', 'Static', 'View',
                'Exception',
                'Attribute', 'Auth', 'Discovery', 'OpenApi', 'Pipeline',
            ],
            $top->allowedDirectories,
        );
        $this->assertSame(
            ['Attribute', 'Auth', 'Discovery', 'OpenApi', 'Pipeline'],
            $spec->packageOnlyDirectories,
        );
        $this->assertContains('Demo', $spec->forbiddenInProductionPackages);
        $this->assertContains('Sandbox', $spec->forbiddenInProductionPackages);
        $this->assertContains('Playground', $spec->forbiddenInProductionPackages);

        // Application/Console only allows Command — the user's contract.
        $console = $spec->ruleFor('Application/Console');
        $this->assertNotNull($console);
        $this->assertSame(['Command'], $console->allowedDirectories);

        // Application/Console/Command is a feature-grouping leaf with a
        // strict file-name pattern (only *Command.php basenames).
        $command = $spec->ruleFor('Application/Console/Command');
        $this->assertNotNull($command);
        $this->assertTrue($command->allowFeatureGrouping);
        $this->assertFalse($command->allowAnyFile, 'Phase 2: explicit pattern, no allowAnyFile catch-all');
        $this->assertNotEmpty($command->allowedFilePatterns);

        // Application/Db is the canonical persistence implementation layer.
        $appRoot = $spec->ruleFor('Application');
        $this->assertNotNull($appRoot);
        $this->assertContains('Db', $appRoot->allowedDirectories);

        // Application/Db's adapter list comes from the canonical
        // $persistenceAdapters in the spec — currently MySQL + SQLite, the
        // two adapters semitexa-orm officially ships (MysqlAdapter,
        // SqliteAdapter) and accepts in OrmManager's driver guard.
        $db = $spec->ruleFor('Application/Db');
        $this->assertNotNull($db, 'Application/Db must be a declared layer');
        $this->assertSame(
            ['MySQL', 'SQLite'],
            $db->allowedDirectories,
            'Storage adapter list must be the official semitexa-orm adapter set',
        );

        // Per-adapter shape: each adapter must declare exactly the three
        // peer sub-trees Model + Mapper + Repository. Mapper/ is a peer of
        // Model/, not a child of it — *Mapper.php belongs there.
        foreach (['MySQL', 'SQLite'] as $adapter) {
            $adapterRule = $spec->ruleFor('Application/Db/' . $adapter);
            $this->assertNotNull($adapterRule, $adapter . ' rule must exist');
            $this->assertSame(
                ['Model', 'Mapper', 'Repository'],
                $adapterRule->allowedDirectories,
                $adapter . ' must allow only Model + Mapper + Repository',
            );

            $modelRule = $spec->ruleFor('Application/Db/' . $adapter . '/Model');
            $this->assertNotNull($modelRule);
            $this->assertFalse($modelRule->allowAnyFile, "{$adapter}/Model must NOT permit arbitrary files");
            $this->assertNotEmpty($modelRule->allowedFilePatterns, "{$adapter}/Model must declare file patterns");

            $mapperRule = $spec->ruleFor('Application/Db/' . $adapter . '/Mapper');
            $this->assertNotNull($mapperRule, "{$adapter}/Mapper must be declared as a peer of Model/");
            $this->assertFalse($mapperRule->allowAnyFile, "{$adapter}/Mapper must NOT permit arbitrary files");
            $this->assertNotEmpty($mapperRule->allowedFilePatterns, "{$adapter}/Mapper must declare file patterns");

            $repoRule = $spec->ruleFor('Application/Db/' . $adapter . '/Repository');
            $this->assertNotNull($repoRule);
            $this->assertFalse($repoRule->allowAnyFile, "{$adapter}/Repository must NOT permit arbitrary files");
            $this->assertNotEmpty($repoRule->allowedFilePatterns, "{$adapter}/Repository must declare file patterns");
        }

        // Per-package code-root allowlist: semitexa-core gets explicit
        // additional directories (Container, Composer, Lifecycle, …) and
        // entry-point class files at its source root. The list is narrow
        // and named — never a wildcard.
        $coreDirs = $spec->packageSpecificDirectories('core');
        $this->assertNotEmpty($coreDirs);
        foreach (['Container', 'Composer', 'Lifecycle', 'Console', 'PHPStan'] as $expected) {
            $this->assertContains($expected, $coreDirs, "core package must declare '{$expected}' as core-only");
        }
        $this->assertSame([], $spec->packageSpecificDirectories('api'), 'no other package gets per-package extras');

        $coreFiles = $spec->packageSpecificFiles('core');
        $this->assertContains('Application.php',    $coreFiles);
        $this->assertContains('Environment.php',    $coreFiles);
        $this->assertContains('ModuleRegistry.php', $coreFiles);
    }

    public function test_command_file_placement_rule_points_at_application_console_command(): void
    {
        $spec = (new ModuleStructureSpecLoader($this->projectRoot))->load();
        $this->assertArrayHasKey('command', $spec->filePlacement);
        $this->assertInstanceOf(FilePlacementRule::class, $spec->filePlacement['command']);
        $this->assertSame('Application/Console/Command', $spec->filePlacement['command']->requiredPath);
        $this->assertSame('module_structure.command_wrong_location', $spec->filePlacement['command']->code);
    }

    public function test_caches_per_mtime_and_re_reads_on_change(): void
    {
        $tmp = sys_get_temp_dir() . '/spec-loader-' . uniqid() . '.php';
        file_put_contents($tmp, $this->buildSpecPhp(['Application']));
        $loader = new ModuleStructureSpecLoader($this->projectRoot, $tmp);
        $first = $loader->load();
        $this->assertSame(['Application'], $first->ruleFor(ModuleStructureSpec::TOP_LEVEL_KEY)?->allowedDirectories);

        // Bump mtime forward (touch +5s) and rewrite.
        clearstatcache();
        file_put_contents($tmp, $this->buildSpecPhp(['Application', 'Domain']));
        touch($tmp, time() + 5);
        $second = $loader->load();
        $this->assertSame(
            ['Application', 'Domain'],
            $second->ruleFor(ModuleStructureSpec::TOP_LEVEL_KEY)?->allowedDirectories,
        );

        @unlink($tmp);
    }

    public function test_throws_when_spec_file_is_missing(): void
    {
        $loader = new ModuleStructureSpecLoader($this->projectRoot, '/does/not/exist/module-structure.php');
        $this->expectException(\RuntimeException::class);
        $loader->load();
    }

    /**
     * @param list<string> $topLevel
     */
    private function buildSpecPhp(array $topLevel): string
    {
        $list = "['" . implode("', '", $topLevel) . "']";
        return "<?php\nuse Semitexa\\Dev\\Application\\Service\\Ai\\Verify\\Structure\\FilePlacementRule;\n"
            . "use Semitexa\\Dev\\Application\\Service\\Ai\\Verify\\Structure\\ModuleStructureRule;\n"
            . "use Semitexa\\Dev\\Application\\Service\\Ai\\Verify\\Structure\\ModuleStructureSpec;\n"
            . "return new ModuleStructureSpec(\n"
            . "    codeRootRules: ['top_level' => new ModuleStructureRule(path: 'top_level', allowedDirectories: {$list})],\n"
            . "    packageRootRule: new ModuleStructureRule(path: 'package_root', allowedDirectories: ['src'], allowedFiles: ['composer.json']),\n"
            . "    filePlacement: ['command' => new FilePlacementRule(code: 'module_structure.command_wrong_location', pattern: '/Command\\.php\$/', requiredPath: 'Application/Console/Command', description: 'console command')],\n"
            . "    packageOnlyDirectories: [],\n"
            . "    requiredPackageRootEntries: ['composer.json', 'src'],\n"
            . ");\n";
    }
}

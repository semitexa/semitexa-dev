<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Structure;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\DetectedModule;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\LocalModuleStructureExtension;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureRule;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureSpec;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureSpecLoader;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureValidator;

/**
 * Local module-structure extension behavior:
 *
 *   - real `packages/semitexa-orm/config/module-structure.php` is detected
 *     and merged into the spec;
 *   - rules contributed by orm's local extension are scoped to orm only
 *     (do not leak to any other package);
 *   - `Adapter/`, `OrmManager.php`, `Trait/`, `Repository/`, `Query/`,
 *     `Metadata/` are allowed inside semitexa-orm but rejected everywhere
 *     else;
 *   - guard rails reject malformed / forbidden / overriding extensions
 *     loudly (RuntimeException with a precise diagnostic).
 */
final class LocalModuleStructureExtensionTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 7);
    }

    // ---------- Loader: discovery + merge ----------

    public function test_loader_discovers_orm_local_extension(): void
    {
        $spec = (new ModuleStructureSpecLoader($this->projectRoot))->load();

        $orm = $spec->localExtensionFor('orm');
        $this->assertInstanceOf(LocalModuleStructureExtension::class, $orm);
        $this->assertSame('orm', $orm->package);

        foreach (['Adapter', 'Trait', 'Repository', 'Query', 'Metadata'] as $dir) {
            $this->assertContains($dir, $orm->topLevelDirectories, "orm extension must declare {$dir}/");
            $this->assertArrayHasKey($dir, $orm->pathRules, "orm extension must define a rule for {$dir}/");
        }
        $this->assertContains('OrmManager.php', $orm->topLevelFiles);
        $this->assertSame('packages/semitexa-orm/docs/MODULE_STRUCTURE.md', $orm->docPath);
    }

    public function test_loader_merges_orm_local_directories_into_package_specific_code_root(): void
    {
        $spec = (new ModuleStructureSpecLoader($this->projectRoot))->load();
        $ormDirs = $spec->packageSpecificDirectories('orm');
        foreach (['Adapter', 'Trait', 'Repository', 'Query', 'Metadata'] as $dir) {
            $this->assertContains($dir, $ormDirs, "orm packageSpecificDirectories must include {$dir} after merge");
        }
        $this->assertContains('OrmManager.php', $spec->packageSpecificFiles('orm'));
    }

    public function test_orm_scoped_rules_do_not_leak_to_other_packages(): void
    {
        $spec = (new ModuleStructureSpecLoader($this->projectRoot))->load();

        // For orm: ruleForInPackage('orm', 'Adapter') resolves via local extension.
        $ormAdapterRule = $spec->ruleForInPackage('orm', 'Adapter');
        $this->assertNotNull($ormAdapterRule, 'orm should resolve Adapter via local extension');
        $this->assertSame('Adapter', $ormAdapterRule->path);

        // For api (no local extension that declares Adapter): same lookup must
        // NOT find a scoped rule, and the global $codeRootRules has no
        // 'Adapter' entry, so it falls back to null.
        $this->assertNull($spec->ruleForInPackage('api', 'Adapter'),
            'orm-scoped Adapter rule must NOT be visible to other packages');
        $this->assertNull($spec->ruleForInPackage(null, 'Adapter'),
            'top-level lookup with no package context must not find the scoped Adapter rule');

        // Existing behavior: ruleFor() (global-only) MUST NOT see scoped rules.
        $this->assertNull($spec->ruleFor('Adapter'),
            'global ruleFor() lookups must remain unaware of package-scoped rules');
    }

    public function test_isPathDeclaredInPackage_respects_scoping(): void
    {
        $spec = (new ModuleStructureSpecLoader($this->projectRoot))->load();
        $this->assertTrue($spec->isPathDeclaredInPackage('orm', 'Adapter'));
        $this->assertFalse($spec->isPathDeclaredInPackage('api', 'Adapter'));
        $this->assertFalse($spec->isPathDeclaredInPackage(null, 'Adapter'));
        // Globally-declared paths remain visible to any package.
        $this->assertTrue($spec->isPathDeclaredInPackage('orm', 'Application/Console/Command'));
        $this->assertTrue($spec->isPathDeclaredInPackage('api', 'Application/Console/Command'));
    }

    // ---------- Validator: positive cases inside orm ----------

    public function test_validator_accepts_orm_adapter_via_local_extension(): void
    {
        $violations = $this->runValidator('packages/semitexa-orm', 'package', 'orm');

        $codes = array_map(fn($v) => [$v->code, $v->actual], $violations);
        // Adapter/, Trait/, Repository/, Query/, Metadata/, OrmManager.php
        // must NOT appear as violations.
        foreach ([['Adapter/', 'unknown_directory'], ['Trait/', 'unknown_directory'], ['Repository/', 'unknown_directory'], ['Query/', 'unknown_directory'], ['Metadata/', 'unknown_directory']] as [$actual, $code]) {
            foreach ($codes as $entry) {
                if ($entry[1] === $actual) {
                    $this->fail("Adapter set: '{$actual}' should be allowed by orm local extension but produced violation: " . $entry[0]);
                }
            }
        }
        $ormManagerHits = array_filter($codes, fn($e) => $e[1] === 'OrmManager.php');
        $this->assertEmpty($ormManagerHits, 'OrmManager.php must be allowed by orm local extension');
    }

    // ---------- Validator: negative cases (other packages, application modules) ----------

    public function test_validator_rejects_adapter_in_non_orm_package(): void
    {
        $tmp = $this->makeFakePackage('semitexa-feature-x', static function (string $root): void {
            mkdir($root . '/src/Adapter', 0o777, true);
            file_put_contents($root . '/src/Adapter/FooAdapter.php', "<?php\nnamespace Semitexa\\FeatureX\\Adapter; class FooAdapter {}\n");
        });

        $violations = $this->runValidator($tmp['rel'], 'package', 'feature-x', $tmp['projectRoot']);
        $this->assertViolation($violations, 'unknown_directory', 'Adapter/');
    }

    public function test_validator_rejects_query_in_non_orm_package(): void
    {
        $tmp = $this->makeFakePackage('semitexa-feature-x', static function (string $root): void {
            mkdir($root . '/src/Query', 0o777, true);
            file_put_contents($root . '/src/Query/FooQuery.php', "<?php\nnamespace Semitexa\\FeatureX\\Query; class FooQuery {}\n");
        });
        $violations = $this->runValidator($tmp['rel'], 'package', 'feature-x', $tmp['projectRoot']);
        $this->assertViolation($violations, 'unknown_directory', 'Query/');
    }

    public function test_validator_rejects_repository_in_non_orm_package(): void
    {
        $tmp = $this->makeFakePackage('semitexa-feature-x', static function (string $root): void {
            mkdir($root . '/src/Repository', 0o777, true);
            file_put_contents($root . '/src/Repository/FooRepository.php', "<?php\nnamespace Semitexa\\FeatureX\\Repository; class FooRepository {}\n");
        });
        $violations = $this->runValidator($tmp['rel'], 'package', 'feature-x', $tmp['projectRoot']);
        $this->assertViolation($violations, 'unknown_directory', 'Repository/');
    }

    public function test_validator_rejects_metadata_in_non_orm_package(): void
    {
        $tmp = $this->makeFakePackage('semitexa-feature-x', static function (string $root): void {
            mkdir($root . '/src/Metadata', 0o777, true);
            file_put_contents($root . '/src/Metadata/FooMetadata.php', "<?php\nnamespace Semitexa\\FeatureX\\Metadata; class FooMetadata {}\n");
        });
        $violations = $this->runValidator($tmp['rel'], 'package', 'feature-x', $tmp['projectRoot']);
        $this->assertViolation($violations, 'unknown_directory', 'Metadata/');
    }

    public function test_validator_rejects_trait_in_non_orm_package(): void
    {
        $tmp = $this->makeFakePackage('semitexa-feature-x', static function (string $root): void {
            mkdir($root . '/src/Trait', 0o777, true);
            file_put_contents($root . '/src/Trait/FooTrait.php', "<?php\nnamespace Semitexa\\FeatureX\\Trait; trait FooTrait {}\n");
        });
        $violations = $this->runValidator($tmp['rel'], 'package', 'feature-x', $tmp['projectRoot']);
        $this->assertViolation($violations, 'unknown_directory', 'Trait/');
    }

    public function test_validator_rejects_orm_manager_root_file_in_non_orm_package(): void
    {
        $tmp = $this->makeFakePackage('semitexa-feature-x', static function (string $root): void {
            file_put_contents($root . '/src/OrmManager.php', "<?php\nnamespace Semitexa\\FeatureX; class OrmManager {}\n");
        });
        $violations = $this->runValidator($tmp['rel'], 'package', 'feature-x', $tmp['projectRoot']);
        $this->assertViolation($violations, 'invalid_root_file', 'OrmManager.php');
    }

    public function test_validator_rejects_adapter_in_application_module(): void
    {
        $tmp = $this->makeApplicationModule('Hello', static function (string $root): void {
            mkdir($root . '/Adapter', 0o777, true);
            file_put_contents($root . '/Adapter/FooAdapter.php', "<?php\nnamespace Semitexa\\Modules\\Hello\\Adapter; class FooAdapter {}\n");
        });
        $violations = $this->runValidator($tmp['rel'], 'application', 'Hello', $tmp['projectRoot']);
        $this->assertViolation($violations, 'unknown_directory', 'Adapter/');
    }

    public function test_validator_rejects_orm_manager_in_application_module(): void
    {
        $tmp = $this->makeApplicationModule('Hello', static function (string $root): void {
            file_put_contents($root . '/OrmManager.php', "<?php\nnamespace Semitexa\\Modules\\Hello; class OrmManager {}\n");
        });
        $violations = $this->runValidator($tmp['rel'], 'application', 'Hello', $tmp['projectRoot']);
        $hits = array_filter($violations, fn($v) => $v->actual === 'OrmManager.php');
        $this->assertNotEmpty($hits, 'OrmManager.php must be rejected at application module root');
    }

    // ---------- Guard rails ----------

    public function test_local_extension_cannot_allow_demo(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/forbidden override.*Demo|production-pollution/i');
        $this->loadWithExtensionFile($this->demoExtensionPhp());
    }

    public function test_local_extension_cannot_redeclare_canonical_layer(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/redeclare canonical top-level/i');
        $this->loadWithExtensionFile($this->canonicalRedeclarationPhp());
    }

    public function test_local_extension_cannot_introduce_domain_contract_rule(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Domain\\\\Contract|Domain\/Contract/');
        $this->loadWithExtensionFile($this->domainContractRulePhp());
    }

    public function test_local_extension_cannot_introduce_exception_rule(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Exception/');
        $this->loadWithExtensionFile($this->exceptionRulePhp());
    }

    public function test_local_extension_top_level_directory_requires_path_rule(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no pathRules\[\w+\] entry|silent skipping is forbidden/i');
        $this->loadWithExtensionFile($this->missingPathRulePhp());
    }

    public function test_local_extension_top_level_file_must_be_php(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be a \*\.php basename/');
        $this->loadWithExtensionFile($this->nonPhpRootFilePhp());
    }

    public function test_local_extension_package_field_must_match_directory(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/declares package "wrong" but the directory implies/');
        $this->loadWithExtensionFile($this->wrongPackageFieldPhp(), packageDirName: 'semitexa-feature-x');
    }

    public function test_local_extension_must_return_correct_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must return a .*LocalModuleStructureExtension/');
        $this->loadWithExtensionFile("<?php\nreturn new \\stdClass();\n");
    }

    // ---------- helpers ----------

    /**
     * @return array{rel: string, projectRoot: string}
     */
    private function makeFakePackage(string $packageDir, callable $populate): array
    {
        $tmp = sys_get_temp_dir() . '/local-ext-' . uniqid();
        $root = $tmp . '/packages/' . $packageDir;
        mkdir($root . '/src', 0o777, true);
        file_put_contents($root . '/composer.json', json_encode([
            'name' => 'semitexa/' . substr($packageDir, strlen('semitexa-')),
            'autoload' => ['psr-4' => ['Semitexa\\FeatureX\\' => 'src/']],
        ]));
        $populate($root);
        return ['rel' => 'packages/' . $packageDir, 'projectRoot' => $tmp];
    }

    /**
     * @return array{rel: string, projectRoot: string}
     */
    private function makeApplicationModule(string $name, callable $populate): array
    {
        $tmp = sys_get_temp_dir() . '/local-ext-app-' . uniqid();
        $root = $tmp . '/src/modules/' . $name;
        mkdir($root, 0o777, true);
        $populate($root);
        return ['rel' => 'src/modules/' . $name, 'projectRoot' => $tmp];
    }

    /**
     * @return list<\Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureViolation>
     */
    private function runValidator(string $relPath, string $kind, string $name, ?string $projectRoot = null): array
    {
        $root = $projectRoot ?? $this->projectRoot;
        $spec = (new ModuleStructureSpecLoader($this->projectRoot))->load();
        $module = new DetectedModule(
            name: $name,
            relativePath: $relPath,
            kind: $kind === 'package' ? DetectedModule::KIND_PACKAGE : DetectedModule::KIND_APPLICATION,
        );
        return (new ModuleStructureValidator($root, $spec))->validate($module);
    }

    /**
     * @param list<\Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureViolation> $violations
     */
    private function assertViolation(array $violations, string $codeFragment, string $actual): void
    {
        foreach ($violations as $v) {
            if (str_contains($v->code, $codeFragment) && $v->actual === $actual) {
                $this->assertTrue(true);
                return;
            }
        }
        $found = array_map(fn($v) => "[{$v->code}] {$v->actual}", $violations);
        $this->fail("Expected violation with code containing '{$codeFragment}' and actual '{$actual}' — got: " . implode(' | ', $found));
    }

    private function loadWithExtensionFile(string $phpSource, string $packageDirName = 'semitexa-feature-x'): void
    {
        $tmp = sys_get_temp_dir() . '/local-ext-malformed-' . uniqid();
        mkdir($tmp . '/packages/' . $packageDirName . '/config', 0o777, true);
        mkdir($tmp . '/packages/' . $packageDirName . '/src', 0o777, true);
        file_put_contents($tmp . '/packages/' . $packageDirName . '/config/module-structure.php', $phpSource);
        // The loader needs a global spec to load first — point it at the
        // real one but use the temp project root so its discovery scan
        // hits ONLY our malformed extension.
        $loader = new ModuleStructureSpecLoader(
            projectRoot: $tmp,
            specPathOverride: $this->projectRoot . '/' . ModuleStructureSpecLoader::SPEC_REL_PATH,
        );
        $loader->load();
    }

    private function demoExtensionPhp(): string
    {
        return "<?php\nuse Semitexa\\Dev\\Application\\Service\\Ai\\Verify\\Structure\\LocalModuleStructureExtension;\n"
            . "use Semitexa\\Dev\\Application\\Service\\Ai\\Verify\\Structure\\ModuleStructureRule;\n"
            . "return new LocalModuleStructureExtension(\n"
            . "    package: 'feature-x',\n"
            . "    topLevelDirectories: ['Demo'],\n"
            . "    pathRules: ['Demo' => new ModuleStructureRule(path: 'Demo')],\n"
            . ");\n";
    }

    private function canonicalRedeclarationPhp(): string
    {
        return "<?php\nuse Semitexa\\Dev\\Application\\Service\\Ai\\Verify\\Structure\\LocalModuleStructureExtension;\n"
            . "use Semitexa\\Dev\\Application\\Service\\Ai\\Verify\\Structure\\ModuleStructureRule;\n"
            . "return new LocalModuleStructureExtension(\n"
            . "    package: 'feature-x',\n"
            . "    topLevelDirectories: ['Application'],\n"
            . "    pathRules: ['Application' => new ModuleStructureRule(path: 'Application')],\n"
            . ");\n";
    }

    private function domainContractRulePhp(): string
    {
        return "<?php\nuse Semitexa\\Dev\\Application\\Service\\Ai\\Verify\\Structure\\LocalModuleStructureExtension;\n"
            . "use Semitexa\\Dev\\Application\\Service\\Ai\\Verify\\Structure\\ModuleStructureRule;\n"
            . "return new LocalModuleStructureExtension(\n"
            . "    package: 'feature-x',\n"
            . "    topLevelDirectories: ['MyDir'],\n"
            . "    pathRules: ['MyDir' => new ModuleStructureRule(path: 'MyDir'), 'Domain/Contract' => new ModuleStructureRule(path: 'Domain/Contract', allowAnyFile: true)],\n"
            . ");\n";
    }

    private function exceptionRulePhp(): string
    {
        return "<?php\nuse Semitexa\\Dev\\Application\\Service\\Ai\\Verify\\Structure\\LocalModuleStructureExtension;\n"
            . "use Semitexa\\Dev\\Application\\Service\\Ai\\Verify\\Structure\\ModuleStructureRule;\n"
            . "return new LocalModuleStructureExtension(\n"
            . "    package: 'feature-x',\n"
            . "    topLevelDirectories: ['MyDir'],\n"
            . "    pathRules: ['MyDir' => new ModuleStructureRule(path: 'MyDir'), 'Exception' => new ModuleStructureRule(path: 'Exception', allowAnyFile: true)],\n"
            . ");\n";
    }

    private function missingPathRulePhp(): string
    {
        return "<?php\nuse Semitexa\\Dev\\Application\\Service\\Ai\\Verify\\Structure\\LocalModuleStructureExtension;\n"
            . "return new LocalModuleStructureExtension(\n"
            . "    package: 'feature-x',\n"
            . "    topLevelDirectories: ['MyDir'],\n"
            . "    pathRules: [],\n"
            . ");\n";
    }

    private function nonPhpRootFilePhp(): string
    {
        return "<?php\nuse Semitexa\\Dev\\Application\\Service\\Ai\\Verify\\Structure\\LocalModuleStructureExtension;\n"
            . "return new LocalModuleStructureExtension(\n"
            . "    package: 'feature-x',\n"
            . "    topLevelDirectories: [],\n"
            . "    topLevelFiles: ['hello.txt'],\n"
            . ");\n";
    }

    private function wrongPackageFieldPhp(): string
    {
        return "<?php\nuse Semitexa\\Dev\\Application\\Service\\Ai\\Verify\\Structure\\LocalModuleStructureExtension;\n"
            . "return new LocalModuleStructureExtension(\n"
            . "    package: 'wrong',\n"
            . ");\n";
    }
}

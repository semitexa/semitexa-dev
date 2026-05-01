<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Structure;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\DetectedModule;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureSpecLoader;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureValidator;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureViolation;

/**
 * Repo-wide crash test fixtures pinned to the synthetic-package model.
 *
 * The 20 adversarial scenarios + 10 positive scenarios from the Phase 6
 * audit. These tests exist so the strict module-structure rules cannot
 * silently regress: every adversarial structure that AI agents are known
 * to produce MUST fail, every documented canonical layout MUST pass.
 *
 * Each scenario uses an isolated synthetic package / module under a fresh
 * temp directory, so no real repo path can mask a regression.
 */
class ModuleStructureCrashTestTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-crash-test-' . uniqid();
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    // ===================== ADVERSARIAL =====================

    public function test_adv_1_openapi_attribute_subdir_in_api_fails(): void
    {
        // The audit collapsed src/Attribute + src/OpenApi/Attribute.
        // Re-introducing OpenApi/Attribute fires unknown_directory.
        $this->scaffoldPackage('api', ['src/OpenApi/Attribute']);
        $this->writePackageFile('api', 'src/OpenApi/Attribute/Foo.php', "<?php\n");
        $this->assertFailsAt('api', ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/OpenApi/Attribute');
    }

    public function test_adv_2_attributes_plural_in_api_fails(): void
    {
        // Singular Attribute is canonical; plural is drift.
        $this->scaffoldPackage('api', ['src/Attributes']);
        $this->writePackageFile('api', 'src/Attributes/Foo.php', "<?php\n");
        $this->assertFailsAt('api', ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/Attributes');
    }

    public function test_adv_3_demo_in_production_package_fails(): void
    {
        $this->scaffoldPackage('api', ['src/Demo']);
        $this->writePackageFile('api', 'src/Demo/Foo.php', "<?php\n");
        $this->assertHasCode('api', ModuleStructureViolation::CODE_PRODUCTION_PACKAGE_POLLUTION);
    }

    public function test_adv_4_sandbox_in_production_package_fails(): void
    {
        $this->scaffoldPackage('api', ['src/Sandbox']);
        $this->writePackageFile('api', 'src/Sandbox/Foo.php', "<?php\n");
        $this->assertHasCode('api', ModuleStructureViolation::CODE_PRODUCTION_PACKAGE_POLLUTION);
    }

    public function test_adv_5_application_services_in_api_fails(): void
    {
        $this->scaffoldPackage('api', ['src/Application/Services']);
        $this->writePackageFile('api', 'src/Application/Services/Foo.php', "<?php\n");
        $this->assertFailsAt('api', ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/Application/Services');
    }

    public function test_adv_6_domain_repository_dir_in_api_fails(): void
    {
        // Phase 3 removed Domain/Repository. Concrete repos go in
        // Application/Db/<Adapter>/Repository, interfaces in Domain/Contract.
        $this->scaffoldPackage('api', ['src/Domain/Repository']);
        $this->writePackageFile('api', 'src/Domain/Repository/FooRepositoryInterface.php', "<?php\n");
        $this->assertFailsAt('api', ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/Domain/Repository');
    }

    public function test_adv_7_domain_model_resource_model_fails(): void
    {
        // Persistence resource models belong under Application/Db/<Adapter>/Model.
        $this->scaffoldPackage('api', ['src/Domain/Model']);
        $this->writePackageFile('api', 'src/Domain/Model/FooResourceModel.php', "<?php\n");
        $this->assertFailsAt('api', ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-api/src/Domain/Model/FooResourceModel.php');
    }

    public function test_adv_8_application_db_unsupported_adapter_postgres_fails(): void
    {
        // Only MySQL + SQLite are officially supported ORM adapters.
        $this->scaffoldPackage('api', ['src/Application/Db/Postgres/Model']);
        $this->writePackageFile('api', 'src/Application/Db/Postgres/Model/FooResource.php', "<?php\n");
        $this->assertFailsAt('api', ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/Application/Db/Postgres');
    }

    public function test_adv_9_application_db_mysql_model_service_filename_fails(): void
    {
        // Application/Db/<Adapter>/Model accepts only *Resource / *ResourceModel / *Mapper.
        $this->scaffoldPackage('api', ['src/Application/Db/MySQL/Model']);
        $this->writePackageFile('api', 'src/Application/Db/MySQL/Model/FooService.php', "<?php\n");
        $this->assertFailsAt('api', ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-api/src/Application/Db/MySQL/Model/FooService.php');
    }

    public function test_adv_10_top_level_console_command_in_non_core_fails(): void
    {
        // Top-level Console is core-only. Application commands go under
        // Application/Console/Command.
        $this->scaffoldPackage('api', ['src/Console/Command']);
        $this->writePackageFile('api', 'src/Console/Command/FooCommand.php', "<?php\n");
        $this->assertHasCode('api', ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY);
        $this->assertHasCode('api', ModuleStructureViolation::CODE_COMMAND_WRONG_LOCATION);
    }

    public function test_adv_11_container_in_non_core_package_fails(): void
    {
        // Container is core-only.
        $this->scaffoldPackage('api', ['src/Container']);
        $this->writePackageFile('api', 'src/Container/Foo.php', "<?php\n");
        $this->assertFailsAt('api', ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/Container');
    }

    public function test_adv_12_auth_in_app_module_fails(): void
    {
        // Auth is package-only. App modules cannot host it.
        $this->scaffoldModule('Playground', ['Auth']);
        $this->writeFile('src/modules/Playground/Auth/Foo.php', "<?php\n");
        $violations = $this->validator()->validate($this->module('Playground'));
        $this->assertViolationAt($violations,
            ModuleStructureViolation::CODE_INVALID_LAYER,
            'src/modules/Playground/Auth');
    }

    public function test_adv_13_openapi_in_app_module_fails(): void
    {
        // OpenApi is package-only.
        $this->scaffoldModule('Playground', ['OpenApi']);
        $this->writeFile('src/modules/Playground/OpenApi/Foo.php', "<?php\n");
        $violations = $this->validator()->validate($this->module('Playground'));
        $this->assertViolationAt($violations,
            ModuleStructureViolation::CODE_INVALID_LAYER,
            'src/modules/Playground/OpenApi');
    }

    public function test_adv_14_core_container_helpers_subdir_fails(): void
    {
        // Container is deep_validated with explicit children
        // [BuildPhase, Exception, Store]. Helpers/ is not in the list.
        $this->scaffoldPackage('core', ['src/Container/Helpers']);
        $this->assertFailsAt('core', ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-core/src/Container/Helpers');
    }

    public function test_adv_15_core_support_helper_filename_fails(): void
    {
        // Support's drift deny-list rejects *Helper.php / *Util.php / etc.
        $this->scaffoldPackage('core', ['src/Support']);
        $this->writePackageFile('core', 'src/Support/FooHelper.php', "<?php\n");
        $this->assertFailsAt('core', ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Support/FooHelper.php');
    }

    public function test_adv_16_core_phpstan_extension_subdir_fails(): void
    {
        // PHPStan/ accepts only Rules/ child.
        $this->scaffoldPackage('core', ['src/PHPStan/Extension']);
        $this->assertFailsAt('core', ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-core/src/PHPStan/Extension');
    }

    public function test_adv_17_core_theme_non_theme_prefix_filename_fails(): void
    {
        $this->scaffoldPackage('core', ['src/Theme']);
        $this->writePackageFile('core', 'src/Theme/FooFrontend.php', "<?php\n");
        $this->assertFailsAt('core', ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Theme/FooFrontend.php');
    }

    public function test_adv_18_core_contract_non_interface_filename_fails(): void
    {
        $this->scaffoldPackage('core', ['src/Contract']);
        $this->writePackageFile('core', 'src/Contract/Foo.php', "<?php\n");
        $this->assertFailsAt('core', ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Contract/Foo.php');
    }

    public function test_adv_19_core_locale_helper_filename_fails(): void
    {
        $this->scaffoldPackage('core', ['src/Locale']);
        $this->writePackageFile('core', 'src/Locale/FooHelper.php', "<?php\n");
        $this->assertFailsAt('core', ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Locale/FooHelper.php');
    }

    public function test_adv_20_core_tenant_helpers_subdir_fails(): void
    {
        // Tenant accepts only Layer/+Scope/ children.
        $this->scaffoldPackage('core', ['src/Tenant/Helpers']);
        $this->assertFailsAt('core', ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-core/src/Tenant/Helpers');
    }

    // ===================== Phase 14: Console/Command + Domain/Command =====================

    public function test_adv_21_core_top_level_console_command_subdir_fails(): void
    {
        // Phase 14: removed `Command` from top-level Console.allowedDirectories.
        // Even in semitexa-core, a `Console/Command/FooCommand.php` is now drift —
        // executable commands live exclusively at `Application/Console/Command/`.
        $this->scaffoldPackage('core', ['src/Console/Command']);
        $this->writePackageFile('core', 'src/Console/Command/FooCommand.php', "<?php\n");
        $this->assertHasCode('core', ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY);
        $this->assertHasCode('core', ModuleStructureViolation::CODE_COMMAND_WRONG_LOCATION);
    }

    public function test_adv_22_core_top_level_console_loose_file_fails(): void
    {
        // Top-level Console allows ONLY two named files (Application.php +
        // BaseCommand.php) and one subdir (Runtime/). A loose helper file
        // alongside them must fail.
        $this->scaffoldPackage('core', ['src/Console']);
        $this->writePackageFile('core', 'src/Console/ConsoleHelpers.php', "<?php\n");
        $this->assertFailsAt('core', ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Console/ConsoleHelpers.php');
    }

    public function test_adv_23_core_top_level_console_unknown_subdir_fails(): void
    {
        // `Console/` allows only `Runtime/` as a subdir. Anything else fails.
        $this->scaffoldPackage('core', ['src/Console/Helpers']);
        $this->assertFailsAt('core', ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-core/src/Console/Helpers');
    }

    public function test_adv_24_domain_command_non_command_filename_fails(): void
    {
        // Domain/Command is for CQRS *Command.php DTOs only. A misplaced
        // service/handler file must fail invalid_location — the rule does
        // not allow arbitrary files.
        $this->scaffoldPackage('api', ['src/Application/Handler/PayloadHandler', 'src/Domain/Command']);
        $this->writePackageFile('api', 'src/Domain/Command/FooService.php', "<?php\n");
        $this->assertFailsAt('api', ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-api/src/Domain/Command/FooService.php');
    }

    public function test_adv_25_domain_command_lowercase_filename_fails(): void
    {
        // Pattern requires PascalCase; a lowercase basename fails.
        $this->scaffoldPackage('api', ['src/Application/Handler/PayloadHandler', 'src/Domain/Command']);
        $this->writePackageFile('api', 'src/Domain/Command/foocommand.php', "<?php\n");
        $this->assertFailsAt('api', ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-api/src/Domain/Command/foocommand.php');
    }

    public function test_adv_26_console_command_in_app_module_fails(): void
    {
        // `Application/Console/Command/` is canonical, but a top-level
        // `Console/` inside an app module is drift (top-level Console is
        // core-only via packageSpecificCodeRoot, and app modules have no
        // such allowance at all).
        $this->scaffoldModule('Playground', ['Console/Command']);
        $this->writeFile('src/modules/Playground/Console/Command/FooCommand.php', "<?php\n");
        $violations = $this->validator()->validate($this->module('Playground'));
        $this->assertViolationAt($violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'src/modules/Playground/Console');
    }

    // ===================== POSITIVE =====================

    public function test_pos_1_canonical_attribute_in_api_passes(): void
    {
        $this->scaffoldPackage('api', ['src/Application/Handler/PayloadHandler', 'src/Attribute']);
        $this->writePackageFile('api', 'src/Attribute/ProducesResourceObject.php', "<?php\n");
        $this->assertPasses('api');
    }

    public function test_pos_2_openapi_schema_in_api_passes(): void
    {
        $this->scaffoldPackage('api', ['src/Application/Handler/PayloadHandler', 'src/OpenApi/Schema']);
        $this->writePackageFile('api', 'src/OpenApi/Schema/ResourceSchemaGenerator.php', "<?php\n");
        $this->assertPasses('api');
    }

    public function test_pos_3_openapi_route_in_api_passes(): void
    {
        $this->scaffoldPackage('api', ['src/Application/Handler/PayloadHandler', 'src/OpenApi/Route']);
        $this->writePackageFile('api', 'src/OpenApi/Route/ResourceRouteSchemaGenerator.php', "<?php\n");
        $this->assertPasses('api');
    }

    public function test_pos_4_application_console_command_in_api_passes(): void
    {
        $this->scaffoldPackage('api', ['src/Application/Console/Command']);
        $this->writePackageFile('api', 'src/Application/Console/Command/DumpOpenApiCommand.php', "<?php\n");
        $this->assertPasses('api');
    }

    public function test_pos_5_application_db_mysql_resource_in_api_passes(): void
    {
        $this->scaffoldPackage('api', ['src/Application/Handler/PayloadHandler', 'src/Application/Db/MySQL/Model']);
        $this->writePackageFile('api', 'src/Application/Db/MySQL/Model/FooResource.php', "<?php\n");
        $this->assertPasses('api');
    }

    public function test_pos_6_application_db_sqlite_repository_in_api_passes(): void
    {
        $this->scaffoldPackage('api', ['src/Application/Handler/PayloadHandler', 'src/Application/Db/SQLite/Repository']);
        $this->writePackageFile('api', 'src/Application/Db/SQLite/Repository/FooRepository.php', "<?php\n");
        $this->assertPasses('api');
    }

    public function test_pos_7_domain_contract_repository_interface_in_api_passes(): void
    {
        $this->scaffoldPackage('api', ['src/Application/Handler/PayloadHandler', 'src/Domain/Contract']);
        $this->writePackageFile('api', 'src/Domain/Contract/FooRepositoryInterface.php', "<?php\n");
        $this->assertPasses('api');
    }

    public function test_pos_8_core_container_canonical_layout_passes(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Container/BuildPhase',
            'src/Container/Exception',
            'src/Container/Store',
        ]);
        $this->writePackageFile('core', 'src/Container/SemitexaContainer.php',     "<?php\n");
        $this->writePackageFile('core', 'src/Container/Exception/NotFoundException.php', "<?php\n");
        $this->assertPasses('core');
    }

    public function test_pos_9_core_phpstan_rule_class_passes(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/PHPStan/Rules',
        ]);
        $this->writePackageFile('core', 'src/PHPStan/Rules/FooRule.php', "<?php\n");
        $this->assertPasses('core');
    }

    public function test_pos_10_app_module_canonical_layout_passes(): void
    {
        // Local Playground-style module with canonical Application + Domain.
        $this->scaffoldModule('Playground', [
            'Application/Payload/Request',
            'Application/Resource/Response',
            'Application/Handler/PayloadHandler',
            'Application/Service',
            'Application/Console/Command',
            'Domain/Model',
            'Domain/Contract',
        ]);
        $violations = $this->validator()->validate($this->module('Playground'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    // ===================== Phase 14 positive: relocated infra + Domain/Command DTOs =====================

    public function test_pos_11_core_application_console_command_passes(): void
    {
        // After Phase 14, semitexa-core's executable commands live at
        // Application/Console/Command/, same as every other package.
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Application/Console/Command',
        ]);
        $this->writePackageFile('core', 'src/Application/Console/Command/CacheClearCommand.php', "<?php\n");
        $this->assertPasses('core');
    }

    public function test_pos_12_core_console_base_command_passes(): void
    {
        // BaseCommand.php is the abstract infrastructure base — it lives at
        // top-level Console/, NOT under Console/Command/.
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Console',
        ]);
        $this->writePackageFile('core', 'src/Console/BaseCommand.php', "<?php\n");
        $this->assertPasses('core');
    }

    public function test_pos_13_core_console_application_passes(): void
    {
        // The Symfony console kernel `Application.php` is allowed at top-level
        // Console/.
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Console',
        ]);
        $this->writePackageFile('core', 'src/Console/Application.php', "<?php\n");
        $this->assertPasses('core');
    }

    public function test_pos_14_core_console_runtime_subtree_passes(): void
    {
        // Runtime/ is the only allowed sub-directory of Console/, and it is
        // opaque_internal — anything inside passes.
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Console/Runtime',
        ]);
        $this->writePackageFile('core', 'src/Console/Runtime/AnyClass.php', "<?php\n");
        $this->assertPasses('core');
    }

    public function test_pos_15_domain_command_dto_passes_no_command_wrong_location(): void
    {
        // Workflow's CQRS DTOs (e.g., StartWorkflowCommand) must NOT trigger
        // command_wrong_location — the placement rule defers under
        // Domain/Command, and the per-directory rule accepts *Command.php.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Command',
        ]);
        $this->writePackageFile('api', 'src/Domain/Command/StartWorkflowCommand.php', "<?php\n");
        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
        foreach ($violations as $v) {
            $this->assertNotSame(
                ModuleStructureViolation::CODE_COMMAND_WRONG_LOCATION,
                $v->code,
                'Domain/Command DTO must never fire command_wrong_location',
            );
        }
    }

    public function test_pos_16_domain_command_feature_grouped_dto_passes(): void
    {
        // Feature-grouping is allowed under Domain/Command — the same exempt
        // path applies to nested folders.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Command/Workflow',
        ]);
        $this->writePackageFile('api', 'src/Domain/Command/Workflow/ApplyTransitionCommand.php', "<?php\n");
        $this->assertPasses('api');
    }

    // ===================== Phase 14b: Domain/Command loophole closure =====================
    // Path-only exemption is not enough — we read file content under
    // Domain/Command and revoke the exemption when the file is, in fact,
    // an executable console command (carries #[AsCommand], extends
    // BaseCommand, or references Symfony's console Command base class).

    public function test_loophole_1_plain_dto_passes(): void
    {
        // A plausible CQRS DTO body — final readonly class, value-object
        // shape, no console coupling — must pass cleanly.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Command',
        ]);
        $this->writePackageFile(
            'api',
            'src/Domain/Command/StartWorkflowCommand.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Semitexa\\Api\\Domain\\Command;\n\nfinal readonly class StartWorkflowCommand\n{\n    public function __construct(public string \$workflowId, public string \$initiator) {}\n}\n",
        );
        $this->assertPasses('api');
    }

    public function test_loophole_2_as_command_attribute_fails(): void
    {
        // #[AsCommand] is the canonical Semitexa marker for an executable
        // console command. Hiding one under Domain/Command must fire
        // command_wrong_location.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Command',
        ]);
        $this->writePackageFile(
            'api',
            'src/Domain/Command/FooCommand.php',
            "<?php\n\nnamespace Semitexa\\Api\\Domain\\Command;\n\nuse Semitexa\\Core\\Attribute\\AsCommand;\n\n#[AsCommand(name: 'foo:run', description: 'do it')]\nfinal class FooCommand\n{\n}\n",
        );
        $this->assertFailsAt(
            'api',
            ModuleStructureViolation::CODE_COMMAND_WRONG_LOCATION,
            'packages/semitexa-api/src/Domain/Command/FooCommand.php',
        );
    }

    public function test_loophole_3_extends_base_command_fails(): void
    {
        // Extending Semitexa\Core\Console\BaseCommand makes the file an
        // executable console command regardless of where it lives.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Command',
        ]);
        $this->writePackageFile(
            'api',
            'src/Domain/Command/FooCommand.php',
            "<?php\n\nnamespace Semitexa\\Api\\Domain\\Command;\n\nuse Semitexa\\Core\\Console\\BaseCommand;\n\nfinal class FooCommand extends BaseCommand\n{\n}\n",
        );
        $this->assertFailsAt(
            'api',
            ModuleStructureViolation::CODE_COMMAND_WRONG_LOCATION,
            'packages/semitexa-api/src/Domain/Command/FooCommand.php',
        );
    }

    public function test_loophole_4_extends_symfony_command_fails(): void
    {
        // Semitexa has no separate "ConsoleCommandInterface" — the closest
        // contract is Symfony's `Symfony\Component\Console\Command\Command`
        // base class, which both Semitexa's BaseCommand and any direct
        // Symfony command extends. Referencing this FQCN inside
        // Domain/Command is a strong signal the file is wired into the
        // console kernel.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Command',
        ]);
        $this->writePackageFile(
            'api',
            'src/Domain/Command/FooCommand.php',
            "<?php\n\nnamespace Semitexa\\Api\\Domain\\Command;\n\nuse Symfony\\Component\\Console\\Command\\Command;\n\nfinal class FooCommand extends Command\n{\n}\n",
        );
        $this->assertFailsAt(
            'api',
            ModuleStructureViolation::CODE_COMMAND_WRONG_LOCATION,
            'packages/semitexa-api/src/Domain/Command/FooCommand.php',
        );
    }

    public function test_loophole_5_application_console_command_with_as_command_passes(): void
    {
        // The canonical home — even with the same #[AsCommand] body that
        // would fail under Domain/Command — must pass.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Application/Console/Command',
        ]);
        $this->writePackageFile(
            'api',
            'src/Application/Console/Command/FooCommand.php',
            "<?php\n\nnamespace Semitexa\\Api\\Application\\Console\\Command;\n\nuse Semitexa\\Core\\Attribute\\AsCommand;\nuse Semitexa\\Core\\Console\\BaseCommand;\n\n#[AsCommand(name: 'foo:run', description: 'do it')]\nfinal class FooCommand extends BaseCommand\n{\n}\n",
        );
        $this->assertPasses('api');
    }

    public function test_loophole_6_top_level_console_command_in_non_core_still_fails(): void
    {
        // Phase 14: top-level Console/Command/ is unknown_directory in
        // every non-core package. The content-aware addition must NOT
        // silently legalise this drift.
        $this->scaffoldPackage('api', ['src/Console/Command']);
        $this->writePackageFile(
            'api',
            'src/Console/Command/FooCommand.php',
            "<?php\n\nuse Semitexa\\Core\\Attribute\\AsCommand;\n\n#[AsCommand(name: 'foo:run')]\nfinal class FooCommand {}\n",
        );
        $this->assertHasCode('api', ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY);
        $this->assertHasCode('api', ModuleStructureViolation::CODE_COMMAND_WRONG_LOCATION);
    }

    public function test_loophole_7_top_level_console_command_in_core_still_fails(): void
    {
        // Phase 14: even semitexa-core no longer enjoys a Console/Command
        // exception. The content-aware addition must not weaken this.
        $this->scaffoldPackage('core', ['src/Console/Command']);
        $this->writePackageFile(
            'core',
            'src/Console/Command/FooCommand.php',
            "<?php\n\nuse Semitexa\\Core\\Attribute\\AsCommand;\n\n#[AsCommand(name: 'foo:run')]\nfinal class FooCommand {}\n",
        );
        $this->assertHasCode('core', ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY);
        $this->assertHasCode('core', ModuleStructureViolation::CODE_COMMAND_WRONG_LOCATION);
    }

    public function test_loophole_8_real_workflow_dto_layout_passes_with_realistic_body(): void
    {
        // Mirrors the real semitexa-workflow Domain/Command/ shape: 3
        // final readonly DTO classes with constructor properties. None
        // touch the console kernel; all must pass.
        $this->scaffoldPackage('workflow', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Command',
        ]);
        $body = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Semitexa\\Workflow\\Domain\\Command;\n\nfinal readonly class %s\n{\n    public function __construct(public string \$workflowId) {}\n}\n";
        $this->writePackageFile('workflow', 'src/Domain/Command/StartWorkflowCommand.php',         sprintf($body, 'StartWorkflowCommand'));
        $this->writePackageFile('workflow', 'src/Domain/Command/ApplyTransitionCommand.php',      sprintf($body, 'ApplyTransitionCommand'));
        $this->writePackageFile('workflow', 'src/Domain/Command/ScheduleWorkflowCheckCommand.php', sprintf($body, 'ScheduleWorkflowCheckCommand'));
        $violations = $this->validator()->validate($this->package('workflow'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
        foreach ($violations as $v) {
            $this->assertNotSame(
                ModuleStructureViolation::CODE_COMMAND_WRONG_LOCATION,
                $v->code,
                'realistic Domain/Command DTOs must never fire command_wrong_location',
            );
        }
    }

    // ===================== Cross-cutting: dotfile false-positive guard =====================

    public function test_gitkeep_does_not_trigger_invalid_location(): void
    {
        // Phase 6 fix: scaffold-marker dotfiles (.gitkeep, .gitignore, .keep,
        // .DS_Store) are universally ignored inside the code root. They do
        // NOT count as architectural files.
        $this->scaffoldPackage('api', ['src/Application/Handler/PayloadHandler', 'src/Domain/Contract']);
        $this->writePackageFile('api', 'src/Domain/Contract/.gitkeep', '');
        $this->writePackageFile('api', 'src/Domain/Contract/.DS_Store', '');
        $this->assertPasses('api');
    }

    // ===================== helpers =====================

    private function validator(): ModuleStructureValidator
    {
        return new ModuleStructureValidator(
            $this->root,
            (new ModuleStructureSpecLoader(dirname(__DIR__, 7)))->load(),
        );
    }

    private function package(string $name): DetectedModule
    {
        return new DetectedModule(
            name: $name,
            relativePath: 'packages/semitexa-' . $name,
            kind: DetectedModule::KIND_PACKAGE,
        );
    }

    private function module(string $name): DetectedModule
    {
        return new DetectedModule(
            name: $name,
            relativePath: 'src/modules/' . $name,
            kind: DetectedModule::KIND_APPLICATION,
        );
    }

    /**
     * @param list<string> $relativeDirs
     */
    private function scaffoldPackage(string $name, array $relativeDirs): void
    {
        $base = $this->root . '/packages/semitexa-' . $name;
        if (!is_dir($base)) {
            mkdir($base, 0755, true);
            file_put_contents($base . '/composer.json', '{}');
            file_put_contents($base . '/LICENSE', '');
            file_put_contents($base . '/README.md', '');
        }
        foreach ($relativeDirs as $rel) {
            mkdir($base . '/' . $rel, 0755, true);
        }
    }

    /**
     * @param list<string> $relativeDirs
     */
    private function scaffoldModule(string $name, array $relativeDirs): void
    {
        $base = $this->root . '/src/modules/' . $name;
        mkdir($base, 0755, true);
        foreach ($relativeDirs as $rel) {
            mkdir($base . '/' . $rel, 0755, true);
        }
    }

    private function writePackageFile(string $name, string $relativeFile, string $contents): void
    {
        $path = $this->root . '/packages/semitexa-' . $name . '/' . $relativeFile;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
    }

    private function writeFile(string $relativePath, string $contents): void
    {
        $path = $this->root . '/' . $relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
    }

    private function assertPasses(string $packageName): void
    {
        $violations = $this->validator()->validate($this->package($packageName));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    private function assertFailsAt(string $packageName, string $code, string $path): void
    {
        $violations = $this->validator()->validate($this->package($packageName));
        $this->assertViolationAt($violations, $code, $path);
    }

    private function assertHasCode(string $packageName, string $code): void
    {
        $violations = $this->validator()->validate($this->package($packageName));
        foreach ($violations as $v) {
            if ($v->code === $code) {
                $this->assertTrue(true);
                return;
            }
        }
        $this->fail("expected violation code {$code}; got:\n" . $this->renderViolations($violations));
    }

    /**
     * @param list<ModuleStructureViolation> $violations
     */
    private function assertViolationAt(array $violations, string $code, string $path): void
    {
        foreach ($violations as $v) {
            if ($v->code === $code && $v->path === $path) {
                $this->assertTrue(true);
                return;
            }
        }
        $this->fail("expected {$code} at '{$path}'; got:\n" . $this->renderViolations($violations));
    }

    /**
     * @param list<ModuleStructureViolation> $violations
     */
    private function renderViolations(array $violations): string
    {
        if ($violations === []) {
            return '(none)';
        }
        $lines = [];
        foreach ($violations as $v) {
            $lines[] = sprintf('  %s @ %s', $v->code, $v->path);
        }
        return implode("\n", $lines);
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

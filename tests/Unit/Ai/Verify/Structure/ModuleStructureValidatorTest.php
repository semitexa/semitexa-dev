<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Structure;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Ai\Verify\Structure\DetectedModule;
use Semitexa\Dev\Ai\Verify\Structure\ModuleStructureSpecLoader;
use Semitexa\Dev\Ai\Verify\Structure\ModuleStructureValidator;
use Semitexa\Dev\Ai\Verify\Structure\ModuleStructureViolation;

/**
 * The strict-allowlist module structure validator. Every test here builds a
 * synthetic module / package tree under a temp directory, runs the validator
 * loaded from the real executable spec at
 * `packages/semitexa-dev/config/module-structure.php`, and asserts the
 * specific {@see ModuleStructureViolation::CODE_*} codes that the user's
 * acceptance criteria require.
 *
 * The spec is the single source of truth — these tests pin its observable
 * behavior, NOT the validator's internal interpretation. If the spec changes,
 * tests change with it (deliberately, in lockstep with the doc mirror).
 */
class ModuleStructureValidatorTest extends TestCase
{
    private string $root;

    /** Project root that contains the real executable spec. */
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-strict-validator-' . uniqid();
        mkdir($this->root, 0755, true);
        // The spec lives in the real project; the loader reads it from there.
        // From .../packages/semitexa-dev/tests/Unit/Ai/Verify/Structure/X.php
        // up 7 levels = project root (.../var/www/html or local .../semitexa.dev).
        $this->projectRoot = dirname(__DIR__, 7);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    // ----------------------- ALLOWED ------------------------

    public function test_canonical_application_module_passes(): void
    {
        // ACCEPTANCE 1 (also 6): a valid declared structure passes.
        $this->scaffoldModule('Hello', [
            'Application/Payload/Request',
            'Application/Resource/Response',
            'Application/Handler/PayloadHandler',
            'Application/Service',
            'Application/View/templates',
            'Application/View/locales',
            'Application/Static/css',
            'Application/Console/Command',
            'Domain/Model',
            'Domain/Contract',
        ]);

        $violations = $this->validator()->validate($this->module('Hello'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_canonical_application_module_command_at_application_console_command_passes(): void
    {
        // ACCEPTANCE: a valid command under the declared Application command path passes.
        $this->scaffoldModule('Hello', [
            'Application/Console/Command',
            'Application/Handler/PayloadHandler',
        ]);
        $this->writeFile('src/modules/Hello/Application/Console/Command/SyncCommand.php', "<?php\n");

        $violations = $this->validator()->validate($this->module('Hello'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_canonical_package_with_command_at_application_console_command_passes(): void
    {
        // ACCEPTANCE: DumpOpenApiCommand-style real regression case — the
        // declared Application-layer location must pass.
        $this->scaffoldPackage('api', [
            'src/Application/Console/Command',
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Model',
            'tests/Unit',
            'docs',
        ], [
            'composer.json',
            'LICENSE',
            'README.md',
            'phpunit.xml.dist',
        ]);
        $this->writePackageFile('api', 'src/Application/Console/Command/DumpOpenApiCommand.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_feature_grouping_under_service_passes(): void
    {
        $this->scaffoldModule('Hello', [
            'Application/Service/Customer/Order',
            'Domain/Model/Customer',
        ]);
        $this->writeFile('src/modules/Hello/Application/Service/Customer/SyncCustomerService.php', "<?php\n");
        $this->writeFile('src/modules/Hello/Application/Service/Customer/Order/PlaceOrderService.php', "<?php\n");
        $this->writeFile('src/modules/Hello/Domain/Model/Customer/Customer.php', "<?php\n");

        $violations = $this->validator()->validate($this->module('Hello'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    // ----------------------- REJECTED ------------------------

    public function test_command_under_src_console_command_fails_with_command_wrong_location(): void
    {
        // ACCEPTANCE 2: a command under src/Console/Command fails.
        // For a package, src/Console/ is itself an unknown layer; the
        // command file additionally trips the file-placement rule.
        $this->scaffoldPackage('api', [
            'src/Console/Command',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Console/Command/DumpOpenApiCommand.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));

        // Either the command_wrong_location rule (file-placement) OR the
        // unknown_directory rule (Console/ not in top_level allowlist) must
        // fire — the user's contract is "the wrong path fails".
        $this->assertHasViolationCode($violations, ModuleStructureViolation::CODE_COMMAND_WRONG_LOCATION);
        $this->assertHasViolationCode($violations, ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY);
    }

    public function test_unknown_top_level_directory_under_src_fails(): void
    {
        // ACCEPTANCE 3: any unknown directory under src/ fails.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Endpoint',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt($violations, ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY, 'packages/semitexa-api/src/Endpoint');
    }

    public function test_invented_services_folder_at_root_fails(): void
    {
        // ACCEPTANCE 4: invented `src/Services` (note: capitalised, plural)
        // fails. Application/Service is the canonical location.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Services',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt($violations, ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY, 'packages/semitexa-api/src/Services');
    }

    /**
     * @dataProvider invented_root_folders
     */
    public function test_invented_root_folders_fail(string $directory): void
    {
        // ACCEPTANCE 4 (extended): every common AI-invented folder fails.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/' . $directory,
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/' . $directory,
        );
    }

    /** @return array<string, array{0: string}> */
    public static function invented_root_folders(): array
    {
        return [
            'Services'  => ['Services'],
            'Helpers'   => ['Helpers'],
            'Managers'  => ['Managers'],
            'Common'    => ['Common'],
            'Misc'      => ['Misc'],
            'Util'      => ['Util'],
            'Utils'     => ['Utils'],
            'Lib'       => ['Lib'],
            'Library'   => ['Library'],
            'Endpoint'  => ['Endpoint'],
            'CLI'       => ['CLI'],
            'Console'   => ['Console'],
            'Db'        => ['Db'],
        ];
    }

    public function test_file_in_wrong_layer_fails(): void
    {
        // ACCEPTANCE 5: a file placed in the wrong architectural layer fails.
        // A *Command.php file under Application/Service/ trips the
        // file-placement rule (commands belong under Application/Console/Command/).
        $this->scaffoldModule('Hello', [
            'Application/Service',
        ]);
        $this->writeFile('src/modules/Hello/Application/Service/SyncCommand.php', "<?php\n");

        $violations = $this->validator()->validate($this->module('Hello'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_COMMAND_WRONG_LOCATION,
            'src/modules/Hello/Application/Service/SyncCommand.php',
        );
    }

    public function test_payload_response_in_payload_layer_fails(): void
    {
        // The legacy `Application/Payload/Response/` shape is rejected because
        // Response/ is not in the explicitly allowed children of
        // Application/Payload (Request, Event, Part). Response DTOs belong in
        // Application/Resource/Response.
        $this->scaffoldModule('Hello', [
            'Application/Payload/Request',
            'Application/Payload/Response',
        ]);

        $violations = $this->validator()->validate($this->module('Hello'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'src/modules/Hello/Application/Payload/Response',
        );
    }

    public function test_handler_subtype_must_be_one_of_three(): void
    {
        $this->scaffoldModule('Hello', [
            'Application/Handler/PayloadHandler',
            'Application/Handler/Custom',
        ]);

        $violations = $this->validator()->validate($this->module('Hello'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'src/modules/Hello/Application/Handler/Custom',
        );
    }

    public function test_php_file_at_application_module_root_fails(): void
    {
        $this->scaffoldModule('Hello', [
            'Application/Handler/PayloadHandler',
        ]);
        $this->writeFile('src/modules/Hello/Helper.php', "<?php\n");

        $violations = $this->validator()->validate($this->module('Hello'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_ROOT_FILE,
            'src/modules/Hello/Helper.php',
        );
    }

    public function test_resources_directory_at_module_root_fails(): void
    {
        $this->scaffoldModule('Hello', [
            'Application/Handler/PayloadHandler',
            'resources/css',
        ]);

        $violations = $this->validator()->validate($this->module('Hello'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'src/modules/Hello/resources',
        );
    }

    public function test_package_only_layer_in_app_module_fires_invalid_layer(): void
    {
        // Package-only layers (Attribute/, Auth/, Discovery/, OpenApi/, Pipeline/)
        // are rejected inside src/modules/* with a layer violation; they are
        // permitted at package code roots only.
        $this->scaffoldModule('Hello', [
            'Application/Handler/PayloadHandler',
            'Attribute',
        ]);

        $violations = $this->validator()->validate($this->module('Hello'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LAYER,
            'src/modules/Hello/Attribute',
        );
    }

    public function test_auth_layer_in_app_module_fires_invalid_layer(): void
    {
        $this->scaffoldModule('Hello', [
            'Application/Handler/PayloadHandler',
            'Auth',
        ]);
        $violations = $this->validator()->validate($this->module('Hello'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LAYER,
            'src/modules/Hello/Auth',
        );
    }

    public function test_package_missing_composer_json_fails(): void
    {
        // Edge: a package directory that exists but lacks composer.json is not
        // resolved as a package by the resolver. Here we exercise the validator
        // directly with a DetectedModule pointing at a real package shape but
        // without composer.json on disk: missing_required_path fires.
        mkdir($this->root . '/packages/semitexa-api/src', 0755, true);

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationCode($violations, ModuleStructureViolation::CODE_MISSING_REQUIRED_PATH);
    }

    public function test_unknown_package_root_directory_fails(): void
    {
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'scratch',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/scratch',
        );
    }

    public function test_unknown_package_root_file_fails(): void
    {
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
        ], ['composer.json', 'LICENSE', 'README.md', 'notes.tmp']);

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_ROOT_FILE,
            'packages/semitexa-api/notes.tmp',
        );
    }

    // ----------------------- ENVELOPE / META ------------------------

    public function test_violation_payload_carries_actionable_fields(): void
    {
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Endpoint',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $arr = $this->violationFor(
            $this->validator()->validate($this->package('api')),
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/Endpoint',
        )->toArray();

        $this->assertSame('module_structure', $arr['check']);
        $this->assertSame('error', $arr['severity']);
        $this->assertSame('module_structure.unknown_directory', $arr['rule']);
        $this->assertSame('module_structure.unknown_directory', $arr['code']);
        $this->assertSame('packages/semitexa-api', $arr['module']);
        $this->assertSame('packages/semitexa-api/src/Endpoint', $arr['path']);
        $this->assertNotEmpty($arr['message']);
        $this->assertNotEmpty($arr['expected']);
        $this->assertNotEmpty($arr['actual']);
        $this->assertSame('packages/semitexa-docs/docs/MODULE_STRUCTURE.md', $arr['doc_ref']);
        $this->assertNotEmpty($arr['suggested_fix']);
    }

    public function test_repository_drift_does_not_silently_pass(): void
    {
        // ACCEPTANCE 8: existing repository drift must not be allowed simply
        // because it exists. Names that are NOT in the strict allowlist must
        // fail. (Auth, Discovery, OpenApi, Pipeline, Attribute, Application/Db
        // are declared package-layers — they are not "drift". Helpers, Common,
        // Util, Misc are.)
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Helpers',
            'src/Common',
            'src/Util',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('api'));
        foreach (['Helpers', 'Common', 'Util'] as $invented) {
            $this->assertHasViolationAt(
                $violations,
                ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
                'packages/semitexa-api/src/' . $invented,
            );
        }
    }

    // ----------------------- Phase 2: validation depth modes ------------------------

    public function test_container_with_undeclared_helpers_subdirectory_fails(): void
    {
        // ACCEPTANCE (Phase 2): Container is deep_validated with explicit
        // children [BuildPhase, Exception, Store]. Adding Helpers/ underneath
        // it must fail with module_structure.unknown_directory — silent
        // skipping is no longer allowed.
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Container/Helpers',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-core/src/Container/Helpers',
        );
    }

    public function test_container_with_undeclared_misc_subdirectory_fails(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Container/Misc',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-core/src/Container/Misc',
        );
    }

    public function test_container_with_declared_buildphase_subdirectory_passes(): void
    {
        // BuildPhase is declared as a Container child → passes (its own rule
        // is opaque_internal, so contents are explicitly opted out).
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Container/BuildPhase',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Container/BuildPhase/Anything.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_opaque_internal_directory_skips_internal_validation(): void
    {
        // After Phase 4 the spec has zero `opaque_internal` directories,
        // but the MODE remains a supported escape hatch for future
        // architectural areas. Synthesize a spec that uses opaque_internal
        // and confirm the validator still skips its contents.
        $rule = new \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureRule(
            path: 'top_level',
            allowedDirectories: ['Application', 'Frontier'],
        );
        $opaqueRule = new \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureRule(
            path: 'Frontier',
            mode: \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureRule::MODE_OPAQUE_INTERNAL,
            opaqueReason: 'Synthetic test fixture for opaque skip behaviour.',
            opaqueOwner: 'tests',
            opaqueTodo: 'Never — this is a test-only fixture.',
        );
        $spec = new \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureSpec(
            codeRootRules: ['top_level' => $rule, 'Frontier' => $opaqueRule],
            packageRootRule: new \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureRule(
                path: 'package_root',
                allowedDirectories: ['src'],
                allowedFiles: ['composer.json'],
            ),
            filePlacement: [],
            packageOnlyDirectories: [],
            requiredPackageRootEntries: ['composer.json', 'src'],
            packageSpecificCodeRoot: ['frontier' => ['directories' => ['Frontier'], 'files' => []]],
        );

        // Scaffold a package with deeply-nested arbitrary contents inside
        // the opaque Frontier/ — none of it should fire any violation.
        $this->scaffoldPackage('frontier', [
            'src/Frontier/Helpers/Sub/Anything',
        ], ['composer.json']);
        $this->writePackageFile('frontier', 'src/Frontier/Helpers/Sub/Anything/Foo.php', "<?php\n");

        $validator = new ModuleStructureValidator($this->root, $spec);
        $violations = $validator->validate($this->package('frontier'));
        $this->assertEmpty(
            $violations,
            'opaque_internal explicitly opts contents out of validation. Got:' . "\n" . $this->renderViolations($violations),
        );
    }

    public function test_opaque_marker_required_when_per_package_dir_lacks_a_rule(): void
    {
        // If packageSpecificCodeRoot mentions a directory but no
        // codeRootRules entry exists for it, the validator must NOT silently
        // skip — it emits module_structure.opaque_marker_required so the
        // gap is visible. Synthesize the case with a custom spec.
        $rule = new \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureRule(
            path: 'top_level',
            allowedDirectories: ['Application', 'Custom'],
        );
        $spec = new \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureSpec(
            codeRootRules: ['top_level' => $rule],
            packageRootRule: new \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureRule(
                path: 'package_root',
                allowedDirectories: ['src'],
                allowedFiles: ['composer.json'],
            ),
            filePlacement: [],
            packageOnlyDirectories: [],
            requiredPackageRootEntries: ['composer.json', 'src'],
            packageSpecificCodeRoot: ['custom' => ['directories' => ['Custom'], 'files' => []]],
        );
        $this->scaffoldPackage('custom', ['src/Custom'], ['composer.json']);

        $validator = new ModuleStructureValidator($this->root, $spec);
        $violations = $validator->validate($this->package('custom'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_OPAQUE_MARKER_REQUIRED,
            'packages/semitexa-custom/src/Custom',
        );
    }

    public function test_application_services_fails_in_api(): void
    {
        // ACCEPTANCE: Application/Services is not declared → fails.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Application/Services',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Application/Services/Foo.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/Application/Services',
        );
    }

    public function test_application_console_command_canonical_command_passes(): void
    {
        // ACCEPTANCE: FooCommand.php under Application/Console/Command/ passes.
        $this->scaffoldPackage('api', [
            'src/Application/Console/Command',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Application/Console/Command/FooCommand.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_application_console_command_non_command_filename_fails(): void
    {
        // Phase 2 tightening: Application/Console/Command accepts only
        // *Command.php basenames now (allowAnyFile is gone).
        $this->scaffoldPackage('api', [
            'src/Application/Console/Command',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Application/Console/Command/FooHelper.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-api/src/Application/Console/Command/FooHelper.php',
        );
    }

    // ----------------------- Phase 2: Domain layer tightening ------------------------

    public function test_domain_contract_accepts_interface_files(): void
    {
        // ACCEPTANCE: FooRepositoryInterface.php under Domain/Contract passes.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Contract',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Domain/Contract/FooRepositoryInterface.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_domain_contract_rejects_non_interface_files(): void
    {
        // Domain/Contract is for INTERFACES only.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Contract',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Domain/Contract/FooRepository.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-api/src/Domain/Contract/FooRepository.php',
        );
    }

    public function test_domain_repository_directory_itself_fails(): void
    {
        // Phase 3 cleanup: Domain/Repository is REMOVED from the allowlist.
        // Repository interfaces canonically live in Domain/Contract/;
        // concrete implementations live in Application/Db/<Adapter>/Repository/.
        // The audit (2026-04-30) found zero Domain/Repository/ files anywhere
        // in the monorepo — the spec entry was a phantom that created
        // ambiguity for AI agents.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Repository',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Domain/Repository/FooRepositoryInterface.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/Domain/Repository',
        );
    }

    public function test_repository_interface_canonical_location_is_domain_contract(): void
    {
        // The single canonical home for repository interfaces is
        // Domain/Contract/. The same FooRepositoryInterface.php that fails
        // in Domain/Repository (above) passes here.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Contract',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Domain/Contract/FooRepositoryInterface.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_concrete_repository_under_domain_anywhere_fails(): void
    {
        // Concrete *Repository.php (without Interface suffix) is
        // persistence implementation — belongs under
        // Application/Db/<Adapter>/Repository/, never inside Domain/.
        // Domain/Contract is for interfaces only and rejects the file via
        // its allowedFilePatterns; the directory Domain/Repository fails
        // outright as undeclared.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Contract',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Domain/Contract/FooMysqlRepository.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-api/src/Domain/Contract/FooMysqlRepository.php',
        );
    }

    public function test_domain_model_passes_entity_class(): void
    {
        // ACCEPTANCE: Foo.php under Domain/Model passes (entity).
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Model',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Domain/Model/Foo.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_domain_model_rejects_resource_model(): void
    {
        // ACCEPTANCE: FooResourceModel.php under Domain/Model fails — it's
        // persistence, belongs under Application/Db/<Adapter>/Model/.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Model',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Domain/Model/FooResourceModel.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-api/src/Domain/Model/FooResourceModel.php',
        );
    }

    /**
     * @dataProvider persistence_filenames_excluded_from_domain_model
     */
    public function test_domain_model_excluded_filename_patterns_fail(string $persistenceFile): void
    {
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Model',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Domain/Model/' . $persistenceFile, "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-api/src/Domain/Model/' . $persistenceFile,
        );
    }

    /** @return array<string, array{0: string}> */
    public static function persistence_filenames_excluded_from_domain_model(): array
    {
        return [
            'FooResource.php'       => ['FooResource.php'],
            'FooResourceModel.php'  => ['FooResourceModel.php'],
            'FooMapper.php'         => ['FooMapper.php'],
            'CustomerResource.php'  => ['CustomerResource.php'],
        ];
    }

    // ----------------------- Phase 2: Attribute layer + file-placement refinement ------------------------

    public function test_attribute_singular_with_class_files_passes(): void
    {
        // ACCEPTANCE: AsSomething.php under Attribute/ passes.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Attribute',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Attribute/AsSomething.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_attribute_layer_accepts_as_command_attribute_class(): void
    {
        // The *Command.php file-placement rule must NOT fire for an
        // attribute class file inside Attribute/ — neither path-based
        // (the negative-lookahead pattern excludes As-prefix) nor
        // semantic (the local Attribute rule explicitly accepts the file
        // via its allowedFilePattern, which the file-placement check
        // defers to).
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Attribute',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Attribute/AsCommand.php', "<?php\n");
        $this->writePackageFile('api', 'src/Attribute/InjectCommand.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_attribute_layer_rejects_real_business_command_filename(): void
    {
        // A file named `RealBusinessCommand.php` (does not start with As/Inject)
        // inside Attribute/ still trips the file-placement rule because the
        // basename does not match the negative-lookahead exclusion. The user
        // wants this to fail because, by name, it looks like a command class
        // misplaced into Attribute/.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Attribute',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Attribute/RealBusinessCommand.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_COMMAND_WRONG_LOCATION,
            'packages/semitexa-api/src/Attribute/RealBusinessCommand.php',
        );
    }

    public function test_attribute_plural_still_fails(): void
    {
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Attributes',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Attributes/AsSomething.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/Attributes',
        );
    }

    public function test_console_top_level_in_non_core_with_command_file_fails(): void
    {
        // ACCEPTANCE: Console/Command/SomeCommand.php in a non-core package
        // fails — both because top-level Console is core-only AND because
        // commands belong under Application/Console/Command.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Console/Command',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Console/Command/SomeCommand.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationCode($violations, ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY);
        $this->assertHasViolationCode($violations, ModuleStructureViolation::CODE_COMMAND_WRONG_LOCATION);
    }

    public function test_random_command_file_at_module_root_fails(): void
    {
        // ACCEPTANCE: src/SomeRandomCommand.php at the package source root
        // fails. The global file-placement rule fires first
        // (command_wrong_location) because the file's basename matches the
        // *Command.php pattern. Either way, the file fails — strict
        // allowlist surfaces the most actionable code (move it to
        // Application/Console/Command/, not just "this isn't a root file").
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/SomeRandomCommand.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_COMMAND_WRONG_LOCATION,
            'packages/semitexa-api/src/SomeRandomCommand.php',
        );
    }

    public function test_random_non_command_file_at_module_root_fails_with_invalid_root_file(): void
    {
        // For a non-command file (no *Command.php placement match), the
        // root-file rule is what fires.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/SomeHelper.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_ROOT_FILE,
            'packages/semitexa-api/src/SomeHelper.php',
        );
    }

    // ----------------------- Phase 3 batch 1: deep-validated core internals ------------------------

    /**
     * @dataProvider phase3_batch1_directories
     */
    public function test_phase3_batch1_directory_is_deep_validated_not_opaque(string $dir): void
    {
        // Phase 3 batch 1: each of these directories was opaque_internal in
        // Phase 2; this test asserts it has been switched to deep_validated
        // (or leaf_files_only), with explicit allowedFilePatterns, in the
        // executable spec. Future Phase 3 batches will tighten the rest.
        $spec = (new \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureSpecLoader(dirname(__DIR__, 7)))->load();
        $rule = $spec->ruleFor($dir);
        $this->assertNotNull($rule, "Phase 3 directory '{$dir}' must have a rule");
        $this->assertNotSame(
            \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureRule::MODE_OPAQUE_INTERNAL,
            $rule->mode,
            "Phase 3 directory '{$dir}' must NOT be opaque_internal anymore",
        );
    }

    /** @return array<string, array{0: string}> */
    public static function phase3_batch1_directories(): array
    {
        return [
            'Exception'  => ['Exception'],
            'Event'      => ['Event'],
            'Config'     => ['Config'],
            'Redis'      => ['Redis'],
            'Support'    => ['Support'],
            'Validation' => ['Validation'],
            'Http'       => ['Http'],
            'Queue'      => ['Queue'],
        ];
    }

    public function test_exception_leaf_accepts_exception_files_rejects_others(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Exception',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Exception/AccessDeniedException.php', "<?php\n");
        $this->writePackageFile('core', 'src/Exception/NotFoundException.php',     "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_exception_leaf_rejects_non_exception_filename(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Exception',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Exception/SomeService.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Exception/SomeService.php',
        );
    }

    public function test_exception_leaf_rejects_subdirectory(): void
    {
        // MODE_LEAF_FILES_ONLY: any subdirectory under Exception/ fails.
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Exception/Custom',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-core/src/Exception/Custom',
        );
    }

    // -------------------------------------------------------------------
    // Top-level `Exception/` is canonical for ALL packages and ALL
    // application modules — not core-only. The directory holds package-wide
    // exception classes; the leaf rule rejects anything that isn't a
    // *Exception.php file or any sub-directory.
    // -------------------------------------------------------------------

    public function test_top_level_exception_accepted_in_non_core_package(): void
    {
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Exception',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Exception/SearchBackendException.php', "<?php\n");
        $this->writePackageFile('api', 'src/Exception/SearchValidationException.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_top_level_exception_accepted_in_application_module(): void
    {
        $this->scaffoldModule('Hello', [
            'Application/Handler/PayloadHandler',
            'Exception',
        ]);
        $this->writeFile('src/modules/Hello/Exception/HelloFlowException.php', "<?php\n");

        $violations = $this->validator()->validate($this->module('Hello'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_top_level_exception_rejects_factory_filename(): void
    {
        // ExceptionFactory.php does NOT match *Exception.php (it ends in
        // Factory.php). Rejected with invalid_location.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Exception',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Exception/ExceptionFactory.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-api/src/Exception/ExceptionFactory.php',
        );
    }

    public function test_top_level_exception_rejects_helper_filename(): void
    {
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Exception',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Exception/FooHelper.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-api/src/Exception/FooHelper.php',
        );
    }

    public function test_top_level_exception_rejects_service_filename(): void
    {
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Exception',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Exception/FooService.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-api/src/Exception/FooService.php',
        );
    }

    public function test_top_level_exception_rejects_error_handler_filename(): void
    {
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Exception',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Exception/ErrorHandler.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-api/src/Exception/ErrorHandler.php',
        );
    }

    public function test_top_level_exception_rejects_subdir_in_non_core_package(): void
    {
        // Leaf-only: any subdirectory beneath Exception/ fires unknown_directory.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Exception/Subdir',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Exception/Subdir/FooException.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/Exception/Subdir',
        );
    }

    public function test_top_level_exception_rejects_subdir_in_application_module(): void
    {
        $this->scaffoldModule('Hello', [
            'Application/Handler/PayloadHandler',
            'Exception/Subdir',
        ]);
        $this->writeFile('src/modules/Hello/Exception/Subdir/FooException.php', "<?php\n");

        $violations = $this->validator()->validate($this->module('Hello'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'src/modules/Hello/Exception/Subdir',
        );
    }

    // -------------------------------------------------------------------
    // Enums are placed contextually:
    //   - domain semantics → Domain/Enum/
    //   - runtime/orchestration modes → Application/Enum/
    //   - top-level src/Enum/ is intentionally NOT a canonical home
    //
    // Both leaf rules accept PascalCase concept names (the codebase does
    // NOT use a `*Enum` suffix — names are `SearchScope`, `MediaKind`,
    // `HttpStatus`, etc.). Drift filenames (Helper / Util / Manager /
    // Service / Factory / Provider / Adapter / Builder / Resolver) are
    // rejected, sub-directories are rejected.
    // -------------------------------------------------------------------

    public function test_top_level_enum_rejected_in_non_core_package(): void
    {
        // src/Enum is NOT a canonical layer — only Domain/Enum and
        // Application/Enum are.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Enum',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Enum/SomeEnum.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/Enum',
        );
    }

    public function test_top_level_enum_rejected_in_application_module(): void
    {
        $this->scaffoldModule('Hello', [
            'Application/Handler/PayloadHandler',
            'Enum',
        ]);
        $this->writeFile('src/modules/Hello/Enum/SomeEnum.php', "<?php\n");

        $violations = $this->validator()->validate($this->module('Hello'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'src/modules/Hello/Enum',
        );
    }

    public function test_domain_enum_accepts_pascal_case_concept_names(): void
    {
        // Codebase convention: enum names are PascalCase concept names —
        // no `*Enum` suffix. Examples: SearchScope, SearchFieldType,
        // SearchMatchStrategy, MediaKind, HttpStatus.
        $this->scaffoldPackage('search', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Enum',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('search', 'src/Domain/Enum/SearchScope.php', "<?php\n");
        $this->writePackageFile('search', 'src/Domain/Enum/SearchFieldType.php', "<?php\n");
        $this->writePackageFile('search', 'src/Domain/Enum/SearchMatchStrategy.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('search'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_application_enum_accepts_pascal_case_concept_names(): void
    {
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Application/Enum',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Application/Enum/RuntimeMode.php', "<?php\n");
        $this->writePackageFile('api', 'src/Application/Enum/DispatchMode.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_domain_enum_accepts_in_application_module(): void
    {
        $this->scaffoldModule('Hello', [
            'Application/Handler/PayloadHandler',
            'Domain/Enum',
        ]);
        $this->writeFile('src/modules/Hello/Domain/Enum/HelloFlowStatus.php', "<?php\n");

        $violations = $this->validator()->validate($this->module('Hello'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_domain_enum_rejects_service_filename(): void
    {
        $this->scaffoldPackage('search', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Enum',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('search', 'src/Domain/Enum/SearchService.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('search'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-search/src/Domain/Enum/SearchService.php',
        );
    }

    public function test_domain_enum_rejects_helper_filename(): void
    {
        $this->scaffoldPackage('search', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Enum',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('search', 'src/Domain/Enum/SearchHelper.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('search'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-search/src/Domain/Enum/SearchHelper.php',
        );
    }

    public function test_application_enum_rejects_manager_filename(): void
    {
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Application/Enum',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Application/Enum/RuntimeManager.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-api/src/Application/Enum/RuntimeManager.php',
        );
    }

    public function test_application_enum_rejects_factory_filename(): void
    {
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Application/Enum',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Application/Enum/RuntimeFactory.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-api/src/Application/Enum/RuntimeFactory.php',
        );
    }

    public function test_domain_enum_rejects_subdirectory(): void
    {
        // MODE_LEAF_FILES_ONLY: any subdirectory under Domain/Enum/ fails.
        $this->scaffoldPackage('search', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Enum/Customer',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('search'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-search/src/Domain/Enum/Customer',
        );
    }

    public function test_application_enum_rejects_subdirectory(): void
    {
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Application/Enum/Mode',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/Application/Enum/Mode',
        );
    }

    public function test_event_leaf_accepts_pascal_case_files(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Event',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Event/EventDispatcher.php',          "<?php\n");
        $this->writePackageFile('core', 'src/Event/EventListenerRegistry.php',    "<?php\n");
        $this->writePackageFile('core', 'src/Event/HandlerCompleted.php',         "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_validation_only_trait_subdir_is_allowed(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Validation/Trait',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Validation/Trait/EmailValidationTrait.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_validation_undeclared_subdir_fails(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Validation/Helpers',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-core/src/Validation/Helpers',
        );
    }

    public function test_validation_trait_leaf_rejects_non_trait_filename(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Validation/Trait',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Validation/Trait/SomeService.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Validation/Trait/SomeService.php',
        );
    }

    public function test_http_subdirectories_are_strict(): void
    {
        // Http allows root files + Exception/ + Response/ — nothing else.
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Http/Exception',
            'src/Http/Response',
            'src/Http/Custom',  // not declared
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-core/src/Http/Custom',
        );
    }

    public function test_queue_subdirectories_are_strict(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Queue/Message',
            'src/Queue/Transport',
            'src/Queue/Worker',  // not declared
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-core/src/Queue/Worker',
        );
    }

    public function test_queue_message_leaf_rejects_non_message_filename(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Queue/Message',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Queue/Message/SomeService.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Queue/Message/SomeService.php',
        );
    }

    public function test_no_core_only_directory_remains_opaque_after_phase_4(): void
    {
        // Phase 4 final state: every core-only directory has an explicit
        // deep_validated / leaf_files_only rule. The opaque_internal MODE
        // remains in the spec as a supported escape hatch for future
        // architectural areas, but no spec entry currently uses it.
        $spec = (new \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureSpecLoader(dirname(__DIR__, 7)))->load();
        $coreDirs = $spec->packageSpecificDirectories('core');
        $this->assertNotEmpty($coreDirs, 'sanity: core has per-package allowlist');
        foreach ($coreDirs as $dir) {
            $rule = $spec->ruleFor($dir);
            $this->assertNotNull($rule, "core-only directory '{$dir}' must have a rule");
            $this->assertNotSame(
                \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureRule::MODE_OPAQUE_INTERNAL,
                $rule->mode,
                "core-only directory '{$dir}' must NOT be opaque_internal after Phase 4",
            );
        }
    }

    // ----------------------- Phase 3 batch 2: runtime / security / request-lifecycle ------------------------

    /**
     * @dataProvider phase3_batch2_directories
     */
    public function test_phase3_batch2_directory_is_no_longer_opaque(string $dir): void
    {
        $spec = (new \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureSpecLoader(dirname(__DIR__, 7)))->load();
        $rule = $spec->ruleFor($dir);
        $this->assertNotNull($rule, "Phase 3 batch 2 directory '{$dir}' must have a rule");
        $this->assertNotSame(
            \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureRule::MODE_OPAQUE_INTERNAL,
            $rule->mode,
            "Phase 3 batch 2 directory '{$dir}' must NOT be opaque_internal anymore",
        );
        // Each batch-2 rule must declare SOMETHING explicit — either a file
        // pattern or an allowed-children list. (Some dirs like `Request`
        // legitimately have no root files yet — only a child sub-tree.)
        $this->assertTrue(
            $rule->allowedFilePatterns !== [] || $rule->allowedDirectories !== [],
            "Phase 3 batch 2 directory '{$dir}' must declare allowedFilePatterns or allowedDirectories",
        );
    }

    /** @return array<string, array{0: string}> */
    public static function phase3_batch2_directories(): array
    {
        return [
            'Acl'           => ['Acl'],
            'Authorization' => ['Authorization'],
            'Cookie'        => ['Cookie'],
            'Csrf'          => ['Csrf'],
            'Request'       => ['Request'],
            'Session'       => ['Session'],
            'Server'        => ['Server'],
            'Resource'      => ['Resource'],
        ];
    }

    public function test_acl_leaf_accepts_existing_files_rejects_subdir(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Acl',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Acl/PermissionCheckerInterface.php', "<?php\n");
        $this->writePackageFile('core', 'src/Acl/NullPermissionChecker.php',      "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_acl_rejects_subdirectory(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Acl/Helpers',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-core/src/Acl/Helpers',
        );
    }

    public function test_cookie_leaf_requires_cookie_prefix(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Cookie',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Cookie/CookieJar.php',          "<?php\n");
        $this->writePackageFile('core', 'src/Cookie/CookieJarInterface.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_cookie_leaf_rejects_non_cookie_prefix(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Cookie',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Cookie/SomeService.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Cookie/SomeService.php',
        );
    }

    public function test_csrf_deep_only_attribute_subdir_allowed(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Csrf/Attribute',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Csrf/CsrfListener.php',         "<?php\n");
        $this->writePackageFile('core', 'src/Csrf/CsrfToken.php',            "<?php\n");
        $this->writePackageFile('core', 'src/Csrf/Attribute/CsrfExempt.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_csrf_rejects_undeclared_subdir(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Csrf/Helpers',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-core/src/Csrf/Helpers',
        );
    }

    public function test_csrf_root_files_must_have_csrf_prefix(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Csrf',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Csrf/SomeService.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Csrf/SomeService.php',
        );
    }

    public function test_request_only_allows_attribute_subdir(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Request/Attribute',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Request/Attribute/PathParam.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_request_root_files_fail(): void
    {
        // Request currently allows no root files — only Attribute/ child.
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Request',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Request/SomeRequest.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Request/SomeRequest.php',
        );
    }

    public function test_session_deep_with_attribute_and_root_files(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Session/Attribute',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Session/Session.php',                "<?php\n");
        $this->writePackageFile('core', 'src/Session/SessionInterface.php',       "<?php\n");
        $this->writePackageFile('core', 'src/Session/Attribute/SessionSegment.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_server_deep_with_lifecycle_and_root_files(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Server/Lifecycle',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Server/CorsHandler.php',              "<?php\n");
        $this->writePackageFile('core', 'src/Server/SwooleBootstrap.php',          "<?php\n");
        $this->writePackageFile('core', 'src/Server/Lifecycle/ServerBootstrapState.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_server_lifecycle_leaf_requires_server_prefix(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Server/Lifecycle',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Server/Lifecycle/CustomPhase.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Server/Lifecycle/CustomPhase.php',
        );
    }

    public function test_resource_deep_passes_canonical_layout(): void
    {
        // Sanity: scaffold every declared sub-tree of Resource with one
        // canonical-named file each; the whole package must pass.
        $resourceSubdirs = [
            'Attribute'   => 'ResourceObject.php',
            'Cursor'      => 'CollectionCursor.php',
            'Exception'   => 'InvalidCursorException.php',
            'Filter'      => 'CollectionFilterRequest.php',
            'Lifecycle'   => 'WarmResourceMetadataListener.php',
            'Memo'        => 'ResolverMemoStore.php',
            'Metadata'    => 'ResourceMetadataExtractor.php',
            'Pagination'  => 'CollectionPage.php',
            'Sort'        => 'CollectionSortRequest.php',
        ];
        $dirs = ['src/Application/Handler/PayloadHandler'];
        foreach (array_keys($resourceSubdirs) as $sub) {
            $dirs[] = 'src/Resource/' . $sub;
        }
        $this->scaffoldPackage('core', $dirs, ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Resource/JsonResourceRenderer.php', "<?php\n");
        foreach ($resourceSubdirs as $sub => $file) {
            $this->writePackageFile('core', "src/Resource/{$sub}/{$file}", "<?php\n");
        }

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_resource_rejects_undeclared_subdir(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Resource/Helpers',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-core/src/Resource/Helpers',
        );
    }

    public function test_resource_lifecycle_leaf_requires_listener_suffix(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Resource/Lifecycle',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Resource/Lifecycle/SomeService.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Resource/Lifecycle/SomeService.php',
        );
    }

    // ----------------------- Phase 3 batch 1 tightening: Support drift guard ------------------------

    /**
     * @dataProvider support_drift_filenames
     */
    public function test_support_rejects_helper_style_drift(string $driftFile): void
    {
        // Support must NOT become a Helpers/Utils/Common/Misc/Managers
        // dumping ground. excludedFilePatterns enforces this even though
        // PascalCase would otherwise match.
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Support',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Support/' . $driftFile, "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Support/' . $driftFile,
        );
    }

    /** @return array<string, array{0: string}> */
    public static function support_drift_filenames(): array
    {
        return [
            'SomeHelper.php'   => ['SomeHelper.php'],
            'SomeUtil.php'     => ['SomeUtil.php'],
            'SomeUtils.php'    => ['SomeUtils.php'],
            'SomeManager.php'  => ['SomeManager.php'],
            'SomeMisc.php'     => ['SomeMisc.php'],
            'SomeCommon.php'   => ['SomeCommon.php'],
            'SomeManagers.php' => ['SomeManagers.php'],
        ];
    }

    public function test_support_accepts_legitimate_utility_class(): void
    {
        // Concrete single-purpose utilities (no Helper/Util/Manager/Misc/Common
        // suffix) pass.
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Support',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Support/CodeExporter.php', "<?php\n");
        $this->writePackageFile('core', 'src/Support/ProjectRoot.php',  "<?php\n");
        $this->writePackageFile('core', 'src/Support/Str.php',          "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    // ----------------------- Phase 3 batch 3: tooling / framework-internal ------------------------

    /**
     * @dataProvider phase3_batch3_directories
     */
    public function test_phase3_batch3_directory_is_no_longer_opaque(string $dir): void
    {
        $spec = (new \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureSpecLoader(dirname(__DIR__, 7)))->load();
        $rule = $spec->ruleFor($dir);
        $this->assertNotNull($rule, "Phase 3 batch 3 directory '{$dir}' must have a rule");
        $this->assertNotSame(
            \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureRule::MODE_OPAQUE_INTERNAL,
            $rule->mode,
            "Phase 3 batch 3 directory '{$dir}' must NOT be opaque_internal anymore",
        );
        $this->assertTrue(
            $rule->allowedFilePatterns !== [] || $rule->allowedDirectories !== [],
            "Phase 3 batch 3 directory '{$dir}' must declare allowedFilePatterns or allowedDirectories",
        );
    }

    /** @return array<string, array{0: string}> */
    public static function phase3_batch3_directories(): array
    {
        return [
            'CodeGen'   => ['CodeGen'],
            'Composer'  => ['Composer'],
            'PHPStan'   => ['PHPStan'],
            'Lifecycle' => ['Lifecycle'],
            'Registry'  => ['Registry'],
            'Log'       => ['Log'],
            'Error'     => ['Error'],
        ];
    }

    public function test_codegen_leaf_requires_generator_suffix(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/CodeGen',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/CodeGen/LayoutGenerator.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_codegen_leaf_rejects_non_generator_filename(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/CodeGen',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/CodeGen/SomeService.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/CodeGen/SomeService.php',
        );
    }

    public function test_composer_leaf_requires_plugin_suffix(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Composer',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Composer/SemitexaPlugin.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_composer_leaf_rejects_non_plugin_filename(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Composer',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Composer/SomeBootstrap.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Composer/SomeBootstrap.php',
        );
    }

    public function test_phpstan_only_rules_subdir_allowed(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/PHPStan/Rules',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/PHPStan/Rules/InjectionViaConstructorRule.php', "<?php\n");
        $this->writePackageFile('core', 'src/PHPStan/Rules/StaticContainerAccessRule.php',   "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_phpstan_rejects_undeclared_subdir(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/PHPStan/Extensions',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-core/src/PHPStan/Extensions',
        );
    }

    public function test_phpstan_rules_leaf_requires_rule_suffix(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/PHPStan/Rules',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/PHPStan/Rules/SomeHelper.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/PHPStan/Rules/SomeHelper.php',
        );
    }

    public function test_lifecycle_leaf_accepts_phase_and_context_files(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Lifecycle',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Lifecycle/LifecycleComponentRegistry.php', "<?php\n");
        $this->writePackageFile('core', 'src/Lifecycle/RoutePhase.php',                 "<?php\n");
        $this->writePackageFile('core', 'src/Lifecycle/RequestLifecycleContext.php',    "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_lifecycle_rejects_drift_filename(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Lifecycle',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Lifecycle/SomeHelper.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Lifecycle/SomeHelper.php',
        );
    }

    public function test_log_leaf_accepts_logger_and_bridge_files(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Log',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Log/AsyncJsonLogger.php',     "<?php\n");
        $this->writePackageFile('core', 'src/Log/LoggerInterface.php',     "<?php\n");
        $this->writePackageFile('core', 'src/Log/LogLevel.php',            "<?php\n");
        $this->writePackageFile('core', 'src/Log/StaticLoggerBridge.php',  "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_log_rejects_drift_filename(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Log',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Log/SomeManager.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Log/SomeManager.php',
        );
    }

    public function test_error_leaf_requires_error_prefix(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Error',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Error/ErrorDispatchState.php',     "<?php\n");
        $this->writePackageFile('core', 'src/Error/ErrorPageContext.php',       "<?php\n");
        $this->writePackageFile('core', 'src/Error/ErrorRouteDispatcher.php',   "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_error_leaf_rejects_non_error_prefix(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Error',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Error/SomeService.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Error/SomeService.php',
        );
    }

    public function test_registry_rejects_drift_filename(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Registry',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Registry/SomeUtil.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Registry/SomeUtil.php',
        );
    }

    // ----------------------- Tightening: Acl + Authorization drift guards ------------------------

    public function test_acl_rejects_drift_filename(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Acl',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Acl/SomeHelper.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Acl/SomeHelper.php',
        );
    }

    public function test_authorization_rejects_drift_filename(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Authorization',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Authorization/SomeManager.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Authorization/SomeManager.php',
        );
    }

    // ----------------------- Phase 4: final 4 promoted ------------------------

    /**
     * @dataProvider phase4_directories
     */
    public function test_phase4_directory_is_no_longer_opaque(string $dir): void
    {
        $spec = (new \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureSpecLoader(dirname(__DIR__, 7)))->load();
        $rule = $spec->ruleFor($dir);
        $this->assertNotNull($rule, "Phase 4 directory '{$dir}' must have a rule");
        $this->assertNotSame(
            \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureRule::MODE_OPAQUE_INTERNAL,
            $rule->mode,
            "Phase 4 directory '{$dir}' must NOT be opaque_internal anymore",
        );
        $this->assertTrue(
            $rule->allowedFilePatterns !== [] || $rule->allowedDirectories !== [] || $rule->allowedFiles !== [],
            "Phase 4 directory '{$dir}' must declare explicit allowedFilePatterns / allowedDirectories / allowedFiles",
        );
    }

    /** @return array<string, array{0: string}> */
    public static function phase4_directories(): array
    {
        return [
            'Contract' => ['Contract'],
            'Locale'   => ['Locale'],
            'Tenant'   => ['Tenant'],
            'Theme'    => ['Theme'],
        ];
    }

    public function test_framework_contract_accepts_interface_files(): void
    {
        // semitexa-core/src/Contract holds framework-level interfaces.
        // Distinct from Domain/Contract (module-level interfaces).
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Contract',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Contract/TypedHandlerInterface.php',         "<?php\n");
        $this->writePackageFile('core', 'src/Contract/RouteMetadataResolverInterface.php', "<?php\n");
        $this->writePackageFile('core', 'src/Contract/ValidatablePayload.php',             "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_framework_contract_rejects_non_interface_filename(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Contract',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Contract/SomeService.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Contract/SomeService.php',
        );
    }

    public function test_framework_contract_does_not_conflict_with_domain_contract(): void
    {
        // The two layers are at different paths (semitexa-core/src/Contract
        // vs <package>/src/Domain/Contract) and serve different purposes:
        // framework-level vs module-level interfaces. Both rules must
        // coexist cleanly.
        $spec = (new \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureSpecLoader(dirname(__DIR__, 7)))->load();
        $framework = $spec->ruleFor('Contract');
        $domain    = $spec->ruleFor('Domain/Contract');
        $this->assertNotNull($framework, 'framework Contract rule must exist');
        $this->assertNotNull($domain,    'Domain/Contract rule must exist');
        $this->assertNotSame($framework, $domain, 'two distinct rule objects, not aliased');
    }

    public function test_locale_leaf_accepts_context_files_rejects_drift(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Locale',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Locale/LocaleContextInterface.php', "<?php\n");
        $this->writePackageFile('core', 'src/Locale/DefaultLocaleContext.php',   "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_locale_rejects_drift_filename(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Locale',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Locale/SomeHelper.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Locale/SomeHelper.php',
        );
    }

    public function test_tenant_deep_passes_canonical_layout(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Tenant/Layer',
            'src/Tenant/Scope',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Tenant/TenantContextInterface.php',           "<?php\n");
        $this->writePackageFile('core', 'src/Tenant/TenantResolverInterface.php',          "<?php\n");
        $this->writePackageFile('core', 'src/Tenant/Layer/EnvironmentLayer.php',           "<?php\n");
        $this->writePackageFile('core', 'src/Tenant/Layer/EnvironmentValue.php',           "<?php\n");
        $this->writePackageFile('core', 'src/Tenant/Layer/TenantLayerInterface.php',       "<?php\n");
        $this->writePackageFile('core', 'src/Tenant/Scope/NullTenantScope.php',            "<?php\n");
        $this->writePackageFile('core', 'src/Tenant/Scope/TenantScopeInterface.php',       "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_tenant_rejects_undeclared_subdir(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Tenant/Helpers',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-core/src/Tenant/Helpers',
        );
    }

    public function test_tenant_root_rejects_drift_filename(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Tenant',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Tenant/SomeManager.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Tenant/SomeManager.php',
        );
    }

    public function test_tenant_layer_leaf_requires_layer_value_or_interface_suffix(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Tenant/Layer',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Tenant/Layer/SomeService.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Tenant/Layer/SomeService.php',
        );
    }

    public function test_tenant_scope_leaf_requires_scope_in_basename(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Tenant/Scope',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Tenant/Scope/SomeService.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Tenant/Scope/SomeService.php',
        );
    }

    public function test_theme_leaf_requires_theme_prefix(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Theme',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Theme/ThemeProviderInterface.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_theme_leaf_rejects_non_theme_prefix(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Theme',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Theme/SomeFrontend.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Theme/SomeFrontend.php',
        );
    }

    // ----------------------- Phase 5: single canonical Attribute location ------------------------

    public function test_attribute_directory_is_the_canonical_home_for_package_attributes(): void
    {
        // The package's top-level Attribute/ accepts every PHP attribute
        // class — both general API-surface attributes (ApiVersion,
        // ExternalApi) and OpenAPI-flavoured ones (CollectionFilterable,
        // ProducesResourceObject, …). One location, no ambiguity.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Attribute',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Attribute/ApiVersion.php',                "<?php\n");
        $this->writePackageFile('api', 'src/Attribute/ExternalApi.php',               "<?php\n");
        $this->writePackageFile('api', 'src/Attribute/CollectionFilterable.php',      "<?php\n");
        $this->writePackageFile('api', 'src/Attribute/CollectionSortable.php',        "<?php\n");
        $this->writePackageFile('api', 'src/Attribute/ProducesResourceCollection.php', "<?php\n");
        $this->writePackageFile('api', 'src/Attribute/ProducesResourceObject.php',    "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_openapi_attribute_subdirectory_fails(): void
    {
        // Phase 5: there is exactly one canonical location for package PHP
        // attributes (`src/Attribute/`). `src/OpenApi/Attribute/` would
        // create a second competing namespace — the spec rejects it
        // outright, with `module_structure.unknown_directory`.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/OpenApi/Attribute',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/OpenApi/Attribute/CollectionFilterable.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/OpenApi/Attribute',
        );
    }

    public function test_openapi_subdirectories_remain_schema_route_only(): void
    {
        // Phase 5: OpenApi's allowed children are now Schema/ + Route/
        // only (Attribute/ removed). Confirm the spec.
        $spec = (new \Semitexa\Dev\Ai\Verify\Structure\ModuleStructureSpecLoader(dirname(__DIR__, 7)))->load();
        $openApi = $spec->ruleFor('OpenApi');
        $this->assertNotNull($openApi);
        $this->assertSame(
            ['Schema', 'Route'],
            $openApi->allowedDirectories,
            'Phase 5: OpenApi/Attribute removed — canonical attribute home is src/Attribute',
        );
        $this->assertNotContains(
            'Attribute',
            $openApi->allowedDirectories,
            'OpenApi/Attribute would create a second canonical attribute location — forbidden',
        );
    }

    public function test_openapi_schema_and_route_subtrees_still_pass(): void
    {
        // Regression: removing Attribute from OpenApi must NOT have weakened
        // Schema/ or Route/ — both must still be permitted.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/OpenApi/Schema',
            'src/OpenApi/Route',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/OpenApi/OpenApiDocumentBuilder.php',        "<?php\n");
        $this->writePackageFile('api', 'src/OpenApi/Schema/ResourceSchemaGenerator.php', "<?php\n");
        $this->writePackageFile('api', 'src/OpenApi/Route/ResourceRouteSchemaGenerator.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_attribute_singular_remains_canonical(): void
    {
        // Phase 5 must not weaken the existing Attribute-singular rule:
        // src/Attributes (plural) still fails everywhere.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Attributes',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/Attributes',
        );
    }

    // ----------------------- Phase 4 tightening: Event + Config drift guards ------------------------

    public function test_event_rejects_drift_filename(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Event',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Event/SomeHelper.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Event/SomeHelper.php',
        );
    }

    public function test_config_rejects_drift_filename(): void
    {
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Config',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('core', 'src/Config/SomeManager.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-core/src/Config/SomeManager.php',
        );
    }

    // ----------------------- Per-package code root (core-only) ------------------------

    /**
     * @dataProvider core_only_directories
     */
    public function test_core_only_directory_passes_in_semitexa_core(string $coreOnly): void
    {
        // ACCEPTANCE 1, 4, 10: every legitimate semitexa-core top-level
        // directory must pass when used INSIDE semitexa-core.
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/' . $coreOnly,
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, "core-only '{$coreOnly}' must pass in semitexa-core. Got:\n" . $this->renderViolations($violations));
    }

    /**
     * @dataProvider core_only_directories
     */
    public function test_core_only_directory_fails_in_other_package(string $coreOnly): void
    {
        // ACCEPTANCE 11: core-only directories must NOT be allowed in any
        // other production package (semitexa-api, semitexa-orm, etc.).
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/' . $coreOnly,
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/' . $coreOnly,
        );
    }

    /** @return array<string, array{0: string}> */
    public static function core_only_directories(): array
    {
        return [
            'Acl'           => ['Acl'],
            'Authorization' => ['Authorization'],
            'CodeGen'       => ['CodeGen'],
            'Composer'      => ['Composer'],
            'Config'        => ['Config'],
            'Console'       => ['Console'],
            'Container'     => ['Container'],
            'Contract'      => ['Contract'],
            'Cookie'        => ['Cookie'],
            'Csrf'          => ['Csrf'],
            'Error'         => ['Error'],
            'Event'         => ['Event'],
            // 'Exception' is intentionally NOT in this list — it is the
            // canonical home for package-wide exception classes everywhere
            // (see top-level `allowedDirectories` and the dedicated
            // `test_top_level_exception_*` tests above).
            'Http'          => ['Http'],
            'Lifecycle'     => ['Lifecycle'],
            'Locale'        => ['Locale'],
            'Log'           => ['Log'],
            'PHPStan'       => ['PHPStan'],
            'Queue'         => ['Queue'],
            'Redis'         => ['Redis'],
            'Registry'      => ['Registry'],
            'Request'       => ['Request'],
            'Resource'      => ['Resource'],
            'Server'        => ['Server'],
            'Session'       => ['Session'],
            'Support'       => ['Support'],
            'Tenant'        => ['Tenant'],
            'Theme'         => ['Theme'],
            'Validation'    => ['Validation'],
        ];
    }

    public function test_semitexa_core_entry_point_files_pass_at_source_root(): void
    {
        // semitexa-core's PSR-4 entry-point files (Application.php,
        // Environment.php, etc.) live directly at `src/` root and must pass.
        $this->scaffoldPackage('core', [
            'src/Application/Handler/PayloadHandler',
            'src/Container',
        ], ['composer.json', 'LICENSE', 'README.md']);
        foreach (['Application.php', 'Environment.php', 'ErrorHandler.php', 'HttpResponse.php', 'ModuleRegistry.php', 'Request.php', 'RequestFactory.php'] as $file) {
            $this->writePackageFile('core', 'src/' . $file, "<?php\n");
        }

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_files_at_source_root_fail_in_non_core_packages(): void
    {
        // Other packages must NOT have files at their source root — only
        // semitexa-core gets that allowance.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/SomeService.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_ROOT_FILE,
            'packages/semitexa-api/src/SomeService.php',
        );
    }

    public function test_attribute_singular_passes_attributes_plural_fails(): void
    {
        // ACCEPTANCE 4 + 5: singular `Attribute` is canonical; plural
        // `Attributes` is drift and must fail.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Attribute',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $singularViolations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($singularViolations, 'singular Attribute must pass');

        $this->removeDir($this->root);
        mkdir($this->root, 0755, true);

        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Attributes',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $pluralViolations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $pluralViolations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/Attributes',
        );
    }

    public function test_top_level_console_in_non_core_package_fails(): void
    {
        // ACCEPTANCE 9: top-level Console/ in a non-core package fails.
        // Application commands live under Application/Console/Command/.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Console/Command',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Console/Command/SomeCommand.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        // Both codes fire: the bare Console/ is an undeclared layer, AND the
        // command file is at the wrong path.
        $this->assertHasViolationCode($violations, ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY);
        $this->assertHasViolationCode($violations, ModuleStructureViolation::CODE_COMMAND_WRONG_LOCATION);
    }

    public function test_package_only_layer_in_app_module_fires_invalid_layer_for_each_name(): void
    {
        // ACCEPTANCE 12: package-only directories (Attribute, Auth,
        // Discovery, OpenApi, Pipeline) all fail inside src/modules/*.
        foreach (['Attribute', 'Auth', 'Discovery', 'OpenApi', 'Pipeline'] as $packageOnly) {
            $this->removeDir($this->root);
            mkdir($this->root, 0755, true);
            $this->scaffoldModule('Hello', [
                'Application/Handler/PayloadHandler',
                $packageOnly,
            ]);
            $violations = $this->validator()->validate($this->module('Hello'));
            $this->assertHasViolationAt(
                $violations,
                ModuleStructureViolation::CODE_INVALID_LAYER,
                'src/modules/Hello/' . $packageOnly,
            );
        }
    }

    /**
     * @dataProvider invented_helper_directory_names
     */
    public function test_helper_style_directories_fail_in_packages(string $invented): void
    {
        // ACCEPTANCE 6, 7, 8: Helpers/Common/Services/Util — the recurring
        // anti-patterns AI agents invent — all fail.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/' . $invented,
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/' . $invented,
        );
    }

    /** @return array<string, array{0: string}> */
    public static function invented_helper_directory_names(): array
    {
        return [
            'Services'  => ['Services'],
            'Helpers'   => ['Helpers'],
            'Common'    => ['Common'],
            'Util'      => ['Util'],
            'Utils'     => ['Utils'],
            'Lib'       => ['Lib'],
            'Misc'      => ['Misc'],
            'Managers'  => ['Managers'],
        ];
    }

    public function test_canonical_semitexa_core_layout_passes(): void
    {
        // Sanity: a synthetic semitexa-core scaffolded with every one of its
        // declared core-only top-level directories must pass cleanly. This
        // pins the spec.core list against a working reference layout.
        $coreOnlyDirs = array_keys(self::core_only_directories());
        // Strip the data-provider's wrapper-array shape: ['Acl' => ['Acl']]
        // gives string keys we can use directly as directory names.
        $relativeDirs = ['src/Application/Handler/PayloadHandler'];
        foreach ($coreOnlyDirs as $dir) {
            $relativeDirs[] = 'src/' . $dir;
        }
        $this->scaffoldPackage('core', $relativeDirs, ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('core'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    // ----------------------- Application/Db (persistence) ------------------------

    public function test_application_db_mysql_model_repository_passes_canonical_pattern(): void
    {
        // ACCEPTANCE 1-6 (Application/Db round): a package using the canonical
        // persistence-implementation layer must pass cleanly.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Application/Db/MySQL/Model',
            'src/Application/Db/MySQL/Repository',
            'src/Domain/Model',
            'src/Domain/Contract',
        ], ['composer.json', 'LICENSE', 'README.md']);
        // The actual MachineCredential filenames the user listed:
        $this->writePackageFile('api', 'src/Application/Db/MySQL/Model/MachineCredentialResource.php', "<?php\n");
        $this->writePackageFile('api', 'src/Application/Db/MySQL/Model/MachineCredentialResourceModel.php', "<?php\n");
        $this->writePackageFile('api', 'src/Application/Db/MySQL/Model/MachineCredentialMapper.php', "<?php\n");
        $this->writePackageFile('api', 'src/Application/Db/MySQL/Repository/MachineCredentialRepository.php', "<?php\n");
        // Domain remains clean (entity + interface only).
        $this->writePackageFile('api', 'src/Domain/Model/MachineCredential.php', "<?php\n");
        $this->writePackageFile('api', 'src/Domain/Contract/MachineCredentialRepositoryInterface.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    /**
     * @dataProvider supported_orm_adapters
     */
    public function test_every_supported_orm_adapter_passes_with_canonical_filenames(string $adapter): void
    {
        // ACCEPTANCE 1+2: every officially supported adapter must accept the
        // canonical persistence-class filenames (Resource / ResourceModel /
        // Mapper under Model/, Repository under Repository/).
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Application/Db/' . $adapter . '/Model',
            'src/Application/Db/' . $adapter . '/Repository',
            'src/Domain/Model',
            'src/Domain/Contract',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Application/Db/' . $adapter . '/Model/UserResource.php', "<?php\n");
        $this->writePackageFile('api', 'src/Application/Db/' . $adapter . '/Model/UserResourceModel.php', "<?php\n");
        $this->writePackageFile('api', 'src/Application/Db/' . $adapter . '/Model/UserMapper.php', "<?php\n");
        $this->writePackageFile('api', 'src/Application/Db/' . $adapter . '/Repository/UserRepository.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, "adapter '{$adapter}' canonical layout must pass — got:\n" . $this->renderViolations($violations));
    }

    /** @return array<string, array{0: string}> */
    public static function supported_orm_adapters(): array
    {
        // Source of truth: packages/semitexa-orm/src/Adapter/ + the
        // OrmManager driver guard. If a new adapter ships, add it here AND
        // to $persistenceAdapters in packages/semitexa-dev/config/module-structure.php.
        return [
            'MySQL'  => ['MySQL'],
            'SQLite' => ['SQLite'],
        ];
    }

    /**
     * @dataProvider unsupported_db_adapter_names
     */
    public function test_unsupported_db_adapter_fails(string $unsupportedAdapter): void
    {
        // ACCEPTANCE 3: any directory under Application/Db that is not in
        // the official adapter list fails. The strict allowlist refuses to
        // silently accept Postgres, Oracle, MongoDB, MariaDB, Custom, etc.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Application/Db/' . $unsupportedAdapter . '/Model',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/Application/Db/' . $unsupportedAdapter,
        );
    }

    /** @return array<string, array{0: string}> */
    public static function unsupported_db_adapter_names(): array
    {
        return [
            'Postgres'   => ['Postgres'],
            'PostgreSQL' => ['PostgreSQL'],
            'Oracle'     => ['Oracle'],
            'MongoDB'    => ['MongoDB'],
            'MariaDB'    => ['MariaDB'],
            'Custom'     => ['Custom'],
            'Mysql'      => ['Mysql'],   // wrong casing — convention is MySQL
            'Sqlite'     => ['Sqlite'],  // wrong casing — convention is SQLite
        ];
    }

    /**
     * @dataProvider supported_orm_adapters
     */
    public function test_undeclared_subtree_under_supported_adapter_fails(string $adapter): void
    {
        // ACCEPTANCE 4: Application/Db/<Adapter> accepts only Model/ + Repository/.
        // Anything else fails — keeps the persistence layer narrowly scoped.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Application/Db/' . $adapter . '/Migration', // not in the allowlist
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/Application/Db/' . $adapter . '/Migration',
        );
    }

    /**
     * @dataProvider db_model_invalid_filenames
     */
    public function test_db_model_leaf_rejects_non_persistence_filenames(string $adapter, string $invalidFile): void
    {
        // ACCEPTANCE 5: Application/Db/<Adapter>/Model rejects anything that
        // is not *Resource.php / *ResourceModel.php / *Mapper.php. This stops
        // AI agents from dropping ad-hoc Service/Helper/Manager classes into
        // the persistence model layer.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Application/Db/' . $adapter . '/Model',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile(
            'api',
            'src/Application/Db/' . $adapter . '/Model/' . $invalidFile,
            "<?php\n",
        );

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-api/src/Application/Db/' . $adapter . '/Model/' . $invalidFile,
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function db_model_invalid_filenames(): array
    {
        $cases = [];
        foreach (['MySQL', 'SQLite'] as $adapter) {
            foreach (['SomeService.php', 'SomeManager.php', 'SomeHelper.php', 'SomeFactory.php', 'Helper.php', 'PlainClass.php'] as $bad) {
                $cases[$adapter . ': ' . $bad] = [$adapter, $bad];
            }
        }
        return $cases;
    }

    /**
     * @dataProvider db_repository_invalid_filenames
     */
    public function test_db_repository_leaf_rejects_non_repository_filenames(string $adapter, string $invalidFile): void
    {
        // ACCEPTANCE 6: Application/Db/<Adapter>/Repository rejects anything
        // that is not *Repository.php — Factories / Helpers / Services don't
        // belong here.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Application/Db/' . $adapter . '/Repository',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile(
            'api',
            'src/Application/Db/' . $adapter . '/Repository/' . $invalidFile,
            "<?php\n",
        );

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_INVALID_LOCATION,
            'packages/semitexa-api/src/Application/Db/' . $adapter . '/Repository/' . $invalidFile,
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function db_repository_invalid_filenames(): array
    {
        $cases = [];
        foreach (['MySQL', 'SQLite'] as $adapter) {
            foreach (['SomeFactory.php', 'SomeHelper.php', 'SomeService.php', 'SomeMapper.php', 'PlainClass.php'] as $bad) {
                $cases[$adapter . ': ' . $bad] = [$adapter, $bad];
            }
        }
        return $cases;
    }

    public function test_db_model_leaf_accepts_canonical_filenames_in_feature_subgroup(): void
    {
        // Feature subgrouping (Customer/Order/...) is allowed AND the file
        // pattern still applies at any depth — UserResource.php deep inside
        // a feature group is OK; UserService.php deep inside fires.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Application/Db/MySQL/Model/Customer/Order',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Application/Db/MySQL/Model/Customer/Order/OrderResource.php', "<?php\n");
        $this->writePackageFile('api', 'src/Application/Db/MySQL/Model/Customer/Order/OrderMapper.php',   "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_application_db_under_app_module_passes_too(): void
    {
        // Application/Db is part of the canonical Application allowlist for
        // ALL module kinds — application modules under src/modules/ also use
        // it for their persistence implementation.
        $this->scaffoldModule('Hello', [
            'Application/Handler/PayloadHandler',
            'Application/Db/MySQL/Model',
            'Application/Db/MySQL/Repository',
        ]);

        $violations = $this->validator()->validate($this->module('Hello'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    // ----------------------- Domain stays clean of persistence ------------------------

    public function test_repository_interface_in_domain_contract_with_concrete_in_application_db_passes(): void
    {
        // The canonical split: Domain/Contract holds repository INTERFACES;
        // Application/Db/<Adapter>/Repository/ holds concrete implementations.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Contract',
            'src/Application/Db/MySQL/Repository',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Domain/Contract/UserRepositoryInterface.php', "<?php\n");
        $this->writePackageFile('api', 'src/Application/Db/MySQL/Repository/UserRepository.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_domain_model_holds_entities_application_db_holds_resources(): void
    {
        // The split: Domain/Model = entity types; Application/Db = resources +
        // mappers + concrete repos. A canonical package keeps both clean.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Domain/Model',
            'src/Application/Db/MySQL/Model',
        ], ['composer.json', 'LICENSE', 'README.md']);
        $this->writePackageFile('api', 'src/Domain/Model/Customer.php', "<?php\n");
        $this->writePackageFile('api', 'src/Application/Db/MySQL/Model/CustomerResource.php', "<?php\n");
        $this->writePackageFile('api', 'src/Application/Db/MySQL/Model/CustomerResourceModel.php', "<?php\n");
        $this->writePackageFile('api', 'src/Application/Db/MySQL/Model/CustomerMapper.php', "<?php\n");

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    // ----------------------- forbidden-name rule ------------------------

    /**
     * @dataProvider forbidden_demo_style_names
     */
    public function test_forbidden_demo_style_directory_in_package_fires_production_pollution(string $directory): void
    {
        // ACCEPTANCE 1-4 + 9: production packages must NEVER contain
        // demo/sandbox/example/playground code.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/' . $directory,
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('api'));

        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_PRODUCTION_PACKAGE_POLLUTION,
            'packages/semitexa-api/src/' . $directory,
        );
    }

    /** @return array<string, array{0: string}> */
    public static function forbidden_demo_style_names(): array
    {
        return [
            'Demo'         => ['Demo'],
            'Demos'        => ['Demos'],
            'Example'      => ['Example'],
            'Examples'     => ['Examples'],
            'Playground'   => ['Playground'],
            'Sandbox'      => ['Sandbox'],
            'Sample'       => ['Sample'],
            'Samples'      => ['Samples'],
            'TestApp'      => ['TestApp'],
            'Fake'         => ['Fake'],
            'Experimental' => ['Experimental'],
            'Experiment'   => ['Experiment'],
        ];
    }

    public function test_demo_directory_deep_in_package_subtree_still_fires_production_pollution(): void
    {
        // The recursive scan must surface forbidden names ANYWHERE in the
        // package tree — not just at the top.
        $this->scaffoldPackage('api', [
            'src/Application/Service/Demo', // nested under a legit layer
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('api'));

        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_PRODUCTION_PACKAGE_POLLUTION,
            'packages/semitexa-api/src/Application/Service/Demo',
        );
    }

    public function test_production_pollution_violation_message_recommends_src_modules(): void
    {
        // ACCEPTANCE 10: ai:verify must suggest `src/modules/` as the
        // canonical home for local demo modules.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Demo',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $v = $this->violationFor(
            $this->validator()->validate($this->package('api')),
            ModuleStructureViolation::CODE_PRODUCTION_PACKAGE_POLLUTION,
            'packages/semitexa-api/src/Demo',
        );

        $this->assertStringContainsString('src/modules/', $v->expected);
        $this->assertStringContainsString('src/modules/', $v->suggestedFix);
        $this->assertStringContainsString('Move ', $v->suggestedFix);
        // The message is unambiguous about the rule.
        $this->assertStringContainsString('production package', strtolower($v->message));
    }

    public function test_demo_module_under_src_modules_is_not_flagged(): void
    {
        // ACCEPTANCE 5: src/modules/<demo> is the legitimate local sandbox.
        // The pollution rule applies to packages only — application modules
        // under src/modules can be named freely (Demo, Playground, …).
        $this->scaffoldModule('Demo', [
            'Application/Handler/PayloadHandler',
            'Application/Payload/Request',
        ]);
        $this->scaffoldModule('Playground', [
            'Application/Handler/PayloadHandler',
        ]);
        $this->scaffoldModule('Sandbox', [
            'Application/Handler/PayloadHandler',
        ]);

        foreach (['Demo', 'Playground', 'Sandbox'] as $name) {
            $violations = $this->validator()->validate($this->module($name));
            $codes = array_map(static fn(ModuleStructureViolation $v) => $v->code, $violations);
            $this->assertNotContains(
                ModuleStructureViolation::CODE_PRODUCTION_PACKAGE_POLLUTION,
                $codes,
                "src/modules/{$name} must NOT be flagged as production-package pollution",
            );
        }
    }

    public function test_package_tests_directory_is_not_flagged_as_pollution(): void
    {
        // ACCEPTANCE 6: packages/semitexa-api/tests/Demo (test fixtures) is
        // the legitimate location for demo-named test scaffolding. The
        // pollution scan deliberately skips the `tests/` sub-tree.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'tests/Fixtures/Demo',
            'tests/Demo',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('api'));
        $codes = array_map(static fn(ModuleStructureViolation $v) => $v->code, $violations);
        $this->assertNotContains(
            ModuleStructureViolation::CODE_PRODUCTION_PACKAGE_POLLUTION,
            $codes,
            'tests/Demo and tests/Fixtures/Demo must NOT trip the pollution rule',
        );
    }

    public function test_undeclared_top_level_in_package_still_fails(): void
    {
        // ACCEPTANCE 7: non-pollution undeclared folders still fail with
        // unknown_directory (not pollution). Hardens that the new rule has
        // not weakened the strict allowlist.
        $this->scaffoldPackage('api', [
            'src/Application/Handler/PayloadHandler',
            'src/Helpers',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertHasViolationAt(
            $violations,
            ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
            'packages/semitexa-api/src/Helpers',
        );
    }

    public function test_canonical_package_with_declared_framework_layers_passes(): void
    {
        // ACCEPTANCE 8: a valid production package using the declared
        // framework layers (Attribute, Auth, Discovery, OpenApi, Pipeline)
        // passes with zero violations.
        $this->scaffoldPackage('api', [
            'src/Application/Console/Command',
            'src/Application/Handler/PayloadHandler',
            'src/Application/Service',
            'src/Domain/Model',
            'src/Domain/Contract',
            'src/Attribute',
            'src/Auth',
            'src/Discovery',
            'src/OpenApi/Schema',
            'src/OpenApi/Route',
            // Phase 5 cleanup: `OpenApi/Attribute` removed — attribute
            // classes canonically live in src/Attribute, not under OpenApi/.
            'src/Pipeline',
            'tests/Unit',
            'tests/Fixtures',
        ], ['composer.json', 'LICENSE', 'README.md']);

        $violations = $this->validator()->validate($this->package('api'));
        $this->assertEmpty($violations, $this->renderViolations($violations));
    }

    public function test_validator_uses_executable_spec_loader(): void
    {
        // ACCEPTANCE 10: prove the validator follows the spec loader. We do
        // NOT instantiate the validator with hand-built rules in any of the
        // tests above — the helper validator() always loads from the real
        // executable spec at packages/semitexa-dev/config/module-structure.php.
        $loader = new ModuleStructureSpecLoader($this->projectRoot);
        $spec = $loader->load();
        $this->assertNotNull($spec->ruleFor('Application'));
        $this->assertSame(['Command'], $spec->ruleFor('Application/Console')?->allowedDirectories);
    }

    // ----------------------- helpers ------------------------

    private function validator(): ModuleStructureValidator
    {
        return new ModuleStructureValidator(
            $this->root,
            (new ModuleStructureSpecLoader($this->projectRoot))->load(),
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

    private function package(string $name): DetectedModule
    {
        return new DetectedModule(
            name: $name,
            relativePath: 'packages/semitexa-' . $name,
            kind: DetectedModule::KIND_PACKAGE,
        );
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

    /**
     * @param list<string> $relativeDirs
     * @param list<string> $rootFiles
     */
    private function scaffoldPackage(string $name, array $relativeDirs, array $rootFiles): void
    {
        $base = $this->root . '/packages/semitexa-' . $name;
        mkdir($base, 0755, true);
        foreach ($relativeDirs as $rel) {
            mkdir($base . '/' . $rel, 0755, true);
        }
        foreach ($rootFiles as $file) {
            file_put_contents($base . '/' . $file, $file === 'composer.json' ? '{}' : '');
        }
    }

    private function writePackageFile(string $name, string $relativeFile, string $contents): void
    {
        $path = $this->root . '/packages/semitexa-' . $name . '/' . $relativeFile;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $contents);
    }

    private function writeFile(string $relativePath, string $contents): void
    {
        $path = $this->root . '/' . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * @param list<ModuleStructureViolation> $violations
     */
    private function assertHasViolationAt(array $violations, string $code, string $path): void
    {
        foreach ($violations as $v) {
            if ($v->code === $code && $v->path === $path) {
                $this->assertTrue(true);
                return;
            }
        }
        $this->fail(sprintf(
            "Expected violation %s at path '%s'. Got:\n%s",
            $code,
            $path,
            $this->renderViolations($violations),
        ));
    }

    /**
     * @param list<ModuleStructureViolation> $violations
     */
    private function assertHasViolationCode(array $violations, string $code): void
    {
        foreach ($violations as $v) {
            if ($v->code === $code) {
                $this->assertTrue(true);
                return;
            }
        }
        $this->fail(sprintf(
            "Expected at least one violation with code %s. Got:\n%s",
            $code,
            $this->renderViolations($violations),
        ));
    }

    /**
     * @param list<ModuleStructureViolation> $violations
     */
    private function violationFor(array $violations, string $code, string $path): ModuleStructureViolation
    {
        foreach ($violations as $v) {
            if ($v->code === $code && $v->path === $path) {
                return $v;
            }
        }
        $this->fail(sprintf("No violation %s at '%s'.\n%s", $code, $path, $this->renderViolations($violations)));
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

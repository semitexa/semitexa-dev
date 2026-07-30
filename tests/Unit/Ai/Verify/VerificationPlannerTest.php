<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFileClassifier;
use Semitexa\Dev\Application\Service\Ai\Verify\ContractMoveExpansion;
use Semitexa\Dev\Application\Service\Ai\Verify\ContractMoveResolver;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlanner;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;

class VerificationPlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-verify-planner-' . uniqid();
        mkdir($this->root . '/tests', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function test_handler_change_selects_handler_and_di_lints_plus_syntax(): void
    {
        $planner = $this->planner();
        $plan = $planner->plan([
            new ChangedFile('src/modules/Foo/src/Application/Handler/PayloadHandler/GetThingHandler.php', ChangedFile::KIND_HANDLER),
        ], VerificationPlan::SCOPE_STANDARD);

        $this->assertSame(VerificationPlan::SCOPE_STANDARD, $plan->effectiveScope);
        $commands = $this->lintCommandNames($plan);
        sort($commands);
        $this->assertSame(['lint:di', 'lint:handlers'], $commands);
        $this->assertCount(1, $this->targetsOfType($plan, VerificationTarget::TYPE_SYNTAX));
        $this->assertSame([], $plan->expansions);
    }

    public function test_payload_change_adds_responses_lint(): void
    {
        $planner = $this->planner();
        $plan = $planner->plan([
            new ChangedFile('src/modules/Foo/src/Application/Payload/Request/GetThingPayload.php', ChangedFile::KIND_PAYLOAD),
        ], VerificationPlan::SCOPE_STANDARD);

        $commands = $this->lintCommandNames($plan);
        $this->assertContains('lint:responses', $commands);
        $this->assertContains('lint:di', $commands);
    }

    public function test_minimal_scope_runs_syntax_and_structure_but_no_production_lints(): void
    {
        $planner = $this->planner();
        $plan = $planner->plan([
            new ChangedFile('src/modules/Foo/src/Application/Handler/PayloadHandler/X.php', ChangedFile::KIND_HANDLER),
            new ChangedFile('src/modules/Foo/src/Application/Payload/Request/Y.php', ChangedFile::KIND_PAYLOAD),
        ], VerificationPlan::SCOPE_MINIMAL);

        $this->assertSame(VerificationPlan::SCOPE_MINIMAL, $plan->effectiveScope);
        $this->assertCount(2, $this->targetsOfType($plan, VerificationTarget::TYPE_SYNTAX));
        $this->assertSame([], $this->targetsOfType($plan, VerificationTarget::TYPE_LINT));
        $this->assertSame([], $this->targetsOfType($plan, VerificationTarget::TYPE_PHPUNIT));

        // Module structure is non-optional even at minimal scope.
        $this->assertCount(1, $this->targetsOfType($plan, VerificationTarget::TYPE_MODULE_STRUCTURE));
    }

    public function test_repo_wide_minimal_scope_still_runs_phpstan_di(): void
    {
        $planner = $this->planner();
        $plan = $planner->plan([
            new ChangedFile('src/modules/Foo/src/Application/Service/X.php', ChangedFile::KIND_SERVICE),
        ], VerificationPlan::SCOPE_MINIMAL, isRepoWide: true);

        $this->assertSame(VerificationPlan::SCOPE_MINIMAL, $plan->effectiveScope);
        $this->assertCount(1, $this->targetsOfType($plan, VerificationTarget::TYPE_PHPSTAN_DI), 'repo-wide minimal run must include phpstan_di');
    }

    public function test_contract_change_auto_expands_to_broad_with_explanation(): void
    {
        $planner = $this->planner();
        $plan = $planner->plan([
            new ChangedFile('src/modules/Foo/src/Domain/Contract/UserRepoInterface.php', ChangedFile::KIND_CONTRACT),
        ], VerificationPlan::SCOPE_STANDARD);

        $this->assertSame(VerificationPlan::SCOPE_BROAD, $plan->effectiveScope);
        $this->assertCount(1, $plan->expansions);
        $this->assertStringContainsString('contract changed', $plan->expansions[0]);
        $commands = $this->lintCommandNames($plan);
        sort($commands);
        $this->assertSame([
            'lint:di',
            'lint:handlers',
            // Broad scope runs every lint, including the mechanism check that
            // reports application code hand-rolling a framework capability.
            'lint:mechanisms',
            'lint:responses',
            'lint:scoping',
            'lint:templates',
        ], $commands);
    }

    public function test_high_file_count_auto_expands_to_broad(): void
    {
        $files = [];
        for ($i = 0; $i < 16; $i++) {
            $files[] = new ChangedFile("src/modules/Foo/src/Domain/Service/S{$i}.php", ChangedFile::KIND_SERVICE);
        }
        $plan = $this->planner()->plan($files, VerificationPlan::SCOPE_STANDARD);

        $this->assertSame(VerificationPlan::SCOPE_BROAD, $plan->effectiveScope);
        $this->assertNotEmpty($plan->expansions);
        $this->assertStringContainsString('16 files changed', $plan->expansions[0]);
    }

    public function test_test_file_change_schedules_phpunit_target_for_that_test_only(): void
    {
        $planner = $this->planner();
        $plan = $planner->plan([
            new ChangedFile('tests/Unit/Foo/SomethingTest.php', ChangedFile::KIND_TEST),
        ], VerificationPlan::SCOPE_STANDARD);

        $phpunit = $this->targetsOfType($plan, VerificationTarget::TYPE_PHPUNIT);
        $this->assertCount(1, $phpunit);
        $this->assertSame('SomethingTest', $phpunit[0]->testFilter);
        $this->assertSame('tests/Unit/Foo/SomethingTest.php', $phpunit[0]->filePath);
    }

    public function test_source_file_change_finds_matching_test_by_name(): void
    {
        mkdir($this->root . '/tests/Unit/Foo', 0755, true);
        file_put_contents($this->root . '/tests/Unit/Foo/GetThingHandlerTest.php', "<?php\nclass GetThingHandlerTest {}\n");

        $plan = $this->planner()->plan([
            new ChangedFile('src/modules/Foo/src/Application/Handler/PayloadHandler/GetThingHandler.php', ChangedFile::KIND_HANDLER),
        ], VerificationPlan::SCOPE_STANDARD);

        $phpunit = $this->targetsOfType($plan, VerificationTarget::TYPE_PHPUNIT);
        $this->assertCount(1, $phpunit);
        $this->assertSame('GetThingHandlerTest', $phpunit[0]->testFilter);
        $this->assertSame('tests/Unit/Foo/GetThingHandlerTest.php', $phpunit[0]->filePath);
    }

    public function test_deleted_files_are_kept_in_plan_but_skip_execution(): void
    {
        $plan = $this->planner()->plan([
            new ChangedFile('src/modules/Foo/src/Application/Handler/PayloadHandler/Gone.php', ChangedFile::KIND_HANDLER, ChangedFile::STATUS_DELETED),
        ], VerificationPlan::SCOPE_STANDARD);

        $this->assertCount(1, $plan->changedFiles);
        // Deletion alone produces no syntax/lint/phpunit targets — the
        // file is gone and there is nothing to lint. Module structure
        // is the exception: removing the last file from a module may
        // still leave the module in a structurally invalid state, so
        // the structure target is still scheduled.
        $this->assertSame([], $this->targetsOfType($plan, VerificationTarget::TYPE_SYNTAX));
        $this->assertSame([], $this->targetsOfType($plan, VerificationTarget::TYPE_LINT));
        $this->assertSame([], $this->targetsOfType($plan, VerificationTarget::TYPE_PHPUNIT));
    }

    public function test_dedupes_lint_targets_when_multiple_files_share_a_kind(): void
    {
        $plan = $this->planner()->plan([
            new ChangedFile('src/modules/Foo/src/Application/Handler/PayloadHandler/A.php', ChangedFile::KIND_HANDLER),
            new ChangedFile('src/modules/Foo/src/Application/Handler/PayloadHandler/B.php', ChangedFile::KIND_HANDLER),
        ], VerificationPlan::SCOPE_STANDARD);

        $commands = $this->lintCommandNames($plan);
        $this->assertSame(count($commands), count(array_unique($commands)));
        // Both files appear in triggeredBy.
        foreach ($this->targetsOfType($plan, VerificationTarget::TYPE_LINT) as $t) {
            $this->assertCount(2, $t->triggeredBy);
        }
    }

    public function test_test_fixture_change_does_not_invoke_phpunit_directly_on_fixture(): void
    {
        // Recording the regression that motivated Phase 6f.5: a
        // fixture under tests/Unit/Resource/Fixtures/ must not be
        // scheduled as `phpunit:RecordingAddressesResolver`, because
        // the class does not extend TestCase and phpunit would emit
        // "does not extend PHPUnit\Framework\TestCase".
        mkdir($this->root . '/packages/semitexa-core/tests/Unit/Resource/Fixtures', 0755, true);
        // No real test files at all → no phpunit targets at all.

        $plan = $this->planner()->plan([
            new ChangedFile(
                'packages/semitexa-core/tests/Unit/Resource/Fixtures/RecordingAddressesResolver.php',
                ChangedFile::KIND_TEST_FIXTURE,
            ),
        ], VerificationPlan::SCOPE_STANDARD);

        $phpunit = $this->targetsOfType($plan, VerificationTarget::TYPE_PHPUNIT);
        // No real test files set up under the synthetic root, so the
        // fixture path produces zero phpunit targets — but the key
        // invariant is that none of them target the fixture itself.
        $this->assertSame([], $phpunit);
        // Syntax check still runs for the changed file.
        $this->assertCount(1, $this->targetsOfType($plan, VerificationTarget::TYPE_SYNTAX));
    }

    public function test_test_fixture_change_schedules_enclosing_test_suite_directory(): void
    {
        // Build a tiny tree mirroring the regression case:
        //   tests/Unit/Resource/Fixtures/RecordingAddressesResolver.php  (fixture)
        //   tests/Unit/Resource/Phase6eListResolverTest.php              (real test)
        //   tests/Unit/Resource/Phase6fListParentBatchingTest.php        (real test)
        $resourceDir = $this->root . '/packages/semitexa-core/tests/Unit/Resource';
        mkdir($resourceDir . '/Fixtures', 0755, true);
        file_put_contents($resourceDir . '/Phase6eListResolverTest.php', "<?php\nclass Phase6eListResolverTest {}\n");
        file_put_contents($resourceDir . '/Phase6fListParentBatchingTest.php', "<?php\nclass Phase6fListParentBatchingTest {}\n");

        $plan = $this->planner()->plan([
            new ChangedFile(
                'packages/semitexa-core/tests/Unit/Resource/Fixtures/RecordingAddressesResolver.php',
                ChangedFile::KIND_TEST_FIXTURE,
            ),
        ], VerificationPlan::SCOPE_STANDARD);

        $phpunit = $this->targetsOfType($plan, VerificationTarget::TYPE_PHPUNIT);

        // ONE directory-scoped target, not N per-file ones. Running
        // phpunit on the directory loads every Test.php in one
        // process, which both halves the noise and resolves cross-
        // file helper-class declarations sibling tests rely on.
        $this->assertCount(1, $phpunit);
        $this->assertNull($phpunit[0]->testFilter, 'fixture suite target runs without --filter');
        $this->assertSame(
            'packages/semitexa-core/tests/Unit/Resource',
            $phpunit[0]->filePath,
            'fixture suite target points at the enclosing test directory, not the fixture file',
        );
        $this->assertStringContainsString('test fixture changed', $phpunit[0]->reason);
        $this->assertSame(
            ['packages/semitexa-core/tests/Unit/Resource/Fixtures/RecordingAddressesResolver.php'],
            $phpunit[0]->triggeredBy,
        );
    }

    public function test_test_fixture_change_walks_up_when_immediate_parent_has_no_tests(): void
    {
        // Fixture is two levels deep below the closest test family.
        $resourceDir = $this->root . '/packages/semitexa-core/tests/Unit/Resource';
        mkdir($resourceDir . '/Fixtures/Nested', 0755, true);
        file_put_contents($resourceDir . '/SomethingTest.php', "<?php\nclass SomethingTest {}\n");

        $plan = $this->planner()->plan([
            new ChangedFile(
                'packages/semitexa-core/tests/Unit/Resource/Fixtures/Nested/Helper.php',
                ChangedFile::KIND_TEST_FIXTURE,
            ),
        ], VerificationPlan::SCOPE_STANDARD);

        $phpunit = $this->targetsOfType($plan, VerificationTarget::TYPE_PHPUNIT);
        $this->assertCount(1, $phpunit);
        $this->assertNull($phpunit[0]->testFilter);
        $this->assertSame('packages/semitexa-core/tests/Unit/Resource', $phpunit[0]->filePath);
    }

    public function test_two_fixtures_in_same_subtree_dedupe_to_single_target(): void
    {
        $resourceDir = $this->root . '/packages/semitexa-core/tests/Unit/Resource';
        mkdir($resourceDir . '/Fixtures', 0755, true);
        file_put_contents($resourceDir . '/SomethingTest.php', "<?php\nclass SomethingTest {}\n");

        $plan = $this->planner()->plan([
            new ChangedFile(
                'packages/semitexa-core/tests/Unit/Resource/Fixtures/A.php',
                ChangedFile::KIND_TEST_FIXTURE,
            ),
            new ChangedFile(
                'packages/semitexa-core/tests/Unit/Resource/Fixtures/B.php',
                ChangedFile::KIND_TEST_FIXTURE,
            ),
        ], VerificationPlan::SCOPE_STANDARD);

        $phpunit = $this->targetsOfType($plan, VerificationTarget::TYPE_PHPUNIT);
        $this->assertCount(1, $phpunit, 'two fixtures sharing a suite collapse to one phpunit target');
        $this->assertCount(2, $phpunit[0]->triggeredBy);
    }

    public function test_test_fixture_change_schedules_no_phpunit_target_when_no_enclosing_tests_exist(): void
    {
        // Stand-alone fixture tree with no real test files anywhere
        // up the chain → no phpunit target. Syntax check still runs.
        mkdir($this->root . '/packages/semitexa-core/tests/Unit/Resource/Fixtures', 0755, true);

        $plan = $this->planner()->plan([
            new ChangedFile(
                'packages/semitexa-core/tests/Unit/Resource/Fixtures/Lonely.php',
                ChangedFile::KIND_TEST_FIXTURE,
            ),
        ], VerificationPlan::SCOPE_STANDARD);

        $this->assertSame([], $this->targetsOfType($plan, VerificationTarget::TYPE_PHPUNIT));
        $this->assertCount(1, $this->targetsOfType($plan, VerificationTarget::TYPE_SYNTAX));
    }

    public function test_real_test_file_is_still_invoked_directly_after_fixture_split(): void
    {
        // Defends the existing behaviour for KIND_TEST: a real
        // FooTest.php still produces a phpunit target keyed by class
        // name with reason "test file changed — running it directly".
        $plan = $this->planner()->plan([
            new ChangedFile('tests/Unit/Foo/SomethingTest.php', ChangedFile::KIND_TEST),
        ], VerificationPlan::SCOPE_STANDARD);

        $phpunit = $this->targetsOfType($plan, VerificationTarget::TYPE_PHPUNIT);
        $this->assertCount(1, $phpunit);
        $this->assertSame('SomethingTest', $phpunit[0]->testFilter);
        $this->assertSame('test file changed — running it directly', $phpunit[0]->reason);
    }

    public function test_test_fixture_change_does_not_emit_lint_targets(): void
    {
        // Fixtures are pure test scaffolding — no production lint
        // (lint:di, lint:handlers, …) applies to them.
        $plan = $this->planner()->plan([
            new ChangedFile(
                'packages/semitexa-core/tests/Unit/Resource/Fixtures/X.php',
                ChangedFile::KIND_TEST_FIXTURE,
            ),
        ], VerificationPlan::SCOPE_STANDARD);

        $this->assertSame([], $this->lintCommandNames($plan));
    }

    public function test_changed_php_files_schedule_one_phpstan_di_target_at_standard_scope(): void
    {
        // Single-file invocation (`ai:verify --files=<one .php file>`) must
        // schedule a `phpstan_di` target so an AI agent that drops a
        // ctor-injected command into a package gets the
        // `semitexa.injectionViaConstructor` PHPStan error reported even when
        // the project's project-wide `composer phpstan` is not run.
        $plan = $this->planner()->plan([
            new ChangedFile(
                'packages/semitexa-api/src/Application/Console/Command/DumpOpenApiCommand.php',
                ChangedFile::KIND_PHP_OTHER,
            ),
        ], VerificationPlan::SCOPE_STANDARD);

        $diTargets = $this->targetsOfType($plan, VerificationTarget::TYPE_PHPSTAN_DI);
        $this->assertCount(1, $diTargets, 'one batched phpstan_di target per verify run');
        $this->assertSame(
            ['packages/semitexa-api/src/Application/Console/Command/DumpOpenApiCommand.php'],
            $diTargets[0]->triggeredBy,
        );
    }

    public function test_multiple_changed_php_files_share_a_single_phpstan_di_target(): void
    {
        $plan = $this->planner()->plan([
            new ChangedFile('src/modules/Foo/src/Application/Service/X.php', ChangedFile::KIND_SERVICE),
            new ChangedFile('src/modules/Foo/src/Application/Handler/PayloadHandler/Y.php', ChangedFile::KIND_HANDLER),
            new ChangedFile('packages/semitexa-api/src/Application/Console/Command/Z.php', ChangedFile::KIND_PHP_OTHER),
        ], VerificationPlan::SCOPE_STANDARD);

        $diTargets = $this->targetsOfType($plan, VerificationTarget::TYPE_PHPSTAN_DI);
        $this->assertCount(1, $diTargets);
        $this->assertCount(3, $diTargets[0]->triggeredBy);
    }

    public function test_phpstan_di_target_skipped_for_test_fixtures_and_templates(): void
    {
        $plan = $this->planner()->plan([
            new ChangedFile('packages/semitexa-core/tests/Unit/X.php', ChangedFile::KIND_TEST),
            new ChangedFile('packages/semitexa-core/tests/Unit/Fixtures/F.php', ChangedFile::KIND_TEST_FIXTURE),
            new ChangedFile('src/modules/Foo/src/Application/View/templates/pages/x.html.twig', ChangedFile::KIND_TEMPLATE),
            new ChangedFile('docker/etc/nginx.conf', ChangedFile::KIND_NON_PHP),
        ], VerificationPlan::SCOPE_STANDARD);

        $this->assertSame([], $this->targetsOfType($plan, VerificationTarget::TYPE_PHPSTAN_DI));
    }

    public function test_phpstan_di_target_skipped_at_minimal_scope(): void
    {
        $plan = $this->planner()->plan([
            new ChangedFile('src/modules/Foo/src/Application/Service/X.php', ChangedFile::KIND_SERVICE),
        ], VerificationPlan::SCOPE_MINIMAL);

        $this->assertSame([], $this->targetsOfType($plan, VerificationTarget::TYPE_PHPSTAN_DI));
    }

    public function test_unknown_scope_falls_back_to_standard(): void
    {
        $plan = $this->planner()->plan([
            new ChangedFile('src/modules/Foo/src/Application/Handler/PayloadHandler/X.php', ChangedFile::KIND_HANDLER),
        ], 'gibberish');

        $this->assertSame('gibberish', $plan->scope);
        $this->assertSame(VerificationPlan::SCOPE_STANDARD, $plan->effectiveScope);
    }

    public function test_removed_contract_expands_phpstan_di_input_with_dependents(): void
    {
        $resolver = $this->stubContractMoveResolver([
            new ContractMoveExpansion(
                contractFqcn:   'Semitexa\\Foo\\Domain\\Contract\\Removed',
                contractFile:   'packages/semitexa-foo/src/Domain/Contract/Removed.php',
                changeStatus:   ChangedFile::STATUS_DELETED,
                dependentFiles: [
                    'packages/semitexa-bar/src/Application/Service/Consumer.php',
                    'src/modules/Hello/src/Application/Handler/PayloadHandler/H.php',
                ],
            ),
        ]);

        $plan = $this->planner($resolver)->plan([
            new ChangedFile(
                'packages/semitexa-foo/src/Domain/Contract/Removed.php',
                ChangedFile::KIND_CONTRACT,
                ChangedFile::STATUS_DELETED,
            ),
        ], VerificationPlan::SCOPE_STANDARD);

        $phpstan = $this->targetsOfType($plan, VerificationTarget::TYPE_PHPSTAN_DI);
        $this->assertCount(1, $phpstan, 'Layer 1 must schedule one phpstan_di target even when only deleted files changed');
        $this->assertEqualsCanonicalizing(
            [
                'packages/semitexa-bar/src/Application/Service/Consumer.php',
                'src/modules/Hello/src/Application/Handler/PayloadHandler/H.php',
            ],
            $phpstan[0]->triggeredBy,
            'orphan consumers must be folded into the phpstan_di scan input',
        );
        $this->assertStringContainsString('broken-FQCN guard', $phpstan[0]->reason);

        $this->assertNotEmpty($plan->expansions);
        $this->assertStringContainsString(
            'Semitexa\\Foo\\Domain\\Contract\\Removed',
            implode("\n", $plan->expansions),
        );
        $this->assertStringContainsString('removed', implode("\n", $plan->expansions));
    }

    public function test_renamed_contract_expands_phpstan_di_input(): void
    {
        $resolver = $this->stubContractMoveResolver([
            new ContractMoveExpansion(
                contractFqcn:   'Semitexa\\Foo\\Contract\\Old',
                contractFile:   'packages/semitexa-foo/src/Contract/Old.php',
                changeStatus:   ChangedFile::STATUS_RENAMED,
                dependentFiles: ['packages/semitexa-bar/src/Service/Consumer.php'],
            ),
        ]);

        $plan = $this->planner($resolver)->plan([
            new ChangedFile(
                'packages/semitexa-foo/src/Domain/Contract/New.php',
                ChangedFile::KIND_CONTRACT,
                ChangedFile::STATUS_RENAMED,
            ),
        ], VerificationPlan::SCOPE_STANDARD);

        $phpstan = $this->targetsOfType($plan, VerificationTarget::TYPE_PHPSTAN_DI);
        $this->assertCount(1, $phpstan);
        $this->assertContains(
            'packages/semitexa-bar/src/Service/Consumer.php',
            $phpstan[0]->triggeredBy,
        );
        $this->assertStringContainsString('renamed', implode("\n", $plan->expansions));
    }

    public function test_unrelated_change_set_produces_no_contract_move_expansion(): void
    {
        // Resolver returns empty: critical for false-positive control. ai:verify
        // is shared infra; a spurious expansion would break every package's
        // verify with unrelated phpstan_di scans.
        $resolver = $this->stubContractMoveResolver([]);

        $plan = $this->planner($resolver)->plan([
            new ChangedFile(
                'src/modules/Foo/src/Application/Service/Unrelated.php',
                ChangedFile::KIND_SERVICE,
                ChangedFile::STATUS_MODIFIED,
            ),
        ], VerificationPlan::SCOPE_STANDARD);

        // No expansions from the contract-move resolver.
        $contractMoveExpansions = array_filter(
            $plan->expansions,
            static fn(string $note) => str_contains($note, 'broken-FQCN') || str_contains($note, 'dependent file'),
        );
        $this->assertSame([], array_values($contractMoveExpansions));

        // phpstan_di still scopes to just the modified file — no orphans appended.
        $phpstan = $this->targetsOfType($plan, VerificationTarget::TYPE_PHPSTAN_DI);
        $this->assertCount(1, $phpstan);
        $this->assertSame(
            ['src/modules/Foo/src/Application/Service/Unrelated.php'],
            $phpstan[0]->triggeredBy,
        );
    }

    public function test_dependent_file_already_in_change_set_is_not_duplicated(): void
    {
        $resolver = $this->stubContractMoveResolver([
            new ContractMoveExpansion(
                contractFqcn:   'Semitexa\\Foo\\Contract\\Removed',
                contractFile:   'packages/semitexa-foo/src/Contract/Removed.php',
                changeStatus:   ChangedFile::STATUS_DELETED,
                dependentFiles: [
                    'packages/semitexa-bar/src/Service/AlreadyChanged.php',
                ],
            ),
        ]);

        $plan = $this->planner($resolver)->plan([
            new ChangedFile(
                'packages/semitexa-foo/src/Contract/Removed.php',
                ChangedFile::KIND_CONTRACT,
                ChangedFile::STATUS_DELETED,
            ),
            new ChangedFile(
                'packages/semitexa-bar/src/Service/AlreadyChanged.php',
                ChangedFile::KIND_SERVICE,
                ChangedFile::STATUS_MODIFIED,
            ),
        ], VerificationPlan::SCOPE_STANDARD);

        $phpstan = $this->targetsOfType($plan, VerificationTarget::TYPE_PHPSTAN_DI);
        $this->assertCount(1, $phpstan);
        $triggers = $phpstan[0]->triggeredBy;
        $this->assertCount(
            1,
            array_filter($triggers, static fn(string $p) => $p === 'packages/semitexa-bar/src/Service/AlreadyChanged.php'),
            'a file already in the changed-file set must not be re-added by the resolver expansion',
        );
    }

    public function test_package_php_change_plans_the_capability_index_gate(): void
    {
        // The index is a snapshot of what every package declares, so any package
        // PHP file is a candidate for having added or removed a #[Capability].
        // Narrowing to "files that look like a Capabilities class" would miss
        // the mechanism shape, which lives on arbitrary attribute classes.
        $plan = $this->planner()->plan([
            new ChangedFile('packages/semitexa-ssr/src/Attribute/AsDeferred.php', ChangedFile::KIND_PHP_OTHER),
        ], VerificationPlan::SCOPE_MINIMAL);

        $this->assertCount(1, $this->targetsOfType($plan, VerificationTarget::TYPE_CAPABILITY_INDEX));
    }

    public function test_hand_editing_the_shipped_index_plans_its_own_gate(): void
    {
        // Editing the artifact directly is the drift the content hash exists to
        // catch, and a JSON file would otherwise trigger nothing at all.
        $plan = $this->planner()->plan([
            new ChangedFile('packages/semitexa-dev/resources/capability-index.json', ChangedFile::KIND_NON_PHP),
        ], VerificationPlan::SCOPE_MINIMAL);

        $this->assertCount(1, $this->targetsOfType($plan, VerificationTarget::TYPE_CAPABILITY_INDEX));
    }

    public function test_application_code_does_not_plan_the_capability_index_gate(): void
    {
        // A consumer project has no packages/ directory and cannot regenerate
        // the index. Planning the gate for their files would fail a verify over
        // an artifact they do not own — so the trigger is package code only.
        $plan = $this->planner()->plan([
            new ChangedFile('src/modules/Foo/src/Application/Handler/PayloadHandler/X.php', ChangedFile::KIND_HANDLER),
        ], VerificationPlan::SCOPE_BROAD);

        $this->assertSame([], $this->targetsOfType($plan, VerificationTarget::TYPE_CAPABILITY_INDEX));
    }

    private function planner(?ContractMoveResolver $contractMoveResolver = null): VerificationPlanner
    {
        return new VerificationPlanner(
            $this->root,
            new ChangedFileClassifier(),
            null,
            $contractMoveResolver ?? $this->stubContractMoveResolver([]),
        );
    }

    /**
     * @param list<ContractMoveExpansion> $expansions
     */
    private function stubContractMoveResolver(array $expansions): ContractMoveResolver
    {
        return new class($this->root, $expansions) extends ContractMoveResolver {
            /** @param list<ContractMoveExpansion> $expansions */
            public function __construct(string $root, private readonly array $expansions)
            {
                parent::__construct($root);
            }

            public function resolve(array $files): array
            {
                return $this->expansions;
            }
        };
    }

    /**
     * @return list<string>
     */
    private function lintCommandNames(VerificationPlan $plan): array
    {
        return array_values(array_map(
            static fn(VerificationTarget $t) => (string) $t->commandName,
            $this->targetsOfType($plan, VerificationTarget::TYPE_LINT),
        ));
    }

    /**
     * @return list<VerificationTarget>
     */
    private function targetsOfType(VerificationPlan $plan, string $type): array
    {
        return array_values(array_filter(
            $plan->targets,
            static fn(VerificationTarget $t) => $t->type === $type,
        ));
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

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Ai\Verify\ChangedFile;
use Semitexa\Dev\Ai\Verify\ChangedFileClassifier;
use Semitexa\Dev\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Ai\Verify\VerificationPlanner;
use Semitexa\Dev\Ai\Verify\VerificationTarget;

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
            new ChangedFile('src/modules/Foo/Application/Handler/PayloadHandler/GetThingHandler.php', ChangedFile::KIND_HANDLER),
        ], VerificationPlan::SCOPE_STANDARD);

        $this->assertSame(VerificationPlan::SCOPE_STANDARD, $plan->effectiveScope);
        $commands = $this->lintCommandNames($plan);
        sort($commands);
        $this->assertSame(['semitexa:lint:di', 'semitexa:lint:handlers'], $commands);
        $this->assertCount(1, $this->targetsOfType($plan, VerificationTarget::TYPE_SYNTAX));
        $this->assertSame([], $plan->expansions);
    }

    public function test_payload_change_adds_responses_lint(): void
    {
        $planner = $this->planner();
        $plan = $planner->plan([
            new ChangedFile('src/modules/Foo/Application/Payload/Request/GetThingPayload.php', ChangedFile::KIND_PAYLOAD),
        ], VerificationPlan::SCOPE_STANDARD);

        $commands = $this->lintCommandNames($plan);
        $this->assertContains('semitexa:lint:responses', $commands);
        $this->assertContains('semitexa:lint:di', $commands);
    }

    public function test_minimal_scope_runs_only_syntax(): void
    {
        $planner = $this->planner();
        $plan = $planner->plan([
            new ChangedFile('src/modules/Foo/Application/Handler/PayloadHandler/X.php', ChangedFile::KIND_HANDLER),
            new ChangedFile('src/modules/Foo/Application/Payload/Request/Y.php', ChangedFile::KIND_PAYLOAD),
        ], VerificationPlan::SCOPE_MINIMAL);

        $this->assertSame(VerificationPlan::SCOPE_MINIMAL, $plan->effectiveScope);
        $this->assertCount(2, $this->targetsOfType($plan, VerificationTarget::TYPE_SYNTAX));
        $this->assertSame([], $this->targetsOfType($plan, VerificationTarget::TYPE_LINT));
        $this->assertSame([], $this->targetsOfType($plan, VerificationTarget::TYPE_PHPUNIT));
    }

    public function test_contract_change_auto_expands_to_broad_with_explanation(): void
    {
        $planner = $this->planner();
        $plan = $planner->plan([
            new ChangedFile('src/modules/Foo/Domain/Contract/UserRepoInterface.php', ChangedFile::KIND_CONTRACT),
        ], VerificationPlan::SCOPE_STANDARD);

        $this->assertSame(VerificationPlan::SCOPE_BROAD, $plan->effectiveScope);
        $this->assertCount(1, $plan->expansions);
        $this->assertStringContainsString('contract changed', $plan->expansions[0]);
        $commands = $this->lintCommandNames($plan);
        sort($commands);
        $this->assertSame([
            'semitexa:lint:di',
            'semitexa:lint:handlers',
            'semitexa:lint:responses',
            'semitexa:lint:scoping',
            'semitexa:lint:templates',
        ], $commands);
    }

    public function test_high_file_count_auto_expands_to_broad(): void
    {
        $files = [];
        for ($i = 0; $i < 16; $i++) {
            $files[] = new ChangedFile("src/modules/Foo/Domain/Service/S{$i}.php", ChangedFile::KIND_SERVICE);
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
            new ChangedFile('src/modules/Foo/Application/Handler/PayloadHandler/GetThingHandler.php', ChangedFile::KIND_HANDLER),
        ], VerificationPlan::SCOPE_STANDARD);

        $phpunit = $this->targetsOfType($plan, VerificationTarget::TYPE_PHPUNIT);
        $this->assertCount(1, $phpunit);
        $this->assertSame('GetThingHandlerTest', $phpunit[0]->testFilter);
        $this->assertSame('tests/Unit/Foo/GetThingHandlerTest.php', $phpunit[0]->filePath);
    }

    public function test_deleted_files_are_kept_in_plan_but_skip_execution(): void
    {
        $plan = $this->planner()->plan([
            new ChangedFile('src/modules/Foo/Application/Handler/PayloadHandler/Gone.php', ChangedFile::KIND_HANDLER, ChangedFile::STATUS_DELETED),
        ], VerificationPlan::SCOPE_STANDARD);

        $this->assertCount(1, $plan->changedFiles);
        $this->assertSame([], $plan->targets);
    }

    public function test_dedupes_lint_targets_when_multiple_files_share_a_kind(): void
    {
        $plan = $this->planner()->plan([
            new ChangedFile('src/modules/Foo/Application/Handler/PayloadHandler/A.php', ChangedFile::KIND_HANDLER),
            new ChangedFile('src/modules/Foo/Application/Handler/PayloadHandler/B.php', ChangedFile::KIND_HANDLER),
        ], VerificationPlan::SCOPE_STANDARD);

        $commands = $this->lintCommandNames($plan);
        $this->assertSame(count($commands), count(array_unique($commands)));
        // Both files appear in triggeredBy.
        foreach ($this->targetsOfType($plan, VerificationTarget::TYPE_LINT) as $t) {
            $this->assertCount(2, $t->triggeredBy);
        }
    }

    public function test_unknown_scope_falls_back_to_standard(): void
    {
        $plan = $this->planner()->plan([
            new ChangedFile('src/modules/Foo/Application/Handler/PayloadHandler/X.php', ChangedFile::KIND_HANDLER),
        ], 'gibberish');

        $this->assertSame('gibberish', $plan->scope);
        $this->assertSame(VerificationPlan::SCOPE_STANDARD, $plan->effectiveScope);
    }

    private function planner(): VerificationPlanner
    {
        return new VerificationPlanner($this->root, new ChangedFileClassifier());
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

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ActionlintTargets;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlanner;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;

/**
 * A commit that changed only a GitHub workflow could not be pushed: nothing
 * was planned for the file, so the run was `incomplete` (20.11,
 * semitexa-installer's docker-publish.yml).
 */
final class ActionlintTargetsTest extends TestCase
{
    #[Test]
    public function the_planner_gives_a_changed_workflow_its_own_actionlint_target(): void
    {
        $package = 'packages/semitexa-installer/.github/workflows/docker-publish.yml';
        $root = '.github/workflows/ci.yaml';

        $targets = $this->planned([
            new ChangedFile($package, ChangedFile::KIND_NON_PHP),
            new ChangedFile($root, ChangedFile::KIND_NON_PHP, ChangedFile::STATUS_ADDED),
        ]);

        self::assertSame(["actionlint:{$package}", "actionlint:{$root}"], array_map(static fn (VerificationTarget $t): string => $t->id, $targets));
        self::assertSame([[$package], [$root]], array_map(static fn (VerificationTarget $t): array => $t->triggeredBy, $targets));
        self::assertSame([$package, $root], array_map(static fn (VerificationTarget $t): ?string => $t->filePath, $targets));
    }

    #[Test]
    public function it_is_planned_at_minimal_scope_too(): void
    {
        $path = 'packages/semitexa-core/.github/workflows/ci.yml';

        $targets = $this->planned([new ChangedFile($path, ChangedFile::KIND_NON_PHP)], VerificationPlan::SCOPE_MINIMAL);

        self::assertSame(["actionlint:{$path}"], array_map(static fn (VerificationTarget $t): string => $t->id, $targets));
    }

    #[Test]
    public function a_yaml_that_is_no_workflow_of_a_workspace_repository_gets_none(): void
    {
        $targets = $this->planned([
            new ChangedFile('packages/semitexa-core/.github/dependabot.yml', ChangedFile::KIND_NON_PHP),
            new ChangedFile('packages/semitexa-core/config/services.yml', ChangedFile::KIND_NON_PHP),
            new ChangedFile('docker-compose.yml', ChangedFile::KIND_NON_PHP),
            // An action a workflow calls, not a workflow.
            new ChangedFile('.github/workflows/build/action.yml', ChangedFile::KIND_NON_PHP),
            // A scaffold's template: GitHub reads no workflow from there.
            new ChangedFile('packages/semitexa-installer/scaffold/.github/workflows/ci.yml', ChangedFile::KIND_NON_PHP),
            new ChangedFile('packages/semitexa-core/.github/workflows/README.md', ChangedFile::KIND_NON_PHP),
        ]);

        self::assertSame([], $targets);
    }

    #[Test]
    public function a_deleted_workflow_has_nothing_to_read_and_a_renamed_one_is_read_where_it_is_now(): void
    {
        $targets = ActionlintTargets::of([
            new ChangedFile('.github/workflows/old.yml', ChangedFile::KIND_NON_PHP, ChangedFile::STATUS_DELETED),
            new ChangedFile('.github/workflows/new.yml', ChangedFile::KIND_NON_PHP, ChangedFile::STATUS_RENAMED, '.github/workflows/was.yml'),
        ]);

        self::assertSame(['actionlint:.github/workflows/new.yml'], array_map(static fn (VerificationTarget $t): string => $t->id, $targets));
    }

    /**
     * @param list<ChangedFile> $files
     *
     * @return list<VerificationTarget>
     */
    private function planned(array $files, string $scope = VerificationPlan::SCOPE_STANDARD): array
    {
        $plan = (new VerificationPlanner(sys_get_temp_dir() . '/actionlint-targets-test-' . getmypid()))->plan($files, $scope);

        return array_values(array_filter($plan->targets, static fn (VerificationTarget $t): bool => $t->type === VerificationTarget::TYPE_ACTIONLINT));
    }
}

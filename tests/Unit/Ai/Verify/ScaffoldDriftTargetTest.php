<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlanner;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;

/**
 * The installer scaffold is a single source propagated into four copies, and
 * the failure this target exists for is the PARTIAL ritual: touch the source,
 * forget a copy — or hand-edit a copy, which the next sync overwrites without
 * a word.
 *
 * Triggered by path, unlike the agent-skill copies. Those live outside any git
 * repository, so an edit to one can never appear in a changed-file list and the
 * gate has to run always; these copies are version-controlled, so the paths are
 * exactly the signal.
 *
 * MEASURED 2026-09-06: a one-line change to the scaffold's
 * docker-compose.test.yml left semitexa-update's checksum manifest stale, and
 * only that package's own test noticed. This generalises the catch.
 */
final class ScaffoldDriftTargetTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function scaffoldPaths(): array
    {
        return [
            'the source of truth' => ['packages/semitexa-installer/scaffold/docker-compose.test.yml'],
            'the update mirror'   => ['packages/semitexa-update/resources/scaffold/Dockerfile'],
            'the update manifest' => ['packages/semitexa-update/resources/scaffold-manifest.json'],
            'the ultimate copy'   => ['packages/semitexa-ultimate/composer.json'],
            'the root launcher'   => ['bin/semitexa'],
        ];
    }

    #[Test]
    #[DataProvider('scaffoldPaths')]
    public function a_touched_scaffold_copy_schedules_the_drift_check(string $path): void
    {
        self::assertTrue(
            $this->plans($path),
            $path . ' must schedule the scaffold drift check',
        );
    }

    /** @return array<string, array{string}> */
    public static function unrelatedPaths(): array
    {
        return [
            'ordinary source'      => ['packages/semitexa-media/src/Application/Service/MediaService.php'],
            'a test'               => ['packages/semitexa-os/tests/Unit/Service/ProviderHealthCacheTest.php'],
            'an app module'        => ['src/modules/CmsDemo/src/Application/Service/DemoArticleEditor.php'],
            'a similarly named package' => ['packages/semitexa-installer/src/Installer.php'],
        ];
    }

    #[Test]
    #[DataProvider('unrelatedPaths')]
    public function an_unrelated_file_does_not_schedule_it(string $path): void
    {
        self::assertFalse(
            $this->plans($path),
            $path . ' must not drag in a scaffold check it has nothing to do with',
        );
    }

    private function plans(string $path): bool
    {
        $planner = new VerificationPlanner(getcwd() ?: '.');
        $plan = $planner->plan(
            [new ChangedFile($path, ChangedFile::KIND_NON_PHP, ChangedFile::STATUS_MODIFIED)],
            VerificationPlan::SCOPE_MINIMAL,
        );

        foreach ($plan->targets as $target) {
            if ($target->type === VerificationTarget::TYPE_SCAFFOLD_DRIFT) {
                return true;
            }
        }

        return false;
    }
}

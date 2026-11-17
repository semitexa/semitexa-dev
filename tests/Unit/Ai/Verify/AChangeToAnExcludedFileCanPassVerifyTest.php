<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFileClassifier;
use Semitexa\Dev\Application\Service\Ai\Verify\ContractMoveResolver;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\PhpstanExcludedPaths;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\PhpstanRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlanner;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;

/**
 * tk-verify-schedules-di-phpstan-for-excluded-files: a change to
 * packages/semitexa-ultimate/src/Capabilities.php (a docblock) left every
 * ai:verify of it incomplete. phpstan_di was handed only that file, which the
 * ai:verify phpstan config excludes on purpose, and phpstan answered "No files
 * found to analyse". Excluded files are left out of phpstan_di now.
 */
final class AChangeToAnExcludedFileCanPassVerifyTest extends TestCase
{
    private const CONFIG = <<<'NEON'
        parameters:
            level: 5
            excludePaths:
                - %currentWorkingDirectory%/packages/semitexa-core/src/Composer/SemitexaPlugin.php
                # ultimate is gated by its own CI
                - %currentWorkingDirectory%/packages/semitexa-ultimate/src
                - '%currentWorkingDirectory%/packages/semitexa-old/src/' (?)

            ignoreErrors:
                - '#nothing#'
        NEON;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/excluded-paths-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/' . dirname(PhpstanRunner::CONFIG_REL_PATH), 0o755, true);
        file_put_contents($this->root . '/' . PhpstanRunner::CONFIG_REL_PATH, self::CONFIG);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function the_excluded_paths_are_read_from_the_config(): void
    {
        $excluded = PhpstanExcludedPaths::forProject($this->root);

        self::assertSame([
            'packages/semitexa-core/src/Composer/SemitexaPlugin.php',
            'packages/semitexa-ultimate/src',
            'packages/semitexa-old/src',
        ], $excluded->paths, 'the list ends where the next key begins; a comment inside it is skipped');
        self::assertTrue($excluded->excludes('packages/semitexa-ultimate/src/Capabilities.php'));
        self::assertTrue($excluded->excludes('packages/semitexa-core/src/Composer/SemitexaPlugin.php'));
        self::assertFalse($excluded->excludes('packages/semitexa-ultimate/srcs/Other.php'), 'a directory excludes what is inside it, not a longer name');
        self::assertFalse($excluded->excludes('packages/semitexa-core/src/Composer/SemitexaPluginTest.php'));
    }

    /** Review, dev#278: a --files value keeps its spelling, so ./ and .. are resolved before matching. */
    #[Test]
    public function a_path_is_matched_as_it_resolves_not_as_it_is_spelled(): void
    {
        $excluded = PhpstanExcludedPaths::forProject($this->root);

        self::assertTrue($excluded->excludes('./packages/semitexa-ultimate/src/Capabilities.php'));
        self::assertTrue($excluded->excludes('packages//semitexa-ultimate/./src/Capabilities.php'));
        self::assertFalse($excluded->excludes('packages/semitexa-ultimate/src/../../semitexa-api/src/Application/Console/Command/DumpOpenApiCommand.php'), 'it climbs out of the excluded directory');
        self::assertFalse($excluded->excludes('../packages/semitexa-ultimate/src/Capabilities.php'), 'above the project root nothing is excluded');
    }

    #[Test]
    public function the_workspace_config_is_read_the_same_way(): void
    {
        $config = dirname(__DIR__, 4) . '/config/phpstan-ai-verify.neon';
        self::assertFileExists($config);

        $paths = PhpstanExcludedPaths::fromConfig($config)->paths;

        // The shape the reader understands is the shape that config uses: if it changes, this goes red.
        self::assertContains('packages/semitexa-ultimate/src', $paths);
        self::assertContains('packages/semitexa-core/src/Composer/SemitexaPlugin.php', $paths);
    }

    #[Test]
    public function an_excluded_file_alone_schedules_no_phpstan_di(): void
    {
        $plan = $this->planner()->plan([
            new ChangedFile('packages/semitexa-ultimate/src/Capabilities.php', ChangedFile::KIND_PHP_OTHER),
        ], VerificationPlan::SCOPE_STANDARD);

        // Review, dev#278: the file is still checked, only not by phpstan; an empty plan would pass the line after.
        $syntax = array_values(array_filter($plan->targets, static fn (VerificationTarget $t): bool => $t->type === VerificationTarget::TYPE_SYNTAX));
        self::assertSame([['packages/semitexa-ultimate/src/Capabilities.php']], array_map(static fn (VerificationTarget $t): array => $t->triggeredBy, $syntax));
        self::assertSame([], $this->diTargets($plan));
    }

    #[Test]
    public function beside_other_files_only_the_excluded_one_is_left_out(): void
    {
        $plan = $this->planner()->plan([
            new ChangedFile('packages/semitexa-ultimate/src/Capabilities.php', ChangedFile::KIND_PHP_OTHER),
            new ChangedFile('packages/semitexa-api/src/Application/Console/Command/DumpOpenApiCommand.php', ChangedFile::KIND_PHP_OTHER),
        ], VerificationPlan::SCOPE_STANDARD);

        $targets = $this->diTargets($plan);
        self::assertCount(1, $targets);
        self::assertSame(['packages/semitexa-api/src/Application/Console/Command/DumpOpenApiCommand.php'], $targets[0]->triggeredBy);
    }

    /** @return list<VerificationTarget> */
    private function diTargets(VerificationPlan $plan): array
    {
        return array_values(array_filter(
            $plan->targets,
            static fn (VerificationTarget $t): bool => $t->type === VerificationTarget::TYPE_PHPSTAN_DI,
        ));
    }

    private function planner(): VerificationPlanner
    {
        $noMoves = new class ($this->root) extends ContractMoveResolver {
            public function resolve(array $files): array
            {
                return [];
            }
        };

        return new VerificationPlanner($this->root, new ChangedFileClassifier(), null, $noMoves);
    }
}

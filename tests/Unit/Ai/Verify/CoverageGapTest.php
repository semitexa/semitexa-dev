<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\CoverageGap;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;

final class CoverageGapTest extends TestCase
{
    #[Test]
    public function a_file_only_a_structural_check_selected_is_named_unchecked(): void
    {
        $gap = $this->gap($this->plan(
            [$this->file('src/A.php', ChangedFile::KIND_SERVICE), $this->file('docs/run.sh', ChangedFile::KIND_NON_PHP)],
            [
                $this->target(VerificationTarget::TYPE_SYNTAX, ['src/A.php']),
                $this->target(VerificationTarget::TYPE_MODULE_STRUCTURE, ['src/A.php', 'docs/run.sh']),
            ],
        ));

        self::assertSame([['path' => 'docs/run.sh', 'kind' => ChangedFile::KIND_NON_PHP]], $gap->toArray()['unchecked_files']);
        self::assertSame('2 checks over 2 changed files; no check read 1 of them: docs/run.sh', $gap->headline());
        // Something was read: the pass stands, with the gap beside it.
        self::assertSame('pass', $gap->adjust('pass'));
    }

    #[Test]
    public function a_pass_over_a_change_no_check_could_read_is_incomplete(): void
    {
        // Measured 2026-10-01: .md/.sh/.js changes planned module_structure
        // alone and reported `pass`.
        $gap = $this->gap($this->plan(
            [$this->file('assets/app.js', ChangedFile::KIND_CLIENT_SCRIPT)],
            [$this->target(VerificationTarget::TYPE_MODULE_STRUCTURE, ['assets/app.js'])],
        ));

        self::assertSame('incomplete', $gap->adjust('pass'));
        self::assertSame('incomplete', $gap->adjust('skipped'));
        // A failure is still the more important thing to say.
        self::assertSame('fail', $gap->adjust('fail'));
    }

    #[Test]
    public function every_file_read_says_so_and_changes_nothing(): void
    {
        $gap = $this->gap($this->plan(
            [$this->file('src/A.php', ChangedFile::KIND_SERVICE)],
            [$this->target(VerificationTarget::TYPE_PHPUNIT, ['src/A.php'])],
        ));

        self::assertSame([], $gap->unchecked);
        self::assertSame('1 check over 1 changed file; every file was read by at least one check', $gap->headline());
        self::assertSame('pass', $gap->adjust('pass'));
    }

    #[Test]
    public function a_directory_is_a_structure_request_not_an_unread_file(): void
    {
        // `ai:verify --all` hands package roots; module_structure IS their check.
        $root = sys_get_temp_dir() . '/coverage-gap-dir-' . getmypid();
        @mkdir($root . '/packages/semitexa-x', 0777, true);
        $plan = $this->plan(
            [$this->file('packages/semitexa-x', ChangedFile::KIND_NON_PHP)],
            [$this->target(VerificationTarget::TYPE_MODULE_STRUCTURE, ['packages/semitexa-x'])],
        );

        $gap = CoverageGap::of($plan, $root);
        @rmdir($root . '/packages/semitexa-x');
        @rmdir($root . '/packages');
        @rmdir($root);

        self::assertSame([], $gap->unchecked);
        self::assertSame('pass', $gap->adjust('pass'));
    }

    #[Test]
    public function a_deleted_file_has_nothing_left_to_read(): void
    {
        $gap = $this->gap($this->plan(
            [$this->file('src/Gone.php', ChangedFile::KIND_SERVICE, ChangedFile::STATUS_DELETED)],
            [$this->target(VerificationTarget::TYPE_MODULE_STRUCTURE, ['src/Gone.php'])],
        ));

        self::assertSame([], $gap->unchecked);
        self::assertSame('pass', $gap->adjust('pass'));
    }

    /**
     * @param list<ChangedFile>        $files
     * @param list<VerificationTarget> $targets
     */
    private function plan(array $files, array $targets): VerificationPlan
    {
        return new VerificationPlan(VerificationPlan::SCOPE_STANDARD, VerificationPlan::SCOPE_STANDARD, $files, $targets);
    }

    private function gap(VerificationPlan $plan): CoverageGap
    {
        return CoverageGap::of($plan, sys_get_temp_dir() . '/coverage-gap-test-' . getmypid());
    }

    private function file(string $path, string $kind, string $status = ChangedFile::STATUS_MODIFIED): ChangedFile
    {
        return new ChangedFile($path, $kind, $status);
    }

    /**
     * @param list<string> $triggeredBy
     */
    private function target(string $type, array $triggeredBy): VerificationTarget
    {
        return new VerificationTarget($type, $type . ':x', 'test', $triggeredBy);
    }
}

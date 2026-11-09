<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\ProjectGuardTargets;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlanner;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;

/**
 * An instruction to an agent goes stale from either side: the instruction is
 * edited, or the file it names is deleted or renamed. docs:instructions runs on
 * both; docs:claims no longer takes credit for root files it never read.
 */
final class InstructionTargetsTest extends TestCase
{
    #[Test]
    public function an_edited_instruction_or_a_deleted_file_schedules_the_instruction_lint(): void
    {
        $edited = $this->target([new ChangedFile('AGENTS.md', ChangedFile::KIND_NON_PHP)]);
        self::assertNotNull($edited);
        self::assertSame(['docs:lint', ['--instructions' => true], ['AGENTS.md']], [$edited->commandName, $edited->commandInput, $edited->triggeredBy]);

        $deleted = $this->target([
            new ChangedFile('packages/semitexa-x/src/Gone.php', ChangedFile::KIND_PHP_OTHER, ChangedFile::STATUS_DELETED),
            new ChangedFile('packages/semitexa-x/src/Kept.php', ChangedFile::KIND_PHP_OTHER),
        ]);
        self::assertSame(['packages/semitexa-x/src/Gone.php'], $deleted?->triggeredBy);

        self::assertNull($this->target([new ChangedFile('packages/semitexa-x/src/Kept.php', ChangedFile::KIND_PHP_OTHER)]));
        self::assertNull($this->target([new ChangedFile('AGENTS.md', ChangedFile::KIND_NON_PHP)], VerificationPlan::SCOPE_MINIMAL), 'a console boot: not at minimal');
    }

    #[Test]
    public function instructions_are_root_markdown_and_skills(): void
    {
        self::assertSame(
            [true, true, false, true, true, true, false, false],
            array_map(ProjectGuardTargets::isInstruction(...), [
                'AI_NOTES.md',
                'packages/semitexa-ultimate/AGENTS.md',
                'packages/semitexa-ultimate/docs/X.md',
                'CLAUDE.md',
                '.claude/skills/codereview/SKILL.md',
                'packages/semitexa-dev/resources/skills/codereview/references/CODE_REVIEW.md',
                'packages/semitexa-docs/docs/AI.md',
                'AGENTS.php',
            ]),
        );
    }

    #[Test]
    public function docs_claims_no_longer_claims_root_instructions_it_never_reads(): void
    {
        // Its own root: docs:claims names only pages that exist, and the
        // working directory differs between the workspace and the package's CI.
        $root = sys_get_temp_dir() . '/semitexa-docs-instructions-' . bin2hex(random_bytes(4));
        mkdir($root . '/packages/semitexa-docs/docs', 0777, true);
        file_put_contents($root . '/AGENTS.md', "# Agents\n");
        file_put_contents($root . '/packages/semitexa-docs/docs/AI.md', "# AI\n");
        $plan = (new VerificationPlanner($root))->plan([
            new ChangedFile('AGENTS.md', ChangedFile::KIND_NON_PHP),
            new ChangedFile('packages/semitexa-docs/docs/AI.md', ChangedFile::KIND_NON_PHP),
        ], VerificationPlan::SCOPE_STANDARD);
        $claims = array_values(array_filter($plan->targets, static fn (VerificationTarget $t): bool => $t->id === 'docs:claims'))[0] ?? null;

        self::assertSame(['packages/semitexa-docs/docs/AI.md'], $claims?->triggeredBy);
    }

    #[Test]
    public function docs_claims_reads_the_changed_pages_not_the_framework_corpus(): void
    {
        // In a project docs:lint with no --path reads vendor/semitexa/docs: pages
        // about packages the project does not install (Guard run 2026-11-08,
        // 20 claims nobody there could fix). A deleted page holds no claim.
        $root = sys_get_temp_dir() . '/semitexa-docs-claims-' . bin2hex(random_bytes(4));
        mkdir($root . '/docs', 0777, true);
        mkdir($root . '/client/ubuntu', 0777, true);
        file_put_contents($root . '/docs/guide.md', "# Guide\n");
        file_put_contents($root . '/client/ubuntu/README.md', "# Client\n");
        $plan = (new VerificationPlanner($root))->plan([
            new ChangedFile('docs/guide.md', ChangedFile::KIND_NON_PHP),
            new ChangedFile('client/ubuntu/README.md', ChangedFile::KIND_NON_PHP, ChangedFile::STATUS_ADDED),
            new ChangedFile('docs/old.md', ChangedFile::KIND_NON_PHP, ChangedFile::STATUS_DELETED),
            // A typo in --files: docs:lint would skip it and pass having read nothing.
            new ChangedFile('docs/no-such-page.md', ChangedFile::KIND_NON_PHP),
        ], VerificationPlan::SCOPE_STANDARD);
        $claims = array_values(array_filter($plan->targets, static fn (VerificationTarget $t): bool => $t->id === 'docs:claims'))[0] ?? null;

        self::assertSame(['docs/guide.md', 'client/ubuntu/README.md'], $claims?->triggeredBy);
        self::assertSame([$root . '/docs/guide.md', $root . '/client/ubuntu/README.md'], $claims->commandInput['--path'] ?? null);
    }

    #[Test]
    public function only_deleted_pages_leave_no_claims_gate_to_fall_back_to_the_corpus(): void
    {
        $plan = (new VerificationPlanner(sys_get_temp_dir()))->plan([
            new ChangedFile('docs/old.md', ChangedFile::KIND_NON_PHP, ChangedFile::STATUS_DELETED),
        ], VerificationPlan::SCOPE_STANDARD);

        self::assertSame([], array_values(array_filter($plan->targets, static fn (VerificationTarget $t): bool => $t->id === 'docs:claims')));
    }

    /** @param list<ChangedFile> $files */
    private function target(array $files, string $scope = VerificationPlan::SCOPE_STANDARD): ?VerificationTarget
    {
        $targets = (new ProjectGuardTargets(sys_get_temp_dir()))->targets($files, $scope);

        return array_values(array_filter($targets, static fn (VerificationTarget $t): bool => $t->id === 'docs:instructions'))[0] ?? null;
    }
}

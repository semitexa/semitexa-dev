<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Evidence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Evidence\VarArtifactScan;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\ProjectGuardTargets;

/**
 * var/ is ignored folder by folder; a tool writing a new one put screenshots
 * and traces one `git add -A` from a public commit (2026-10-02: five folders).
 */
final class VarArtifactScanTest extends TestCase
{
    #[Test]
    public function runtime_output_git_would_commit_is_named(): void
    {
        $changed = [
            ['path' => 'var/os-dev-shots/desk.png', 'status' => 'A'],
            ['path' => 'var/trace/20261002-101010-aaaa.json', 'status' => 'A'],
            ['path' => 'packages/semitexa-ultimate/var/tmp/x.log', 'status' => 'M'],
            ['path' => 'src/modules/Shop/var/cache/y', 'status' => 'A'],
        ];

        self::assertSame(
            ['packages/semitexa-ultimate/var/tmp/x.log', 'src/modules/Shop/var/cache/y', 'var/os-dev-shots/desk.png', 'var/trace/20261002-101010-aaaa.json'],
            VarArtifactScan::offending($changed),
        );
    }

    #[Test]
    public function what_the_scaffold_versions_on_purpose_is_not(): void
    {
        $changed = [
            ['path' => 'var/ai-work/tasks/tk-x.json', 'status' => 'M'],
            ['path' => 'var/ai-traces/tk-x.ndjson', 'status' => 'M'],
            ['path' => 'var/migrations/history/2026-10-02.sql', 'status' => 'A'],
            ['path' => 'var/docs/README.md', 'status' => 'M'],
            ['path' => 'var/log/.gitkeep', 'status' => 'A'],
            ['path' => 'var/os-dev-shots/old.png', 'status' => 'D'],
            ['path' => 'src/Variant/var.php', 'status' => 'A'],
            ['path' => 'docs/var/notes.md', 'status' => 'A'],
        ];

        self::assertSame([], VarArtifactScan::offending($changed));
    }

    #[Test]
    public function ai_verify_runs_the_lint_only_when_something_under_var_changed(): void
    {
        $guards = new ProjectGuardTargets(sys_get_temp_dir());
        $ids = static fn (array $targets): array => array_map(static fn ($t): string => $t->id, $targets);

        self::assertNotContains('lint:var-artifacts', $ids($guards->targets([new ChangedFile('src/Foo.php', ChangedFile::KIND_PHP_OTHER)], 'full')));

        $targets = $guards->targets([new ChangedFile('var/e2e-proof/shot.png', ChangedFile::KIND_NON_PHP, ChangedFile::STATUS_ADDED)], 'full');
        $lint = array_values(array_filter($targets, static fn ($t): bool => $t->id === 'lint:var-artifacts'))[0] ?? null;
        self::assertNotNull($lint);
        self::assertSame(['lint:var-artifacts', ['var/e2e-proof/shot.png']], [$lint->commandName, $lint->triggeredBy]);
    }
}

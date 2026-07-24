<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlanner;

/**
 * Every lint the planner can emit must be a command that actually exists.
 *
 * The planner named its five lints with a `semitexa:` prefix that was never
 * registered. Symfony raised CommandNotFoundException, the executor turned that
 * into "skipped", and the verdict ignores skipped — so handlers, di, scoping,
 * responses and templates never gated anything while ai:verify reported pass.
 * The executor now fails on an unknown command, which turns the next such typo
 * into a red run; this test turns it into a red test, before anyone runs it.
 */
final class PlannedLintCommandsExistTest extends TestCase
{
    /** @return list<string> every command name the planner can put in a plan */
    private function plannedCommandNames(): array
    {
        $reflection = new ReflectionClass(VerificationPlanner::class);
        $constants = $reflection->getConstants();

        $names = [];
        /** @var array<string, list<string>> $map */
        $map = $constants['KIND_LINT_MAP'] ?? [];
        foreach ($map as $commands) {
            foreach ($commands as $command) {
                $names[] = $command;
            }
        }
        /** @var list<string> $all */
        $all = $constants['ALL_LINTS'] ?? [];
        foreach ($all as $command) {
            $names[] = $command;
        }

        $names = array_values(array_unique(array_filter($names, static fn (string $n): bool => $n !== '@all')));
        sort($names);

        return $names;
    }

    private function workspaceRoot(): ?string
    {
        $dir = __DIR__;
        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir . '/packages') && is_file($dir . '/composer.json')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /** @return list<string> names declared via #[AsCommand(name: '...')] across the workspace */
    private function registeredCommandNames(string $root): array
    {
        $names = [];
        foreach ([$root . '/packages', $root . '/src/modules'] as $scanRoot) {
            if (!is_dir($scanRoot)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($scanRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), 'Command.php')) {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                if (preg_match_all("/name:\s*'([a-z0-9:_-]+)'/i", $contents, $matches) === false) {
                    continue;
                }
                foreach ($matches[1] as $name) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    #[Test]
    public function the_planner_only_names_lints_that_are_registered(): void
    {
        $root = $this->workspaceRoot();
        if ($root === null) {
            self::markTestSkipped('workspace root not resolvable from ' . __DIR__);
        }

        $planned = $this->plannedCommandNames();
        self::assertNotEmpty($planned, 'the planner should reference at least one lint');

        $registered = $this->registeredCommandNames($root);
        self::assertNotEmpty($registered, 'the command scan found nothing, so it proves nothing');

        $missing = array_values(array_diff($planned, $registered));
        self::assertSame(
            [],
            $missing,
            "ai:verify plans lints that no command declares: " . implode(', ', $missing)
                . ". A planned gate that does not exist cannot gate anything.",
        );
    }

    #[Test]
    public function the_planner_uses_the_bare_lint_prefix(): void
    {
        // Guards the exact regression: the names carried a semitexa: prefix that
        // no command has ever declared.
        foreach ($this->plannedCommandNames() as $name) {
            self::assertStringStartsWith(
                'lint:',
                $name,
                "planner lint names must be bare command names, got {$name}",
            );
        }
    }
}

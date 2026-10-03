<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Dev\Application\Service\Ai\Verify\ProjectGuardTargets;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlanner;

/**
 * Every lint ai:verify runs says why it exists — the same contract as the
 * Semitexa PHPStan rules and the module_structure codes: a RATIONALE constant
 * on the command class, a cause, then the incident that taught it or the
 * admission that there was none. A lint added to the plan without one fails
 * here rather than failing a change later with nothing but its last line.
 */
final class LintRationaleTest extends TestCase
{
    private const SHAPE = '/^Why: \S.{60,}(Learned (\d{4}-\d{2}|in )|no incident on record)/s';

    private const PACKAGES = __DIR__ . '/../../../../../';

    #[Test]
    public function every_planned_lint_explains_itself(): void
    {
        $planned = $this->plannedLints();
        self::assertContains('lint:di', $planned, 'the planner constants moved; this test would be vacuous');
        self::assertContains('lint:var-artifacts', $planned, 'the guard target moved; this test would be vacuous');

        $classes = $this->commandClasses();
        $wrong = [];
        foreach ($planned as $name) {
            $class = $classes[$name] ?? null;
            if ($class === null) {
                $wrong[] = "{$name}: no command class declares it";
                continue;
            }
            $why = (new ReflectionClass($class))->getConstant('RATIONALE');
            if (!is_string($why) || preg_match(self::SHAPE, $why) !== 1) {
                $wrong[] = "{$name}: {$class}::RATIONALE is missing or not 'Why: ... Learned <date>: ...' / '... no incident on record'";
            }
        }

        self::assertSame([], $wrong);
    }

    /** @return list<string> */
    private function plannedLints(): array
    {
        $constants = (new ReflectionClass(VerificationPlanner::class))->getConstants();
        $names = [];
        $map = $constants['KIND_LINT_MAP'] ?? [];
        $lists = is_array($map) ? array_values($map) : [];
        $lists[] = $constants['ALL_LINTS'] ?? [];
        foreach ($lists as $commands) {
            foreach (is_array($commands) ? $commands : [] as $command) {
                if (is_string($command)) {
                    $names[] = $command;
                }
            }
        }

        // Guard targets are built in code, not constants: read the lint names they schedule.
        $source = (string) file_get_contents((string) (new ReflectionClass(ProjectGuardTargets::class))->getFileName());
        preg_match_all("/commandName:\s*'(lint:[a-z0-9-]+)'/", $source, $guards);
        array_push($names, ...$guards[1]);

        return array_values(array_unique(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'lint:'))));
    }

    /** @return array<string, class-string> command name => class */
    private function commandClasses(): array
    {
        $classes = [];
        foreach (glob(self::PACKAGES . 'semitexa-*/src/Application/Console/Command/*Command.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match("/name:\s*'(lint:[a-z0-9-]+)'/", $source, $name) !== 1
                || preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
                continue;
            }
            /** @var class-string $class */
            $class = $namespace[1] . '\\' . basename($file, '.php');
            $classes[$name[1]] = $class;
        }

        return $classes;
    }
}

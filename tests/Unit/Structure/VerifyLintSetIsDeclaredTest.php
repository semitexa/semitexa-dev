<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlanner;

/**
 * ai:verify is the one door (tk-verify-one-door): an agent is told about it and nothing else, so
 * every rule it should follow has to be a check that door runs.
 *
 * A new rule is a detector inside an existing lint, not a new lint command. A new lint is a
 * second door: it starts outside every plan until someone wires it, and until then it reads
 * exactly like a passing check. So the set of lints the plan may run is written down here, and
 * so is every `lint:*` command the packages ship that the plan does not run, each with why.
 * Adding to either list is a deliberate edit of this file.
 */
final class VerifyLintSetIsDeclaredTest extends TestCase
{
    /** The lints a verify plan may schedule: by changed-file kind or in a broad run. */
    private const PLANNED = [
        'lint:access',
        'lint:components',
        'lint:css-tokens',
        'lint:deferred-slots',
        'lint:deferred-twig',
        'lint:di',
        'lint:handlers',
        'lint:inline-script',
        'lint:mechanisms',
        'lint:responses',
        'lint:scoping',
        'lint:templates',
        'lint:transport-doors',
        'lint:ui-contracts',
    ];

    /** Project-wide guards every run adds (ProjectGuardTargets), whatever changed. */
    private const GUARDS = [
        'lint:test-integrity',
        'lint:var-artifacts',
    ];

    /** Shipped lint commands the plan does not run, and why. */
    private const NOT_IN_VERIFY = [
        'lint:resources' => 'never wired into a plan; tk-lint-resources-not-in-verify decides whether it joins one',
    ];

    #[Test]
    public function the_plan_runs_exactly_the_declared_lints(): void
    {
        $all = self::constant('ALL_LINTS');
        sort($all);
        self::assertSame(self::PLANNED, $all, 'ALL_LINTS changed. A new rule belongs in an existing lint as a detector (tk-verify-one-door); a new lint is added here on purpose, or not at all.');

        foreach (self::constant('KIND_LINT_MAP') as $kind => $lints) {
            foreach ($lints as $lint) {
                self::assertContains($lint, self::PLANNED, "KIND_LINT_MAP[{$kind}] schedules {$lint}, which a broad run would never run.");
            }
        }
        foreach (self::constant('CROSS_FILE_LINTS') as $lint) {
            self::assertContains($lint, self::PLANNED, "CROSS_FILE_LINTS names {$lint}, which no plan runs.");
        }
    }

    #[Test]
    public function every_shipped_lint_command_is_run_by_the_plan_or_says_why_not(): void
    {
        $packages = dirname(__DIR__, 4);
        $found = [];
        foreach (glob($packages . '/*/src', GLOB_ONLYDIR) ?: [] as $src) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                if (preg_match_all("/#\\[AsCommand\\(\\s*name:\\s*'(lint:[a-z:-]+)'/", (string) file_get_contents($file->getPathname()), $m) > 0) {
                    foreach ($m[1] as $name) {
                        $found[$name] = true;
                    }
                }
            }
        }
        $found = array_keys($found);
        sort($found);
        self::assertContains('lint:di', $found, 'the scan reads the packages');

        $declared = [...self::PLANNED, ...self::GUARDS, ...array_keys(self::NOT_IN_VERIFY)];
        sort($declared);
        self::assertSame($declared, $found, 'A lint command was added or removed. A new rule is a detector inside an existing lint (tk-verify-one-door); a lint the plan does not run goes in NOT_IN_VERIFY with the reason.');
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function constant(string $name): array
    {
        $value = new \ReflectionClassConstant(VerificationPlanner::class, $name)->getValue();
        self::assertIsArray($value);

        return $value;
    }
}

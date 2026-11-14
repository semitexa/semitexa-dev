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
    public function the_scan_reads_every_way_an_attribute_names_a_command(): void
    {
        self::assertSame(['lint:a', 'lint:b', 'lint:c', 'lint:d', 'lint:f'], self::lintCommandNames(<<<'PHP'
            <?php
            #[AsCommand(name: 'lint:a', description: 'x')]
            #[AsCommand(description: "y", name: "lint:b")]
            #[AsCommand('lint:c')]
            #[\Symfony\Component\Console\Attribute\AsCommand(
                name: 'lint:d',
            )]
            #[AsCommand(name: 'docs:e')]
            #[AsCommand(description: ")]", name: "lint:f")]
            PHP));

        $this->expectException(\UnexpectedValueException::class);
        self::lintCommandNames("<?php\n#[AsCommand(name: 'lint:resources' . '-extra')]\n");
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
                $source = file_get_contents($file->getPathname());
                // A gate that cannot read a file fails, rather than counting it as declaring nothing.
                self::assertIsString($source, 'cannot read ' . $file->getPathname());
                foreach (self::lintCommandNames($source) as $name) {
                    $found[$name] = true;
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
     * The lint:* names a file's #[AsCommand] attributes declare: named or first positional
     * argument, either quote, qualified or not. Read with PHP's tokenizer, because a string
     * argument can hold anything, `)]` included (review, dev#254).
     *
     * @return list<string>
     */
    private static function lintCommandNames(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (array|string $t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $names = [];
        foreach ($tokens as $i => $token) {
            $isAsCommand = is_array($token)
                && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && preg_match('/(?:^|\\\\)AsCommand$/', $token[1]) === 1
                && ($tokens[$i + 1] ?? null) === '(';
            if (!$isAsCommand) {
                continue;
            }
            // The argument list, to its own closing parenthesis: strings are single tokens.
            $depth = 0;
            $first = true;
            for ($k = $i + 1; isset($tokens[$k]); $k++) {
                $t = $tokens[$k];
                if ($t === '(' || $t === '[') {
                    $depth++;
                    continue;
                }
                if ($t === ')' || $t === ']') {
                    if (--$depth === 0) {
                        break;
                    }
                    continue;
                }
                if ($depth !== 1) {
                    continue;
                }
                $named = is_array($t) && $t[0] === T_STRING && $t[1] === 'name' && ($tokens[$k + 1] ?? null) === ':';
                $at = $named ? $k + 2 : ($first ? $k : null);
                $value = $at === null ? null : ($tokens[$at] ?? null);
                if (is_array($value) && $value[0] === T_CONSTANT_ENCAPSED_STRING) {
                    // Only a whole literal is the name: 'lint:x' . 'y' is another command (review, dev#254).
                    if (!in_array($tokens[$at + 1] ?? null, [',', ')'], true)) {
                        throw new \UnexpectedValueException('a command name that is not one string literal: ' . $value[1] . ' ...');
                    }
                    $name = substr($value[1], 1, -1);
                    if (str_starts_with($name, 'lint:')) {
                        $names[] = $name;
                    }
                }
                $first = false;
                if ($named) {
                    $k += 2;
                }
            }
        }

        return $names;
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

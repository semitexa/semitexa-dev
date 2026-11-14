<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Structure;

use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlanner;
use Semitexa\Dev\Tests\Fixtures\LintScan\ConstantNamedLintCommand;
use Semitexa\Dev\Tests\Fixtures\LintScan\NowdocNamedLintCommand;

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
    public const RATIONALE = 'Why: ai:verify is the one check an agent is told about, so a lint no plan schedules never runs and reads like a passing check. Learned 2026-10-08: lint:deferred-twig existed, was wired into no gate, and was red on a real template the day it was connected.';

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
        // verify:accept-test-change: the text scan's fixture cases (quotes, order, `)]`, concatenation) became PHP's job when the scan moved to reflection; these cover the forms text could not read.
        self::assertSame(['lint:fixture-check', 'lint:fixture-alias'], self::lintCommandNames(ConstantNamedLintCommand::class), 'a constant, a concatenation, an aliased import, and aliases');
        self::assertSame(['lint:fixture-nowdoc'], self::lintCommandNames(NowdocNamedLintCommand::class), 'a nowdoc, after a description holding )]');
        self::assertSame(
            ['Semitexa\\Dev\\Tests\\Fixtures\\LintScan\\NowdocNamedLintCommand'],
            self::classesIn((string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/LintScan/NowdocNamedLintCommand.php')),
        );
        self::assertSame(['App\\NewLintCommand'], self::classesIn("<?php\nnamespace App;\n#[AsCommand(name: 'lint:new-check')]\nfinal class /* command documentation */ NewLintCommand {}\n"), 'a comment between class and its name');
        self::assertSame([], self::classesIn("<?php\n\$x = Foo::class;\n\$y = new class {};\n"), 'neither ::class nor an anonymous class is a declaration');
    }

    #[Test]
    public function every_shipped_lint_command_is_run_by_the_plan_or_says_why_not(): void
    {
        $packages = dirname(__DIR__, 4);
        $found = [];
        foreach (glob($packages . '/*/src', GLOB_ONLYDIR) ?: [] as $src) {
            // Only what this project installs ships its commands: a checkout under packages/ that
            // composer never installed is not loadable here, and not shipped (release clone, 14.11).
            $manifest = json_decode((string) @file_get_contents(dirname($src) . '/composer.json'), true);
            $name = is_array($manifest) && is_string($manifest['name'] ?? null) ? $manifest['name'] : null;
            self::assertNotNull($name, 'a package without a readable composer.json name: ' . dirname($src));
            if (!InstalledVersions::isInstalled($name)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                // A gate that cannot read a file fails, rather than counting it as declaring nothing.
                self::assertIsString($source, 'cannot read ' . $file->getPathname());
                if (!str_contains($source, 'AsCommand')) {
                    continue;
                }
                $classes = self::classesIn($source);
                // A file that names AsCommand and declares nothing readable is a miss of this scan, said out loud.
                self::assertNotSame([], $classes, "{$file->getPathname()} mentions AsCommand and no declaration was read from it");
                foreach ($classes as $class) {
                    self::assertTrue(class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class), "{$class} ({$file->getPathname()}) mentions AsCommand and cannot be loaded");
                    foreach (self::lintCommandNames($class) as $name) {
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
     * The lint:* names and aliases a command class's #[AsCommand] declares, read by PHP itself:
     * a constant, a nowdoc, a concatenation or an aliased import is the name PHP sees. Three
     * rounds of reading the attribute as text each missed a form (review, dev#254).
     *
     * @param class-string $class
     * @return list<string>
     */
    private static function lintCommandNames(string $class): array
    {
        $names = [];
        foreach ((new \ReflectionClass($class))->getAttributes(AsCommand::class) as $attribute) {
            $command = $attribute->newInstance();
            foreach ([$command->name, ...$command->aliases] as $name) {
                if (str_starts_with($name, 'lint:')) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * The classes a file declares, by its namespace and class tokens.
     *
     * @return list<string>
     */
    private static function classesIn(string $source): array
    {
        $tokens = token_get_all($source);
        $namespace = '';
        $classes = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                for ($k = $i + 1; isset($tokens[$k]) && $tokens[$k] !== ';' && $tokens[$k] !== '{'; $k++) {
                    if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                        $namespace = $tokens[$k][1];
                    }
                }
            }
            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                // `Foo::class` and `new class` are not declarations.
                $previous = $tokens[$i - 1] ?? null;
                if (is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                    continue;
                }
                // A comment may sit between the keyword and the name (review, dev#254).
                for ($k = $i + 1; isset($tokens[$k]) && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true); $k++);
                if (isset($tokens[$k]) && is_array($tokens[$k]) && $tokens[$k][0] === T_STRING) {
                    $classes[] = ltrim($namespace . '\\' . $tokens[$k][1], '\\');
                }
            }
        }

        return $classes;
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

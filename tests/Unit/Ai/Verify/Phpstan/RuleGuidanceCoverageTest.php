<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Phpstan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\PhpstanRunner;

/**
 * Every rule ai:verify runs can say what to do about it.
 *
 * A violation reaches an agent as NDJSON carrying a `suggested_fix`, and the
 * fallback for an identifier nobody wrote guidance for is "Fix the issue
 * reported by PHPStan." — which is not guidance, it is the absence of it
 * wearing a sentence. Nothing noticed, because the map and the rule list live
 * in different packages and neither mentions the other: three identifiers were
 * falling through when this was written (inertConstructorBody,
 * staticFacadeAccess and workerServiceConnectionHandle), two of them from rules
 * that have been shipping for months.
 *
 * The two halves are tied together here, so a rule added to the neon without
 * guidance fails rather than quietly degrading.
 */
final class RuleGuidanceCoverageTest extends TestCase
{
    private const RULES_NEON = __DIR__ . '/../../../../../config/phpstan-ai-verify-rules.neon';

    /**
     * Identifiers the registered rules can actually emit, read from the rules
     * themselves rather than from a list somebody has to remember to update.
     *
     * @return list<string>
     */
    private function emittedIdentifiers(): array
    {
        $neon = file_get_contents(self::RULES_NEON);
        self::assertIsString($neon, 'the shared rule list must be readable');

        self::assertSame(
            1,
            preg_match_all('/^\s*-\s*(Semitexa\\\\[A-Za-z0-9\\\\]+Rule)\s*$/m', $neon, $matches) > 0 ? 1 : 0,
            'no rules found in the neon — the pattern stopped matching, which would make this test vacuous',
        );

        $identifiers = [];

        foreach ($matches[1] as $class) {
            self::assertTrue(class_exists($class), $class . ' is registered but cannot be loaded');

            $file = (new ReflectionClass($class))->getFileName();
            self::assertIsString($file);

            $source = file_get_contents($file);
            self::assertIsString($source);

            preg_match_all("/->identifier\('([^']+)'\)/", $source, $found);
            self::assertNotSame([], $found[1], $class . ' declares no identifier at all');

            foreach ($found[1] as $identifier) {
                $identifiers[$identifier] = true;
            }
        }

        return array_keys($identifiers);
    }

    #[Test]
    public function every_registered_rule_has_actionable_guidance(): void
    {
        $suggestionFor = new ReflectionMethod(PhpstanRunner::class, 'suggestionFor');
        $runner = (new ReflectionClass(PhpstanRunner::class))->newInstanceWithoutConstructor();
        $fallback = $suggestionFor->invoke($runner, 'semitexa.nothing-will-ever-use-this');

        $identifiers = $this->emittedIdentifiers();
        self::assertGreaterThan(10, count($identifiers), 'the rule set should not have shrunk to nothing');

        $missing = [];
        foreach ($identifiers as $identifier) {
            if ($suggestionFor->invoke($runner, $identifier) === $fallback) {
                $missing[] = $identifier;
            }
        }

        self::assertSame(
            [],
            $missing,
            "These rules report violations with no guidance beyond the generic fallback:\n  - "
            . implode("\n  - ", $missing)
            . "\nAdd an entry to PhpstanRunner::suggestionFor() saying what to DO about each.",
        );
    }
}

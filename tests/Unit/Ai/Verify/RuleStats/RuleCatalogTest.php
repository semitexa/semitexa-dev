<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\RuleStats;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\RuleStats\RuleCatalog;

/** The catalog is read from the rules: every family is present, none is invented. */
final class RuleCatalogTest extends TestCase
{
    #[Test]
    public function every_rule_family_is_read_from_its_source(): void
    {
        $root = dirname(__DIR__, 7);
        self::assertFileExists($root . '/vendor/semitexa/core/config/phpstan-rules.neon', 'run from the workspace root');
        $families = array_count_values((new RuleCatalog($root))->rules());

        self::assertGreaterThanOrEqual(28, $families['phpstan'] ?? 0);
        self::assertSame(13, $families['module_structure'] ?? 0);
        self::assertSame(2, $families['live_tenancy'] ?? 0);
        self::assertSame(5, $families['ratchets'] ?? 0);
        foreach (['lint:di', 'lint:test-integrity', 'lint:var-artifacts', 'docs:instructions', 'docs:claims'] as $gate) {
            self::assertSame(1, $families[$gate] ?? 0, $gate);
        }
    }
}

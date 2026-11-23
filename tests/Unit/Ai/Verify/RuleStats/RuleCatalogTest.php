<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\RuleStats;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\RuleStats\RuleCatalog;
use Semitexa\Dev\Tests\Fixtures\RuleStats\UnloadableRule;

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
        self::assertSame(14, $families['module_structure'] ?? 0); // 14th: job_wrong_location (2026-11-20)
        self::assertSame(2, $families['live_tenancy'] ?? 0);
        self::assertSame(9, $families['ratchets'] ?? 0); // 6th: JsonOutputIsRawTest (2026-10-07); 7th: RawQueryOutsideTransactionRatchetTest (2026-11-11); 8th: VerifyLintSetIsDeclaredTest (2026-11-14); 9th: SemanticRuleCountsRecordTest (2026-11-19)
        foreach (['lint:di', 'lint:test-integrity', 'lint:var-artifacts', 'docs:instructions', 'docs:claims'] as $gate) {
            self::assertSame(1, $families[$gate] ?? 0, $gate);
        }
    }

    #[Test]
    public function an_unreadable_rule_list_fails_instead_of_shrinking_the_catalog(): void
    {
        // Review of dev#130: an empty PHPStan family reported every live rule as retired.
        $this->expectExceptionObject(new \RuntimeException('cannot read the PHPStan rule list /nonexistent-root/vendor/semitexa/core/config/phpstan-rules.neon'));
        (new RuleCatalog('/nonexistent-root'))->rules();
    }

    #[Test]
    public function a_rule_is_read_from_its_file_and_never_loaded(): void
    {
        // A project without PHPStan: declaring a rule class dies on its missing interface.
        $class = UnloadableRule::class;
        $root = $this->projectListing($class);
        try {
            $rules = (new RuleCatalog($root))->rules();
        } finally {
            $this->remove($root);
        }

        self::assertSame('phpstan', $rules['semitexa.ruleStatsFixtureUnloadable'] ?? null);
        self::assertSame('phpstan', $rules['semitexa.brokenFqcn'] ?? null);
        self::assertFalse(class_exists($class, false), 'the catalog loaded the rule class');
    }

    #[Test]
    public function a_registered_rule_with_no_file_is_an_error_that_names_it(): void
    {
        $root = $this->projectListing('Semitexa\\Dev\\Tests\\Fixtures\\RuleStats\\NowhereRule');
        $neon = $root . '/vendor/semitexa/core/config/phpstan-rules.neon';
        try {
            (new RuleCatalog($root))->rules();
            self::fail('a rule with no file was accepted');
        } catch (\RuntimeException $e) {
            self::assertSame("Semitexa\\Dev\\Tests\\Fixtures\\RuleStats\\NowhereRule is registered in {$neon} but cannot be found", $e->getMessage());
        } finally {
            $this->remove($root);
        }
    }

    #[Test]
    public function the_real_catalog_lists_the_identifiers_it_listed_when_it_loaded_the_classes(): void
    {
        // Recorded from develop b46e335 (class_exists + reflection), 2026-11-21. A new rule adds its identifier here.
        $expected = [
            'semitexa.brokenFqcn',
            'semitexa.builtSqlFragment',
            'semitexa.configOnArrayType',
            'semitexa.configOnClassType',
            'semitexa.deprecatedApi',
            'semitexa.directResponseConstruction',
            'semitexa.disallowErrorLog',
            'semitexa.discoveryCallSite',
            'semitexa.domainModelEncapsulation',
            'semitexa.eventListenerMissingExecution',
            'semitexa.executionScopedWithoutAttribute',
            'semitexa.explicitOptionalDependency',
            'semitexa.factoryClosedWorld',
            'semitexa.factoryContract',
            'semitexa.factoryStringKey',
            'semitexa.handlerReturnsResponse',
            'semitexa.inertConstructorBody',
            'semitexa.injectOnScalarType',
            'semitexa.injectedPropertyVisibility',
            'semitexa.injectionViaConstructor',
            'semitexa.mapperTypeConversion',
            'semitexa.moduleContractOwnership',
            'semitexa.noOpMapper',
            'semitexa.readonlyInjectionOfExecutionScoped',
            'semitexa.staticContainerAccess',
            'semitexa.staticFacadeAccess',
            'semitexa.traitInjection',
            'semitexa.unannotatedServiceProperty',
            'semitexa.unquotedSqlIdentifier',
            'semitexa.workerServiceCallerState',
            'semitexa.workerServiceConnectionHandle',
        ];
        $phpstan = array_keys((new RuleCatalog(dirname(__DIR__, 7)))->rules(), 'phpstan', true);

        self::assertSame($expected, $phpstan);
    }

    /** A temporary project root whose rule list registers one class. */
    private function projectListing(string $class): string
    {
        $root = sys_get_temp_dir() . '/semitexa-rule-catalog-' . bin2hex(random_bytes(6));
        mkdir($root . '/vendor/semitexa/core/config', 0o777, true);
        file_put_contents($root . '/vendor/semitexa/core/config/phpstan-rules.neon', "rules:\n    - {$class}\n");

        return $root;
    }

    private function remove(string $root): void
    {
        unlink($root . '/vendor/semitexa/core/config/phpstan-rules.neon');
        foreach (['/vendor/semitexa/core/config', '/vendor/semitexa/core', '/vendor/semitexa', '/vendor', ''] as $dir) {
            rmdir($root . $dir);
        }
    }
}

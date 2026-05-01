<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\DevGraph\DevGraphPathCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * dev:graph:path emits a deterministic JSON envelope explaining a file or
 * directory path using global module-structure rules + any package-local
 * extension. Powers `ai:ask --path=…`.
 *
 * These tests run against the real monorepo so they exercise the actual
 * orm local extension at packages/semitexa-orm/config/module-structure.php.
 */
final class DevGraphPathCommandTest extends TestCase
{
    public function test_explains_orm_adapter_as_locally_allowed_public_primitive(): void
    {
        $envelope = $this->explainPath('packages/semitexa-orm/src/Adapter');

        $this->assertSame('path_explanation', $envelope['kind']);
        $this->assertSame('orm', $envelope['package']);
        $this->assertSame('package', $envelope['module_kind']);
        $this->assertSame('local', $envelope['rule_scope']);
        $this->assertSame('allowed', $envelope['status']);
        $this->assertTrue($envelope['public_api']);
        $this->assertContains('packages/semitexa-orm/docs/MODULE_STRUCTURE.md', $envelope['docs_used']);
        $this->assertContains('packages/semitexa-orm/config/module-structure.php', $envelope['executable_rules_used']);
        $this->assertNotEmpty($envelope['warnings'], 'must warn that this allowance is local-only');
        $this->assertStringContainsString('local extension', $envelope['warnings'][0]);
        $this->assertStringContainsString('orm', $envelope['warnings'][0]);
        $this->assertNotNull($envelope['rule']);
        $this->assertSame('Adapter', $envelope['rule']['path']);
    }

    public function test_explains_orm_manager_root_file_as_locally_allowed(): void
    {
        $envelope = $this->explainPath('packages/semitexa-orm/src/OrmManager.php');

        $this->assertSame('orm', $envelope['package']);
        $this->assertSame('local', $envelope['rule_scope']);
        $this->assertSame('allowed', $envelope['status']);
        $this->assertTrue($envelope['public_api']);
        $this->assertTrue($envelope['is_file']);
        $this->assertFalse($envelope['is_directory']);
    }

    public function test_explains_orm_schema_as_invalid_unresolved_aq(): void
    {
        $envelope = $this->explainPath('packages/semitexa-orm/src/Schema');

        $this->assertSame('orm', $envelope['package']);
        $this->assertSame('invalid', $envelope['status']);
        $this->assertFalse($envelope['public_api']);
        $this->assertNotNull($envelope['suggested_action']);
        // Local extension docs ARE consulted (orm has a local extension), but
        // the rule scope is global because Schema is not authorised by it.
        $this->assertContains('packages/semitexa-orm/docs/MODULE_STRUCTURE.md', $envelope['docs_used']);
    }

    public function test_explains_adapter_in_non_orm_package_as_invalid(): void
    {
        $tmpAbs = sys_get_temp_dir() . '/path-cmd-' . uniqid();
        $relPkg = 'packages/semitexa-feature-x';
        $abs = $tmpAbs . '/' . $relPkg;
        mkdir($abs . '/src/Adapter', 0o777, true);
        file_put_contents($abs . '/composer.json', '{"name":"semitexa/feature-x"}');

        // Use the real spec (loaded relative to monorepo root) so the
        // command sees the orm local extension. Resolution is path-only,
        // so we explain the temp path against the real spec discovery —
        // which (correctly) does not find a local extension for feature-x.
        $envelope = $this->runAtRoot($tmpAbs, $relPkg . '/src/Adapter');

        $this->assertSame('feature-x', $envelope['package']);
        $this->assertSame('invalid', $envelope['status']);
        $this->assertSame('global', $envelope['rule_scope']);
        $this->assertFalse($envelope['public_api']);
        $this->assertNotEmpty($envelope['suggested_action']);
        // No local extension was loaded for feature-x.
        $this->assertNotContains('packages/semitexa-feature-x/config/module-structure.php', $envelope['executable_rules_used']);
    }

    public function test_explains_globally_canonical_path_without_local_extension(): void
    {
        // Use a real package that has no local extension — pick one
        // authoritatively known to lack one (e.g. semitexa-workflow).
        $envelope = $this->explainPath('packages/semitexa-workflow/src/Domain/Model');

        $this->assertSame('workflow', $envelope['package']);
        $this->assertSame('global', $envelope['rule_scope']);
        $this->assertSame('allowed', $envelope['status']);
        // No local-extension warnings, since workflow has no local extension.
        foreach ($envelope['warnings'] as $w) {
            $this->assertStringNotContainsString('local extension', $w);
        }
    }

    public function test_explains_outside_module_path(): void
    {
        $envelope = $this->explainPath('var/docs/some-note.md');

        $this->assertSame('outside_module', $envelope['status']);
        $this->assertSame('none', $envelope['rule_scope']);
        $this->assertNull($envelope['package']);
        $this->assertNull($envelope['module']);
    }

    // ---------- Feature-grouping inheritance (the Phase-3 ai:ask bug fix) ----------

    public function test_feature_grouped_subdir_under_application_service_is_allowed(): void
    {
        $envelope = $this->explainPath('packages/semitexa-orm/src/Application/Service/Transaction');

        $this->assertSame('allowed', $envelope['status'], 'feature-grouped subdir must agree with ai:verify (allowed)');
        $this->assertSame('global', $envelope['rule_scope']);
        $this->assertTrue($envelope['exists']);
        $this->assertStringContainsString("Application/Service", $envelope['reason']);
        $this->assertStringContainsString("allowFeatureGrouping", $envelope['reason']);
        $this->assertNotNull($envelope['rule']);
        // Rule is the inherited Application/Service rule, not a Transaction-specific one.
        $this->assertSame('Application/Service', $envelope['rule']['path']);
        $this->assertTrue($envelope['rule']['allowFeatureGrouping']);
        // Warning should mention the inheritance explicitly.
        $hasInheritanceWarning = false;
        foreach ($envelope['warnings'] as $w) {
            if (str_contains($w, 'feature-grouped child') && str_contains($w, 'Application/Service')) {
                $hasInheritanceWarning = true;
                break;
            }
        }
        $this->assertTrue($hasInheritanceWarning, 'expected an inheritance warning naming Application/Service');
    }

    public function test_feature_grouped_nested_file_is_allowed(): void
    {
        $envelope = $this->explainPath('packages/semitexa-orm/src/Application/Service/Transaction/TransactionManager.php');

        $this->assertSame('allowed', $envelope['status']);
        $this->assertSame('global', $envelope['rule_scope']);
        $this->assertTrue($envelope['exists']);
        $this->assertTrue($envelope['is_file']);
        $this->assertFalse($envelope['is_directory']);
        $this->assertStringContainsString("Application/Service", $envelope['reason']);
    }

    public function test_flat_file_directly_under_application_service_is_allowed(): void
    {
        $envelope = $this->explainPath('packages/semitexa-orm/src/Application/Service/Uuid7.php');

        $this->assertSame('allowed', $envelope['status']);
        $this->assertSame('global', $envelope['rule_scope']);
        $this->assertTrue($envelope['exists']);
        // Direct rule (not inherited): no inheritance warning expected.
        foreach ($envelope['warnings'] as $w) {
            $this->assertStringNotContainsString('feature-grouped child', $w);
        }
    }

    public function test_invalid_application_child_remains_invalid(): void
    {
        // Application/RandomFeature: Application allows only specific
        // sub-directories; arbitrary feature folders are rejected.
        $envelope = $this->explainPath('packages/semitexa-orm/src/Application/RandomFeature');

        // The path doesn't exist; for hypothetical paths under
        // non-feature-grouped parents we expect unresolved (no rule found
        // at any ancestor). The key contract is: NOT allowed.
        $this->assertNotSame('allowed', $envelope['status'],
            'arbitrary children under Application must not be classified as allowed');
    }

    public function test_orm_local_adapter_still_reports_local_scope(): void
    {
        // Regression guard: feature-grouping fix must not weaken local-extension reporting.
        $envelope = $this->explainPath('packages/semitexa-orm/src/Adapter');

        $this->assertSame('allowed', $envelope['status']);
        $this->assertSame('local', $envelope['rule_scope']);
        $this->assertTrue($envelope['public_api']);
    }

    public function test_orm_unresolved_aq_path_remains_invalid(): void
    {
        // Hydration is not in the local extension, not globally canonical.
        $envelope = $this->explainPath('packages/semitexa-orm/src/Hydration');

        $this->assertNotSame('allowed', $envelope['status'],
            'Hydration is an unresolved ORM AQ — must not be allowed');
    }

    public function test_hypothetical_feature_grouped_path_reports_allowed_with_exists_false(): void
    {
        // Path doesn't exist on disk, but Application/Service is feature-grouping enabled.
        $envelope = $this->explainPath('packages/semitexa-workflow/src/Application/Service/FutureFeature');

        $this->assertSame('allowed', $envelope['status']);
        $this->assertSame('global', $envelope['rule_scope']);
        $this->assertFalse($envelope['exists']);
        $hasHypotheticalWarning = false;
        foreach ($envelope['warnings'] as $w) {
            if (str_contains($w, 'hypothetical') || str_contains($w, 'does not exist')) {
                $hasHypotheticalWarning = true;
                break;
            }
        }
        $this->assertTrue($hasHypotheticalWarning, 'expected a hypothetical-path warning when path does not exist');
    }

    public function test_missing_path_option_fails_with_error_envelope(): void
    {
        $app = new Application();
        $app->add(new DevGraphPathCommand());
        $tester = new CommandTester($app->find('dev:graph:path'));
        $exit = $tester->execute([]);

        $this->assertSame(1, $exit);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame('error', $decoded['kind']);
        $this->assertStringContainsString('--path', $decoded['error']);
    }

    /** @return array<string, mixed> */
    private function explainPath(string $path): array
    {
        return $this->runAtRoot($this->projectRoot(), $path);
    }

    /** @return array<string, mixed> */
    private function runAtRoot(string $projectRoot, string $path): array
    {
        // ProjectRoot caches its first-seen root statically; reset so the
        // command picks up the chdir'd directory.
        $cwd = getcwd();
        ProjectRoot::reset();
        // Some hosts set SEMITEXA_PROJECT_ROOT — the constant short-circuits
        // ProjectRoot::get(). For the temp path tests we must NOT inherit
        // that, so we rely on chdir+reset.
        chdir($projectRoot);
        // ProjectRoot::get() also requires src/modules/ to exist at the cwd
        // candidate; ensure the temp project root has it so the candidate
        // matches and the command sees $tmpAbs as the project root.
        if (!is_dir($projectRoot . '/src/modules')) {
            mkdir($projectRoot . '/src/modules', 0o777, true);
        }
        if (!is_file($projectRoot . '/composer.json')) {
            file_put_contents($projectRoot . '/composer.json', '{}');
        }
        try {
            $app = new Application();
            $app->add(new DevGraphPathCommand());
            $tester = new CommandTester($app->find('dev:graph:path'));
            $tester->execute(['--path' => $path, '--json' => true]);
            $out = trim($tester->getDisplay());
            return json_decode($out, true) ?? ['_raw' => $out];
        } finally {
            if ($cwd !== false) {
                chdir($cwd);
            }
            ProjectRoot::reset();
        }
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}

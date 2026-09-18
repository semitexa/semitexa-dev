<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Phpstan;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\PhpstanRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\PhpstanRunResult;

final class ConsumerPhpstanConfigTest extends TestCase
{
    /** How every config addresses the one rule list. */
    private const RULE_LIST_INCLUDE = '%currentWorkingDirectory%/vendor/semitexa/core/config/phpstan-rules.neon';

    public function testWorkspaceAndConsumerShareTheExactRuleList(): void
    {
        $config = dirname(__DIR__, 5) . '/config/';
        foreach (['phpstan-ai-verify.neon', 'phpstan-ai-verify-consumer.neon'] as $file) {
            $contents = file_get_contents($config . $file);
            self::assertStringContainsString(self::RULE_LIST_INCLUDE, $contents);
            self::assertDoesNotMatchRegularExpression('/^rules:/m', $contents);
        }

        $rules = file_get_contents(dirname(__DIR__, 6) . '/semitexa-core/config/phpstan-rules.neon');
        self::assertStringContainsString('MapperTypeConversionRule', $rules);
        self::assertStringContainsString('InjectionViaConstructorRule', $rules);
        self::assertStringNotContainsString('/packages/', file_get_contents($config . 'phpstan-ai-verify-consumer.neon'));
    }

    /**
     * Every config that runs the Semitexa rules names the list the same way,
     * and that way survives both layouts.
     *
     * Composer installs a package under its PACKAGE name, so semitexa/core is
     * `vendor/semitexa/core` in a consumer while the authoring checkout is
     * `packages/semitexa-core`. A RELATIVE include is therefore right in one
     * layout and broken in the other — and the workspace cannot feel it,
     * because `vendor/semitexa/dev` is a symlink to `packages/semitexa-dev`,
     * so a relative path resolves from the real location and finds the file
     * anyway. Addressing the list through the cwd removes the question.
     */
    public function testEveryConfigAddressesTheRuleListTheSameWay(): void
    {
        $root = dirname(__DIR__, 7);
        $configs = [
            'phpstan.neon',
            'packages/semitexa-dev/config/phpstan-ai-verify.neon',
            'packages/semitexa-dev/config/phpstan-ai-verify-consumer.neon',
            'packages/semitexa-dev/resources/phpstan/phpstan.neon',
            'packages/semitexa-ultimate/phpstan.neon',
        ];

        foreach ($configs as $relative) {
            $contents = file_get_contents($root . '/' . $relative);
            self::assertIsString($contents, $relative . ' is unreadable');
            self::assertStringContainsString(self::RULE_LIST_INCLUDE, $contents, $relative . ' does not include the shared rule list');
            self::assertStringNotContainsString(
                '../../semitexa-core/',
                $contents,
                $relative . ' uses a relative path that is wrong in a consumer install',
            );
        }

        // And the address resolves here, where vendor/semitexa/core is the symlink.
        self::assertFileExists($root . '/vendor/semitexa/core/config/phpstan-rules.neon');
    }

    public function testActualAnalysisInAVendorOnlyProject(): void
    {
        $installedRoot = __DIR__;
        while (!is_file($installedRoot . '/vendor/bin/phpstan') && dirname($installedRoot) !== $installedRoot) {
            $installedRoot = dirname($installedRoot);
        }
        self::assertFileExists($installedRoot . '/vendor/bin/phpstan', 'this regression requires the installed analyser, not a fake green');
        $root = sys_get_temp_dir() . '/semitexa-consumer-analysis-' . bin2hex(random_bytes(8));
        mkdir($root . '/src', 0755, true);
        symlink($installedRoot . '/vendor', $root . '/vendor');
        file_put_contents($root . '/composer.json', '{"name":"fixture/consumer"}');
        file_put_contents($root . '/src/Probe.php', '<?php namespace VerificationConsumerFixture; final class Probe {}');
        try {
            self::assertDirectoryDoesNotExist($root . '/packages');
            $runner = new PhpstanRunner($root);
            $clean = $runner->run(['src/Probe.php']);
            self::assertSame(PhpstanRunResult::STATUS_PASS, $clean->status, $clean->rawSignal);

            file_put_contents($root . '/src/Probe.php', <<<'PHP'
<?php
namespace VerificationConsumerFixture;
#[\Semitexa\Core\Attribute\AsService]
final class Probe {
    public function __construct(private \stdClass $dependency) {}
}
PHP);
            $invalid = $runner->run(['src/Probe.php']);
            self::assertSame(PhpstanRunResult::STATUS_FAIL, $invalid->status, $invalid->rawSignal);
            self::assertContains('semitexa.injectionViaConstructor', array_column($invalid->diagnostics, 'identifier'));
        } finally {
            unlink($root . '/src/Probe.php');
            unlink($root . '/composer.json');
            unlink($root . '/vendor');
            rmdir($root . '/src');
            rmdir($root);
        }
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Phpstan;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\PhpstanRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\PhpstanRunResult;

final class ConsumerPhpstanConfigTest extends TestCase
{
    public function testWorkspaceAndConsumerShareTheExactRuleList(): void
    {
        $config = dirname(__DIR__, 5) . '/config/';
        foreach (['phpstan-ai-verify.neon', 'phpstan-ai-verify-consumer.neon'] as $file) {
            $contents = file_get_contents($config . $file);
            self::assertStringContainsString('- phpstan-ai-verify-rules.neon', $contents);
            self::assertDoesNotMatchRegularExpression('/^rules:/m', $contents);
        }
        $rules = file_get_contents($config . 'phpstan-ai-verify-rules.neon');
        self::assertStringContainsString('MapperTypeConversionRule', $rules);
        self::assertStringContainsString('InjectionViaConstructorRule', $rules);
        self::assertStringNotContainsString('/packages/', file_get_contents($config . 'phpstan-ai-verify-consumer.neon'));
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

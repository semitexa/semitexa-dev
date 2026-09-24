<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The release's phpstan ceiling is a versioned number, not a default an
 * environment variable can replace.
 *
 * It was `${PHPSTAN_CEILING:-179}` in the release script: one machine's shell
 * could raise the bar every release is held to without a commit anyone saw.
 */
final class PhpstanCeilingIsVersionedTest extends TestCase
{
    private const CEILING = __DIR__ . '/../../../resources/phpstan/phpstan-ceiling.json';
    private const GATE = __DIR__ . '/../../../resources/skills/release-readiness/scripts/release-auto-checks.sh';

    #[Test]
    public function the_ceiling_file_holds_a_number_and_the_analyser_it_was_measured_with(): void
    {
        $data = json_decode((string) file_get_contents(self::CEILING), true);

        self::assertIsArray($data, 'phpstan-ceiling.json must be valid JSON');
        self::assertIsInt($data['ceiling'] ?? null);
        self::assertGreaterThanOrEqual(0, $data['ceiling']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) ($data['analyser'] ?? ''));
        self::assertIsArray($data['deliberate'] ?? null, 'every raise needs somewhere to say why');
    }

    #[Test]
    public function the_release_gate_takes_no_override_from_the_environment(): void
    {
        $gate = (string) file_get_contents(self::GATE);

        // A DEFAULT is the override: `${VAR:-179}` means "179 unless the shell
        // says otherwise". An empty `${VAR:-}` is only how the gate detects and
        // refuses someone still setting it.
        self::assertDoesNotMatchRegularExpression('/\$\{PHPSTAN_(CEILING|EXPECTED_ANALYSER):-[^}]/', $gate);
        self::assertStringContainsString('are no longer read from the environment', $gate);
        self::assertStringContainsString('resources/phpstan/phpstan-ceiling.json', $gate);
    }
}

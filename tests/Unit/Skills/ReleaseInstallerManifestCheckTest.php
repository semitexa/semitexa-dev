<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Review on dev#283: the preflight stage installer-manifest-fresh ran the derive
 * tool through `docker compose exec -T app`, and it runs before any container is
 * up. A stopped app container failed the exec, and the failure was reported as a
 * stale manifest. The tool only reads files, so the stage runs it on the host,
 * and only the tool's own "stale" answer (exit 1) is called stale.
 */
final class ReleaseInstallerManifestCheckTest extends TestCase
{
    private const STALE = 'no longer matches';
    private const BROKEN = 'Could not check';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/installer-manifest-check-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/packages/semitexa-installer/tools', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_current_manifest_passes_with_no_container_to_exec_into(): void
    {
        $this->tool(0, 'scaffold-manifest.json is current (19 files).');

        [$status, $output] = $this->check();

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('is current', $output);
        self::assertStringNotContainsString(self::STALE, $output);
    }

    #[Test]
    public function a_stale_manifest_fails_with_the_command_that_derives_it_again(): void
    {
        $this->tool(1, 'scaffold-manifest.json is stale');

        [$status, $output] = $this->check();

        self::assertSame(1, $status, $output);
        self::assertStringContainsString(self::STALE, $output);
        self::assertStringContainsString('php packages/semitexa-installer/tools/derive-manifest.php', $output);
    }

    #[Test]
    public function a_tool_that_cannot_read_its_source_is_an_error_not_a_stale_manifest(): void
    {
        $this->tool(2, "Cannot read semitexa/update's manifest");

        [$status, $output] = $this->check();

        self::assertSame(2, $status, $output);
        self::assertStringContainsString(self::BROKEN, $output);
        self::assertStringContainsString("Cannot read semitexa/update's manifest", $output);
        self::assertStringNotContainsString(self::STALE, $output);
    }

    #[Test]
    public function a_tool_that_crashes_is_an_error_not_a_stale_manifest(): void
    {
        file_put_contents(
            $this->root . '/packages/semitexa-installer/tools/derive-manifest.php',
            "<?php\nthrow new \\RuntimeException('broken tool');\n",
        );

        [$status, $output] = $this->check();

        self::assertSame(255, $status, $output);
        self::assertStringContainsString(self::BROKEN, $output);
        self::assertStringNotContainsString(self::STALE, $output);
    }

    #[Test]
    public function a_release_whose_installer_has_no_derive_tool_passes(): void
    {
        self::assertFileDoesNotExist($this->root . '/packages/semitexa-installer/tools/derive-manifest.php');

        [$status, $output] = $this->check();

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('nothing to check', $output);
    }

    private function tool(int $exit, string $message): void
    {
        $stream = $exit === 0 ? 'STDOUT' : 'STDERR';
        file_put_contents(
            $this->root . '/packages/semitexa-installer/tools/derive-manifest.php',
            sprintf("<?php\nif ((\$argv[1] ?? '') !== '--check') { exit(9); }\nfwrite(%s, %s . \"\\n\");\nexit(%d);\n", $stream, var_export($message, true), $exit),
        );
    }

    /**
     * Runs the stage with a PATH that holds php and no docker, as on a host
     * where the dev stack is down.
     *
     * @return array{int, string}
     */
    private function check(): array
    {
        $script = dirname(__DIR__, 3) . '/resources/skills/release-readiness/scripts/release-installer-manifest-check.sh';
        $php = dirname(PHP_BINARY);
        exec(sprintf(
            'env -i PATH=%s HOME=%s DEV_ROOT=%s bash %s 2>&1',
            escapeshellarg($php . ':/usr/bin:/bin'),
            escapeshellarg($this->root),
            escapeshellarg($this->root),
            escapeshellarg($script),
        ), $out, $status);

        return [$status, implode("\n", $out)];
    }
}

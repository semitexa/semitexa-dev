<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The BC gate of the stability contract: roave's breaks since a package's
 * previous tag, kept only on the @api surface of that tag, and refused unless
 * the symbol had been deprecated 90 days before the release.
 *
 * Built on a real tag; roave itself is stood in for by a script printing its
 * JSON (its real output, checked by hand on 2026-10-30: "Method Probe\Clock#old()
 * was removed", exit 3), so the test needs neither bcmath nor intl.
 */
final class BcGateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-bc-' . uniqid('', true);
        mkdir($this->root . '/packages/semitexa-core/src', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private const BREAKS = ['Method Semitexa\\Core\\Clock#old() was removed', 'Method Semitexa\\Core\\Inner#gone() was removed', 'Method b() was added to interface Semitexa\\Core\\Port'];

    #[Test]
    public function a_break_on_the_api_surface_fails_the_cut_and_one_off_it_is_ignored(): void
    {
        $this->tagged('');

        [$status, $out] = $this->gate(self::BREAKS);

        self::assertSame(1, $status);
        self::assertSame([
            '[BREAK] semitexa-core (since 2026.07.01.0900): Method Semitexa\\Core\\Clock#old() was removed — @api and never deprecated',
            '[BREAK] semitexa-core (since 2026.07.01.0900): Method b() was added to interface Semitexa\\Core\\Port — @api and never deprecated',
        ], $out, 'Inner is not @api: its break is not the contract\'s');
    }

    #[Test]
    public function a_part_deprecated_ninety_days_before_may_go(): void
    {
        // 2026.07.01 to the cut on 2026.10.31: 122 days.
        $this->tagged('/** @deprecated 2026.07.01 Use now() instead. */');

        [$status, $out] = $this->gate([self::BREAKS[0]]);

        self::assertSame([0, [
            '[ALLOWED] semitexa-core (since 2026.07.01.0900): Method Semitexa\\Core\\Clock#old() was removed — deprecated 2026.07.01, 122 days ago',
            '1 break(s), each deprecated long enough.',
        ]], [$status, $out]);
    }

    #[Test]
    public function a_part_deprecated_too_recently_may_not(): void
    {
        $this->tagged('/** @deprecated 2026.10.01 Use now() instead. */');

        [$status, $out] = $this->gate([self::BREAKS[0]]);

        self::assertSame([1, ['[BREAK] semitexa-core (since 2026.07.01.0900): Method Semitexa\\Core\\Clock#old() was removed — @api, deprecated 2026.10.01, only 30 of 90 days ago']], [$status, $out]);
    }

    /** A package that promised nothing at its tag costs nothing: roave is not run for it. */
    #[Test]
    public function a_package_without_api_at_its_tag_is_not_checked(): void
    {
        $this->tagged('', api: false);

        [$status, $out] = $this->gate(null);

        self::assertSame([0, ['No break on the @api surface.']], [$status, $out]);
    }

    /** "No break" read from a roave that could not check would pass a break. */
    #[Test]
    public function roave_that_cannot_check_fails_the_gate(): void
    {
        $this->tagged('');

        [$status, $out] = $this->gate(null);

        self::assertSame([1, ['[UNCHECKED] semitexa-core: roave did not check it (exit 2)']], [$status, $out]);
    }

    private function tagged(string $oldDoc, bool $api = true): void
    {
        $dir = $this->root . '/packages/semitexa-core';
        $mark = $api ? '/** @api */' : '';
        file_put_contents($dir . '/composer.json', '{"name":"semitexa/core"}');
        file_put_contents($dir . '/src/Clock.php', "<?php\nnamespace Semitexa\\Core;\n{$mark}\nfinal class Clock\n{\n    public function now(): int { return 0; }\n    {$oldDoc}\n    public function old(): int { return 1; }\n}\n");
        file_put_contents($dir . '/src/Inner.php', "<?php\nnamespace Semitexa\\Core;\nfinal class Inner { public function gone(): void {} }\n");
        file_put_contents($dir . '/src/Port.php', "<?php\nnamespace Semitexa\\Core;\n{$mark}\ninterface Port { public function a(): void; }\n");
        foreach (['init -q', 'config user.email t@example.test', 'config user.name T', 'add -A', 'commit -q -m released', 'tag 2026.07.01.0900', 'commit -q --allow-empty -m after'] as $command) {
            exec(sprintf('git -C %s %s 2>&1', escapeshellarg($dir), $command), $o, $exit);
            self::assertSame(0, $exit, "git {$command}: " . implode("\n", $o));
        }
    }

    /**
     * @param list<string>|null $breaks what the stand-in roave reports; null: it fails (exit 2)
     * @return array{int, list<string>}
     */
    private function gate(?array $breaks): array
    {
        $fake = $this->root . '/roave';
        $json = json_encode(['errors' => array_map(static fn (string $d): array => ['description' => $d], $breaks ?? [])]);
        file_put_contents($fake, $breaks === null ? "#!/bin/sh\nexit 2\n" : "#!/bin/sh\nprintf '%s' " . escapeshellarg((string) $json) . "\nexit " . ($breaks === [] ? 0 : 3) . "\n");
        chmod($fake, 0o755);
        $loader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        self::assertIsString($loader);
        $script = dirname(__DIR__, 2) . '/resources/skills/release-readiness/scripts/release-bc-gate.php';
        exec(sprintf(
            'RELEASE_ROOT=%s SEMITEXA_AUTOLOAD=%s SEMITEXA_BC_CHECK=%s php %s --version=2026.10.31.0900 2>&1',
            escapeshellarg($this->root),
            escapeshellarg(dirname($loader, 2) . '/autoload.php'),
            escapeshellarg($fake),
            escapeshellarg($script),
        ), $out, $status);

        return [$status, $out];
    }
}

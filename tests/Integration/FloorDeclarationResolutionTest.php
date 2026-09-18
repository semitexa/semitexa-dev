<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An author declares WHICH dependency needs a floor; the release writes WHEN.
 *
 * A floor is a guess about a date until the tag exists, and the guess goes stale
 * the moment a release slips — measured 2026-09-16, when semitexa/os floored
 * semitexa/prompt at the day the author expected the cut, review ran a day past
 * it, and preflight died on "that tag is not in semitexa-prompt".
 *
 * Run against the shipped script through its RELEASE_ROOT seam, so this tests
 * what the release actually executes rather than a copy of its rule.
 */
final class FloorDeclarationResolutionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-floors-' . uniqid('', true);
        mkdir($this->root . '/packages/semitexa-ssr', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/packages/semitexa-ssr/composer.json');
        @rmdir($this->root . '/packages/semitexa-ssr');
        @rmdir($this->root . '/packages');
        @rmdir($this->root);
    }

    /** @param array<string, mixed> $composer */
    private function writePackage(array $composer): void
    {
        file_put_contents(
            $this->root . '/packages/semitexa-ssr/composer.json',
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        );
    }

    /** @return array{exit: int, output: string} */
    private function resolve(string $mode, string $version = '', string $set = ''): array
    {
        $script = dirname(__DIR__, 2) . '/resources/skills/release-readiness/scripts/release-resolve-floors.php';

        $command = sprintf(
            'RELEASE_ROOT=%s RELEASE_VERSION=%s RELEASE_SET=%s php %s %s 2>&1',
            escapeshellarg($this->root),
            escapeshellarg($version),
            escapeshellarg($set),
            escapeshellarg($script),
            $mode,
        );

        $output = [];
        $exit = 0;
        exec($command, $output, $exit);

        return ['exit' => $exit, 'output' => implode("\n", $output)];
    }

    /** @return array<string, mixed> */
    private function readPackage(): array
    {
        $json = json_decode((string) file_get_contents($this->root . '/packages/semitexa-ssr/composer.json'), true);
        self::assertIsArray($json);

        return $json;
    }

    /** @return array<string, mixed> */
    private function declaringPackage(): array
    {
        return [
            'name' => 'semitexa/ssr',
            'require' => ['semitexa/core' => '*'],
            'extra' => ['semitexa' => ['floors' => ['semitexa/core' => 'next']]],
        ];
    }

    /**
     * THE POINT OF THE WHOLE THING: the version is written once, by the release,
     * and the declaration records what it resolved to.
     */
    #[Test]
    public function the_release_dates_a_declared_floor(): void
    {
        $this->writePackage($this->declaringPackage());

        $result = $this->resolve('--confirm', '2026.09.18.1500', 'semitexa/ssr,semitexa/core');

        self::assertSame(0, $result['exit'], $result['output']);

        $composer = $this->readPackage();
        self::assertSame('>=2026.09.18.1500 || dev-master', $composer['require']['semitexa/core']);
        self::assertSame('2026.09.18.1500', $composer['extra']['semitexa']['floors']['semitexa/core']);
    }

    /**
     * `|| dev-master` is not decoration. Packages are developed as path
     * repositories, where composer takes the version from the branch, and a
     * branch version satisfies no date floor — so a floor without the escape
     * makes the workspace uninstallable while protecting nobody.
     */
    #[Test]
    public function the_written_floor_admits_the_local_checkout(): void
    {
        $this->writePackage($this->declaringPackage());

        $this->resolve('--confirm', '2026.09.18.1500', 'semitexa/ssr,semitexa/core');

        self::assertStringContainsString('|| dev-master', $this->readPackage()['require']['semitexa/core']);
    }

    /** Resolving twice is a no-op: `next` means the cut it was resolved in, not the newest one. */
    #[Test]
    public function a_dated_floor_is_not_moved_by_the_next_release(): void
    {
        $this->writePackage($this->declaringPackage());
        $this->resolve('--confirm', '2026.09.18.1500', 'semitexa/ssr,semitexa/core');

        $result = $this->resolve('--check', '2026.09.19.0900', 'semitexa/ssr,semitexa/core');

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame('>=2026.09.18.1500 || dev-master', $this->readPackage()['require']['semitexa/core']);
    }

    /**
     * A dependency that is not being tagged cannot have grown the API the floor
     * is for, so the declaration is an authoring mistake. Resolving it anyway
     * would write a floor at a version that says nothing about the class or
     * method it was meant to guard.
     */
    #[Test]
    public function a_floor_on_a_package_outside_the_release_set_is_refused(): void
    {
        $this->writePackage($this->declaringPackage());

        $result = $this->resolve('--confirm', '2026.09.18.1500', 'semitexa/ssr,semitexa/demo');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('is not being tagged', $result['output']);
        self::assertSame('*', $this->readPackage()['require']['semitexa/core'], 'a refused resolution writes nothing');
    }

    /**
     * Preflight's job: a cut may not proceed while a floor still says "next",
     * because the package would ship a constraint that guards nothing.
     */
    #[Test]
    public function check_fails_while_a_declaration_is_undated(): void
    {
        $this->writePackage($this->declaringPackage());

        $result = $this->resolve('--check', '2026.09.18.1500', 'semitexa/ssr,semitexa/core');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('still name the release', $result['output']);
        self::assertSame('*', $this->readPackage()['require']['semitexa/core'], 'check changes nothing');
    }

    /**
     * And on develop, where there is no version yet, the same state is reported
     * as what it is rather than resolved to something invented.
     */
    #[Test]
    public function without_a_release_version_it_says_so_and_writes_nothing(): void
    {
        $this->writePackage($this->declaringPackage());

        $result = $this->resolve('--check');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('RELEASE_VERSION is not set', $result['output']);
        self::assertSame('*', $this->readPackage()['require']['semitexa/core']);
    }

    /** A tree with nothing declared is silent and successful. */
    #[Test]
    public function a_package_that_declares_no_floor_is_left_alone(): void
    {
        $this->writePackage(['name' => 'semitexa/ssr', 'require' => ['semitexa/core' => '*']]);

        $result = $this->resolve('--check', '2026.09.18.1500', 'semitexa/ssr,semitexa/core');

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame('*', $this->readPackage()['require']['semitexa/core']);
    }
}

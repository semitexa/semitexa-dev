<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The report that would have caught `Request::getServedPath()`.
 *
 * The constraint gate asks whether a promised release DECLARES every sibling
 * CLASS a package imports, which catches a moved class and misses the commonest
 * case entirely: a new public METHOD on a class that has existed for months.
 * MEASURED 2026-09-18 — ssr began calling a method core had never released,
 * while the gate was satisfied because `Request` itself has shipped forever.
 *
 * Built on a real pair of git revisions, because the whole mechanism is "what
 * changed between the last tag and now" and a fixture that fakes that tests
 * nothing.
 */
final class NewPublicApiReportTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-newapi-' . uniqid('', true);
        mkdir($this->root . '/packages/semitexa-core/src', 0777, true);
        mkdir($this->root . '/packages/semitexa-ssr/src', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function git(string $dir, string $command): void
    {
        exec(sprintf('git -C %s %s 2>&1', escapeshellarg($dir), $command), $out, $exit);
        self::assertSame(0, $exit, "git {$command} failed: " . implode("\n", $out));
    }

    /**
     * A provider package with one released tag, then a change on top of it.
     */
    private function provider(string $released, string $afterTag): void
    {
        $dir = $this->root . '/packages/semitexa-core';
        file_put_contents($dir . '/composer.json', json_encode(['name' => 'semitexa/core'], JSON_PRETTY_PRINT));
        file_put_contents($dir . '/src/Request.php', $released);

        $this->git($dir, 'init -q');
        $this->git($dir, 'config user.email test@example.com');
        $this->git($dir, 'config user.name Test');
        $this->git($dir, 'add -A');
        $this->git($dir, 'commit -q -m released');
        $this->git($dir, 'tag 2026.09.17.1037');

        file_put_contents($dir . '/src/Request.php', $afterTag);
    }

    /** @param array<string, mixed> $composer */
    private function dependent(array $composer, string $source): void
    {
        $dir = $this->root . '/packages/semitexa-ssr';
        file_put_contents($dir . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT));
        file_put_contents($dir . '/src/ShellResponder.php', $source);
    }

    private function report(): string
    {
        $script = dirname(__DIR__, 2) . '/resources/skills/release-readiness/scripts/release-new-public-api.php';

        $output = [];
        exec(sprintf(
            'RELEASE_ROOT=%s php %s 2>&1',
            escapeshellarg($this->root),
            escapeshellarg($script),
        ), $output);

        return implode("\n", $output);
    }

    private const RELEASED = <<<'PHP'
        <?php
        namespace Semitexa\Core;
        class Request
        {
            public function getPath(): string { return '/'; }
        }
        PHP;

    private const GROWN = <<<'PHP'
        <?php
        namespace Semitexa\Core;
        class Request
        {
            public function getPath(): string { return '/'; }
            public function getServedPath(): string { return '/'; }
        }
        PHP;

    private const USES_REQUEST = <<<'PHP'
        <?php
        namespace Semitexa\Ssr;
        use Semitexa\Core\Request;
        class ShellResponder
        {
            public function url(Request $request): string { return $request->getServedPath(); }
        }
        PHP;

    #[Test]
    public function a_new_public_method_is_reported_with_the_packages_that_use_the_class(): void
    {
        $this->provider(self::RELEASED, self::GROWN);
        $this->dependent(['name' => 'semitexa/ssr', 'require' => ['semitexa/core' => '*']], self::USES_REQUEST);

        $report = $this->report();

        self::assertStringContainsString('semitexa/core (since 2026.09.17.1037)', $report);
        self::assertStringContainsString('getServedPath()', $report);
        self::assertStringContainsString('semitexa/ssr', $report);
        self::assertStringNotContainsString('getPath()', $report, 'a method that was already released is not news');
    }

    /**
     * A package that has already declared a floor on this provider has answered
     * the question, and asking it again is how a report becomes wallpaper.
     */
    #[Test]
    public function a_dependent_that_already_declared_a_floor_is_not_asked_again(): void
    {
        $this->provider(self::RELEASED, self::GROWN);
        $this->dependent([
            'name' => 'semitexa/ssr',
            'require' => ['semitexa/core' => '*'],
            'extra' => ['semitexa' => ['floors' => ['semitexa/core' => 'next']]],
        ], self::USES_REQUEST);

        self::assertStringContainsString('No package in this set grew public API', $this->report());
    }

    /** A private addition cannot be what a sibling calls. */
    #[Test]
    public function a_private_addition_is_not_public_api(): void
    {
        $private = <<<'PHP'
            <?php
            namespace Semitexa\Core;
            class Request
            {
                public function getPath(): string { return '/'; }
                private function normalise(): string { return '/'; }
            }
            PHP;

        $this->provider(self::RELEASED, $private);
        $this->dependent(['name' => 'semitexa/ssr', 'require' => ['semitexa/core' => '*']], self::USES_REQUEST);

        self::assertStringContainsString('No package in this set grew public API', $this->report());
    }

    /** And a package that does not depend on the provider is not asked about it. */
    #[Test]
    public function a_package_that_does_not_require_the_provider_is_left_out(): void
    {
        $this->provider(self::RELEASED, self::GROWN);
        $this->dependent(['name' => 'semitexa/ssr', 'require' => []], self::USES_REQUEST);

        self::assertStringContainsString('No package in this set grew public API', $this->report());
    }
}

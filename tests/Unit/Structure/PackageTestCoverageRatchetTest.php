<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A ratchet on packages that ship no tests.
 *
 * Not a scold. The concrete harm is that `test:run packages/semitexa-<name>`
 * exits 2 on a package with no tests/ directory, so the per-package command the
 * project documents does not work for these — and a package added tomorrow
 * joins them silently.
 *
 * The list only shrinks. Covering a package fails this test until its line is
 * removed, which is the point: the count in a backlog entry goes stale, a list
 * that must be edited does not.
 *
 * Packages with no PHP source at all are excluded rather than listed. The
 * browser extension and the installer scaffold have nothing to unit-test, and a
 * standard that demands tests for them is a standard people learn to ignore.
 */
final class PackageTestCoverageRatchetTest extends TestCase
{
    /** Packages with PHP source and no tests: name => source file count. */
    private const UNTESTED = [
        'semitexa-demo' => 327,
        'semitexa-files' => 4,
        'semitexa-mail' => 42,
        'semitexa-music' => 4,
        'semitexa-showcase-kit' => 4,
        'semitexa-theme-sky' => 1,
        'semitexa-workflow' => 42,
    ];

    #[Test]
    public function no_new_package_ships_without_tests(): void
    {
        $new = [];

        foreach ($this->packagesWithSource() as $name => $sourceFiles) {
            if ($this->hasTests($name) || array_key_exists($name, self::UNTESTED)) {
                continue;
            }

            $new[] = sprintf('%s (%d source files)', $name, $sourceFiles);
        }

        sort($new);

        self::assertSame(
            [],
            $new,
            "These packages have PHP source and no tests, and are not on the recorded list. "
            . "Add a test rather than a line:\n  - " . implode("\n  - ", $new),
        );
    }

    /**
     * The ratchet half. A package that grew tests must leave the list, or the
     * list stops describing anything and the next untested package hides in it.
     */
    #[Test]
    public function a_covered_package_leaves_the_list(): void
    {
        $covered = [];

        foreach (array_keys(self::UNTESTED) as $name) {
            if ($this->hasTests($name)) {
                $covered[] = $name;
            }
        }

        sort($covered);

        self::assertSame(
            [],
            $covered,
            "These now ship tests — remove them from UNTESTED:\n  - " . implode("\n  - ", $covered),
        );
    }

    /** A package that disappeared must not linger as a line nobody can act on. */
    #[Test]
    public function the_list_names_only_packages_that_exist(): void
    {
        $gone = [];
        $present = $this->packagesWithSource();

        foreach (array_keys(self::UNTESTED) as $name) {
            if (!array_key_exists($name, $present)) {
                $gone[] = $name;
            }
        }

        sort($gone);

        self::assertSame([], $gone, "Recorded but no longer a PHP package:\n  - " . implode("\n  - ", $gone));
    }

    /** @return array<string, int> package name => PHP source file count */
    private function packagesWithSource(): array
    {
        $out = [];

        foreach ((array) glob($this->packagesDir() . '/semitexa-*', GLOB_ONLYDIR) as $dir) {
            $count = count($this->phpFiles((string) $dir . '/src'));
            if ($count > 0) {
                $out[basename((string) $dir)] = $count;
            }
        }

        return $out;
    }

    private function hasTests(string $name): bool
    {
        foreach ($this->phpFiles($this->packagesDir() . '/' . $name . '/tests') as $file) {
            if (str_ends_with($file, 'Test.php')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    private function packagesDir(): string
    {
        return dirname(__DIR__, 4);
    }
}

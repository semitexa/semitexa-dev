<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Regression guard for the dev / update / orm ownership boundary.
 *
 * semitexa-dev is for developer tooling only. Update lifecycle, schema migrations,
 * and data patches live in semitexa-update + semitexa-orm respectively. If any of
 * those concerns drift back into Dev, this test fires.
 *
 * Companion docs: var/docs/UPDATE_DEV_ORM_OWNERSHIP_AUDIT.md, epic ep-update-dev-orm-ownership.
 */
final class OwnershipBoundaryTest extends TestCase
{
    public function testDevDoesNotContainDeploymentNamespace(): void
    {
        $root = __DIR__ . '/../../src';
        self::assertDirectoryExists($root);

        $offending = $this->findFiles(
            $root,
            static fn (string $relative): bool =>
                str_contains($relative, '/Deployment/')
                || str_contains($relative, '/RemoteDeployment/')
                || preg_match('/(^|\/)Deployment(\.php|\/)/', $relative) === 1
                || preg_match('/(^|\/)RemoteDeployment(\.php|\/)/', $relative) === 1,
        );

        self::assertSame(
            [],
            $offending,
            "semitexa-dev must not contain Deployment/ or RemoteDeployment/ — those moved to semitexa-update/Packaging.\n"
            . 'Offending files: ' . implode(', ', $offending),
        );
    }

    public function testDevDoesNotDeclareDeployCommands(): void
    {
        // Phase 6 migration: semitexa-dev's commands moved from
        // src/Console/Command/ → src/Application/Console/Command/.
        $root = __DIR__ . '/../../src/Application/Console/Command';
        self::assertDirectoryExists($root);

        $offending = $this->findFiles(
            $root,
            static fn (string $relative): bool =>
                preg_match('/(^|\/)Deploy(?:[A-Z][A-Za-z]*)?Command\.php$/', $relative) === 1,
        );

        self::assertSame(
            [],
            $offending,
            "semitexa-dev must not declare any Deploy*Command — those moved to semitexa-update as update:packages:*.\n"
            . 'Offending files: ' . implode(', ', $offending),
        );
    }

    public function testDevCapabilityRegistryDoesNotAdvertiseUpdateLifecycle(): void
    {
        $registry = file_get_contents(__DIR__ . '/../../src/Capability/CapabilityRegistry.php');
        self::assertIsString($registry);

        foreach (['deploy:auto', 'deploy:check', 'deploy:bootstrap-remote', 'deploy:materialize-release-composer'] as $name) {
            self::assertStringNotContainsString(
                "name: '{$name}'",
                $registry,
                "CapabilityRegistry must not advertise '{$name}' — that command lives in semitexa-update.",
            );
        }
    }

    /**
     * @param callable(string): bool $matches
     * @return list<string>
     */
    private function findFiles(string $root, callable $matches): array
    {
        $rootReal = realpath($root);
        self::assertNotFalse($rootReal, "Expected path to resolve: {$root}");
        self::assertDirectoryExists($rootReal);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootReal, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $offending = [];
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            $relative = substr($item->getPathname(), strlen($rootReal));
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            if ($matches($relative)) {
                $offending[] = $relative;
            }
        }

        sort($offending);
        return $offending;
    }
}

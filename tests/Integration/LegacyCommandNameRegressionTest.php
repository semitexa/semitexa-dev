<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guard against the six removed legacy command names silently coming back
 * into prompts, docs, tests, or source. These names were deleted in the
 * breaking cleanup that promoted `ai:ask` + `dev:graph:*` + `logs:app` to
 * the canonical introspection surface; any new reference is a mistake.
 */
class LegacyCommandNameRegressionTest extends TestCase
{
    /**
     * Kept as individual pieces so this source file never contains the full
     * literal strings the guard rejects.
     *
     * @return list<string>
     */
    private static function forbiddenNames(): array
    {
        return [
            'ai' . ':' . 'capabilities',
            'ai' . ':' . 'mine-conventions',
            'describe' . ':' . 'project',
            'describe' . ':' . 'module',
            'describe' . ':' . 'route',
            'describe' . ':' . 'event',
        ];
    }

    /** @return list<string> */
    private static function skippedTopLevelDirs(): array
    {
        return [
            'vendor',
            'node_modules',
            'var',
            '.git',
            '.phpunit.cache',
            '.idea',
            '.cache',
            'build',
            'dist',
            'coverage',
        ];
    }

    /** @return list<string> */
    private static function scannedExtensions(): array
    {
        return ['php', 'md', 'yaml', 'yml', 'json', 'neon', 'twig', 'xml', 'txt', 'html'];
    }

    public function test_no_legacy_command_names_remain_in_repository(): void
    {
        $root = realpath(__DIR__ . '/../..');
        $this->assertIsString($root, 'repository root not resolvable');

        $forbidden = self::forbiddenNames();
        $scannedExtensions = array_flip(self::scannedExtensions());
        $selfPath = realpath(__FILE__);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS,
            ),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        $violations = [];

        foreach ($iterator as $entry) {
            /** @var SplFileInfo $entry */
            if ($this->isSkipped($entry, $root)) {
                if ($entry->isDir()) {
                    $iterator->next();
                }
                continue;
            }
            if (!$entry->isFile()) {
                continue;
            }
            if ($entry->getRealPath() === $selfPath) {
                continue;
            }
            $ext = strtolower($entry->getExtension());
            if (!isset($scannedExtensions[$ext])) {
                continue;
            }

            $contents = @file_get_contents($entry->getPathname());
            if ($contents === false || $contents === '') {
                continue;
            }

            foreach (explode("\n", $contents) as $lineIndex => $line) {
                foreach ($forbidden as $name) {
                    if (str_contains($line, $name)) {
                        $violations[] = sprintf(
                            '%s:%d — `%s`',
                            $this->relative($entry->getPathname(), $root),
                            $lineIndex + 1,
                            $name,
                        );
                        break;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Legacy command names must not appear outside vendor/, var/, or the guard itself.\n"
            . "Replace them with the `ai:ask <subject>` facade or the underlying `dev:graph:*` commands.\n"
            . "Offenders:\n - " . implode("\n - ", $violations),
        );
    }

    private function isSkipped(SplFileInfo $entry, string $root): bool
    {
        $rel = $this->relative($entry->getPathname(), $root);
        $firstSegment = strtok($rel, DIRECTORY_SEPARATOR);
        return in_array($firstSegment, self::skippedTopLevelDirs(), true);
    }

    private function relative(string $path, string $root): string
    {
        if (str_starts_with($path, $root)) {
            return ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
        }
        return $path;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality;

/**
 * The PHP files under a directory, for metrics that scan the tree. An absent
 * directory yields nothing rather than an error.
 */
final class PhpFiles
{
    /**
     * @return list<string> absolute paths, sorted
     */
    public static function under(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @return array<string, string> package name => absolute package directory
     */
    public static function packages(string $projectRoot): array
    {
        $out = [];
        foreach (glob($projectRoot . '/packages/semitexa-*', GLOB_ONLYDIR) ?: [] as $dir) {
            $out[basename($dir)] = $dir;
        }
        ksort($out);

        return $out;
    }
}

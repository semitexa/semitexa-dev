<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Support;

use Semitexa\Core\Support\ProjectRoot;

/**
 * The namespace generated code takes inside a local module.
 *
 * `App\Modules\<Module>` is canonical: it is what the docs teach and what the
 * starter module uses. The boot registrar maps `Semitexa\Modules\<Module>` to
 * the same directory too, and modules written under that prefix keep it — a
 * class added to one must import its neighbours by the names they declare, or
 * the file is found and the class in it is not. So an existing module answers
 * with the prefix its own code declares; a new one gets the canonical prefix.
 */
final class ModuleNamespace
{
    public const CANONICAL_PREFIX = 'App\\Modules';

    public static function of(string $module, ?string $projectRoot = null): string
    {
        $dir = ($projectRoot ?? ProjectRoot::get()) . '/src/modules/' . $module . '/src';
        $pattern = '/^namespace\s+(App|Semitexa)\\\\Modules\\\\' . preg_quote($module, '/') . '(?:\\\\|;)/m';

        foreach (self::phpFiles($dir) as $file) {
            $head = @file_get_contents($file, false, null, 0, 4096);
            if (is_string($head) && preg_match($pattern, $head, $match) === 1) {
                return $match[1] . '\\Modules\\' . $module;
            }
        }

        return self::CANONICAL_PREFIX . '\\' . $module;
    }

    /** @return list<string> sorted, so the answer does not depend on directory order */
    private static function phpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * Finds --ui-* tokens that are used and declared nowhere.
 *
 * CSS does not fail on one: var() falls to its fallback, or the property is
 * dropped, and nothing says so. A background written as var(--ui-color-bg, #fff)
 * stayed white while the skin's text turned light in dark mode, a contrast of
 * 1.2 to 1, and every test passed (2026-11-07).
 *
 * Declarations are read from the framework as well, in packages/ or vendor/, since
 * that is where a skin lives; only the project's own files are reported.
 */
final class CssTokenScan
{
    private const USED = '/var\(\s*(--ui-[a-z0-9-]+)/';
    private const DECLARED = '/(--ui-[a-z0-9-]+)\s*:/';
    private const SOURCES = ['src', 'packages', 'vendor/semitexa'];

    /** @return array<string, list<string>> file relative to the project => the tokens declared nowhere */
    public static function unknown(string $projectRoot): array
    {
        $declared = [];
        $used = [];
        foreach (self::files($projectRoot) as $file) {
            $content = (string) @file_get_contents($file);
            preg_match_all(self::DECLARED, $content, $declarations);
            $declared += array_flip($declarations[1]);
            preg_match_all(self::USED, $content, $uses);
            $relative = substr($file, strlen($projectRoot) + 1);
            if ($uses[1] !== [] && !str_starts_with($relative, 'vendor/')) {
                $used[$relative] = array_values(array_unique($uses[1]));
            }
        }

        $unknown = [];
        foreach ($used as $file => $tokens) {
            $missing = array_values(array_filter($tokens, static fn (string $token): bool => !isset($declared[$token])));
            if ($missing !== []) {
                $unknown[$file] = $missing;
            }
        }
        ksort($unknown);

        return $unknown;
    }

    /** @return list<string> */
    private static function files(string $projectRoot): array
    {
        $files = [];
        foreach (self::SOURCES as $source) {
            $root = $projectRoot . '/' . $source;
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => !in_array($file->getFilename(), ['node_modules', 'vendor', '.git'], true),
            ));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && preg_match('/\.(css|twig)$/', $file->getFilename()) === 1) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}

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
    // A custom property name is case-sensitive and may hold underscores: `--ui-a`
    // declared must not pass `var(--ui-a_b)` or `var(--ui-A)` as known.
    private const USED = '/var\(\s*(--ui-[A-Za-z0-9_-]+)/';
    private const DECLARED = '/(--ui-[A-Za-z0-9_-]+)\s*:/';
    // CSS comments declare nothing: `/* --ui-foo: removed */` is a note, not a token. A quoted
    // string is matched first and kept, so `content: "/*"` opens no comment; only a closed
    // comment is one (review dev#216).
    private const CSS_STRING_OR_COMMENT = '#("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')|/\*.*?\*/#s';
    private const TWIG_COMMENT = '/\{#.*?#\}/s';
    // In a template, CSS is what sits in a <style> element or a style attribute: tokens are
    // read there and nowhere else, so prose ("--ui-foo: removed", "don't") reads as nothing.
    // A double-quoted attribute may hold Twig with quotes of its own: {{ c ? "a" : "b" }}.
    private const TEMPLATE_CSS = '#<style\b[^>]*>(.*?)</style>|\bstyle\s*=\s*(?:"((?:\{\{.*?\}\}|[^"{]|\{(?!\{))*)"|\'([^\']*)\')#is';
    private const SOURCES = ['src', 'packages', 'vendor/semitexa'];

    /**
     * @return array<string, list<string>> file relative to the project => the tokens declared nowhere
     *
     * @throws \RuntimeException a file it found and could not read: a lint that cannot read must not pass
     */
    public static function unknown(string $projectRoot): array
    {
        $declared = [];
        $used = [];
        foreach (self::files($projectRoot) as $file) {
            $content = @file_get_contents($file);
            if (!is_string($content)) {
                throw new \RuntimeException('cannot read ' . substr($file, strlen($projectRoot) + 1));
            }
            $content = self::withoutCssComments(str_ends_with($file, '.twig') ? self::templateCss((string) preg_replace(self::TWIG_COMMENT, ' ', $content)) : $content);
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

    private static function withoutCssComments(string $content): string
    {
        return (string) preg_replace_callback(self::CSS_STRING_OR_COMMENT, static fn (array $m): string => ($m[1] ?? '') !== '' ? $m[1] : ' ', $content);
    }

    /** The CSS a template holds: its style elements and style attributes, nothing around them. */
    private static function templateCss(string $content): string
    {
        preg_match_all(self::TEMPLATE_CSS, $content, $blocks, PREG_SET_ORDER);

        return implode("\n", array_map(static fn (array $m): string => ($m[1] ?? '') . ($m[2] ?? '') . ($m[3] ?? ''), $blocks));
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

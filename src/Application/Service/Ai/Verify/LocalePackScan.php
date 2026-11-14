<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * Reads every locale pack the project's modules and packages ship, the way
 * semitexa/locale's JsonFileLoader reads it, and says what that loader would
 * drop or the Translator would print as a bare key (tk-verify-reads-locale-packs,
 * found by a consumer whose pack changes ai:verify could not check).
 *
 * Nothing about a pack fails loudly: a file that is not a JSON object is
 * skipped whole, a value that is neither a string nor a plural map is dropped,
 * and a key one language lacks renders as the key itself ("nav.to_marketplace")
 * in the middle of a sentence. So, per locales directory:
 *  - each pack is a JSON object;
 *  - each value is a string or a plural map (zero, one, two, few, many, other => string);
 *  - every pack carries the same keys as the others.
 */
final class LocalePackScan
{
    /** The plural categories JsonFileLoader accepts; anything else drops the value. */
    private const PLURAL_KEYS = ['zero', 'one', 'two', 'few', 'many', 'other'];

    /** Where packs live: a module's View/locales, as JsonFileLoader resolves it, and a workspace package's. */
    private const DIRECTORIES = [
        'src/modules/*/src/Application/View/locales',
        'src/modules/*/Application/View/locales',
        'packages/*/src/Application/View/locales',
    ];

    /** @return array<string, list<string>> pack, relative to the project => what is wrong with it */
    public static function problems(string $projectRoot): array
    {
        $root = rtrim($projectRoot, '/');
        $problems = [];
        foreach (self::DIRECTORIES as $pattern) {
            // A project with no packages/ (or no src/modules) has none of those packs; GLOB_ERR reads that as an error.
            if (!is_dir($root . '/' . strstr($pattern, '/*', true))) {
                continue;
            }
            // GLOB_ERR: a directory that cannot be read fails the lint, never thins it (review, dev#261).
            $dirs = glob($root . '/' . $pattern, GLOB_ONLYDIR | GLOB_ERR);
            if ($dirs === false) {
                $problems[$pattern][] = 'could not be listed: the packs under it were not read';
                continue;
            }
            foreach ($dirs as $dir) {
                $problems += self::directory($root, $dir);
            }
        }
        ksort($problems);

        return $problems;
    }

    /** @return array<string, list<string>> */
    private static function directory(string $root, string $dir): array
    {
        $problems = [];
        $keys = [];
        $files = glob($dir . '/*.json', GLOB_ERR);
        if ($files === false) {
            return [substr($dir, strlen($root) + 1) => ['could not be listed: its packs were not read']];
        }
        foreach ($files as $file) {
            $relative = substr($file, strlen($root) + 1);
            $content = @file_get_contents($file);
            if ($content === false) {
                $problems[$relative][] = 'cannot be read: the loader skips it';
                continue;
            }
            // The root as JSON wrote it: decoded to arrays, `[]` and `{}` look alike and `{"0": "a"}`
            // looks like a list (review, dev#261). The loader skips anything not an array.
            $decoded = json_decode($content);
            if (!$decoded instanceof \stdClass) {
                $problems[$relative][] = json_last_error() !== JSON_ERROR_NONE
                    ? 'is not valid JSON (' . json_last_error_msg() . '): the loader skips the whole file'
                    : 'is not a JSON object of messages: a pack maps each key to its text';
                continue;
            }
            /** @var array<array-key, mixed> $messages */
            $messages = json_decode($content, true);
            foreach ($messages as $key => $value) {
                if (!is_string($value) && !(is_array($value) && self::isPluralMap($value))) {
                    $problems[$relative][] = sprintf('"%s" is neither a string nor a plural map (%s): the loader drops it', $key, implode(', ', self::PLURAL_KEYS));
                }
            }
            $keys[$relative] = array_map('strval', array_keys($messages));
        }

        $all = $keys === [] ? [] : array_values(array_unique(array_merge(...array_values($keys))));
        foreach ($keys as $relative => $own) {
            $missing = array_values(array_diff($all, $own));
            if ($missing !== []) {
                sort($missing);
                $problems[$relative][] = sprintf(
                    'lacks %d key(s) another language in %s has, first "%s": the Translator prints the key itself',
                    count($missing),
                    substr($dir, strlen($root) + 1),
                    $missing[0],
                );
            }
        }

        return $problems;
    }

    /** @param array<array-key, mixed> $value */
    private static function isPluralMap(array $value): bool
    {
        foreach ($value as $category => $text) {
            if (!is_string($text) || !in_array($category, self::PLURAL_KEYS, true)) {
                return false;
            }
        }

        return $value !== [];
    }
}

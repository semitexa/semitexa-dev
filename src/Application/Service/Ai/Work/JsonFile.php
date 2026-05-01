<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

/**
 * Atomic JSON read/write helper.
 *
 * Writes go through a sibling temp file + `rename()` so a crash or a
 * concurrent reader never sees a half-written file. `rename()` is atomic
 * within the same filesystem, which is the case here (both files live in
 * the same var/ai-work subdir).
 */
final class JsonFile
{
    /**
     * @param array<string, mixed> $data
     */
    public static function writeAtomic(string $path, array $data): void
    {
        self::ensureDir(dirname($path));

        $json = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        );
        if ($json === false) {
            throw new \RuntimeException("failed to encode JSON for {$path}: " . json_last_error_msg());
        }

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $written = file_put_contents($tmp, $json . "\n", LOCK_EX);
        if ($written === false) {
            @unlink($tmp);
            throw new \RuntimeException("failed to write temp file: {$tmp}");
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("failed to rename {$tmp} -> {$path}");
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function read(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("cannot read file: {$path}");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("malformed JSON in {$path}");
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public static function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("failed to create dir: {$dir}");
        }
    }
}

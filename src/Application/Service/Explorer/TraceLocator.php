<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Explorer;

use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Finds the trace file a marked request produced.
 *
 * The Explorer sends `X-Semitexa-Trace: explorer-<random>`; the tracer writes
 * the marker into the file it flushes after the response. Only the newest
 * files are read — the trace lands within a moment of the response, and the
 * browser simply asks again if it has not landed yet, rather than a worker
 * sleeping in a loop.
 */
final class TraceLocator
{
    private const SCAN_NEWEST = 40;

    public static function isToken(string $token): bool
    {
        return preg_match('/^explorer-[a-f0-9]{8,32}$/', $token) === 1;
    }

    public static function find(string $token): ?string
    {
        if (!self::isToken($token)) {
            return null;
        }

        $files = glob(self::dir() . '/*.json') ?: [];
        rsort($files);
        $needle = json_encode($token);
        foreach (array_slice($files, 0, self::SCAN_NEWEST) as $file) {
            $body = (string) @file_get_contents($file);
            if (str_contains($body, '"marker":' . $needle) || str_contains($body, '"marker": ' . $needle)) {
                return basename($file);
            }
        }

        return null;
    }

    private static function dir(): string
    {
        $configured = Environment::getEnvValue('SEMITEXA_TRACE_DIR');

        return is_string($configured) && $configured !== '' ? $configured : ProjectRoot::get() . '/var/trace';
    }
}

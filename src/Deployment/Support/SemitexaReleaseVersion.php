<?php

declare(strict_types=1);

namespace Semitexa\Dev\Deployment\Support;

final class SemitexaReleaseVersion
{
    public static function isValid(string $version): bool
    {
        return preg_match('/^\d{4}\.\d{2}\.\d{2}\.\d{4}(?:-(?:alpha|beta|rc\d+|p\d+))?$/i', trim($version)) === 1;
    }

    public static function isStable(string $version): bool
    {
        return self::isValid($version) && !str_contains($version, '-');
    }

    public static function compare(string $left, string $right): int
    {
        $leftParts = self::parse($left);
        $rightParts = self::parse($right);

        if ($leftParts === null || $rightParts === null) {
            return strcmp($left, $right);
        }

        $baseComparison = strcmp($leftParts['base'], $rightParts['base']);
        if ($baseComparison !== 0) {
            return $baseComparison;
        }

        return self::suffixRank($leftParts['suffix']) <=> self::suffixRank($rightParts['suffix']);
    }

    /**
     * @param list<string> $versions
     */
    public static function latestStable(array $versions): ?string
    {
        $stableVersions = array_values(array_filter($versions, self::isStable(...)));
        if ($stableVersions === []) {
            return null;
        }

        usort($stableVersions, self::compare(...));
        return array_pop($stableVersions) ?: null;
    }

    /**
     * @return array{base: string, suffix: string}|null
     */
    private static function parse(string $version): ?array
    {
        $version = trim($version);
        if (preg_match('/^(\d{4}\.\d{2}\.\d{2}\.\d{4})(?:-(alpha|beta|rc\d+|p\d+))?$/i', $version, $m) !== 1) {
            return null;
        }

        return [
            'base' => $m[1],
            'suffix' => strtolower($m[2] ?? ''),
        ];
    }

    private static function suffixRank(string $suffix): int
    {
        if ($suffix === '') {
            return 4000;
        }

        if (preg_match('/^p(\d+)$/', $suffix, $m) === 1) {
            return 5000 + (int) $m[1];
        }

        if (preg_match('/^rc(\d+)$/', $suffix, $m) === 1) {
            return 3000 + (int) $m[1];
        }

        return match ($suffix) {
            'beta' => 2000,
            'alpha' => 1000,
            default => 0,
        };
    }
}

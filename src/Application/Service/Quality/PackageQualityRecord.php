<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality;

use Semitexa\Dev\Application\Service\Ai\Work\JsonFile;

/**
 * A package's own share of the quality ledger, kept in the package's repository
 * (tk-quality-record-lives-with-the-package).
 *
 * The ledger kept every package's number in one file in semitexa-dev, so a
 * change in package X that had to raise one needed a semitexa-dev commit too.
 * With semitexa-dev's PR in review it had nowhere to go: on 2026-11-11 the
 * record for semitexa-os#32 sat uncommitted and the release preflight failed
 * on `workspace-is-pushed`. Kept in `packages/<key>/resources/quality.json`, the record
 * ships in the same PR as the change that caused it.
 *
 * A record REPLACES the central breakdown value for its key, never adds to it,
 * and the total is summed again after: a package with a file is counted once.
 * A key with no file falls back to the central value, so the move is lazy, one
 * package at a time, on that package's next accept.
 */
final class PackageQualityRecord
{
    /**
     * Under resources/: a package root holds only the files its envelope
     * declares, and module_structure refuses anything else there, so
     * `quality.json` at the root failed the first package that used it
     * (semitexa-crud#12).
     */
    public const FILE = 'resources/quality.json';

    /**
     * Whether a breakdown key is a package of this workspace, the repository a
     * record for it belongs in. A route or a module key is not.
     */
    public static function owns(string $projectRoot, string $key): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*$/', $key) === 1 && is_dir($projectRoot . '/packages/' . $key);
    }

    public static function path(string $projectRoot, string $key): string
    {
        return $projectRoot . '/packages/' . $key . '/' . self::FILE;
    }

    /**
     * The central metrics with every package record laid over them.
     *
     * @param array<string, mixed> $metrics id => {total, breakdown, ...} as the central file holds them
     * @return array<string, mixed>
     */
    public static function overlay(string $projectRoot, array $metrics): array
    {
        $touched = [];
        foreach (self::all($projectRoot) as $key => $record) {
            foreach ($record as $id => $count) {
                // A metric the central file does not know is NEW, whatever a
                // package says; the record cannot invent a baseline for it.
                if (!is_array($metrics[$id]['breakdown'] ?? null)) {
                    continue;
                }
                $metrics[$id]['breakdown'][$key] = $count;
                $touched[$id] = true;
            }
        }
        foreach (array_keys($touched) as $id) {
            $metrics[$id]['breakdown'] = array_filter($metrics[$id]['breakdown'], static fn (mixed $n): bool => $n !== 0);
            ksort($metrics[$id]['breakdown']);
            $metrics[$id]['total'] = array_sum($metrics[$id]['breakdown']);
        }

        return $metrics;
    }

    /**
     * Set one metric's count in a package's record, with the reason when it rose.
     *
     * @param array<string, mixed>|null $accepted the acceptance to append, or null for a lowered count
     */
    public static function write(string $projectRoot, string $key, string $id, int $count, ?array $accepted): void
    {
        // Never a directory for a key that names no package (review, dev#237).
        if (!self::owns($projectRoot, $key)) {
            throw new \InvalidArgumentException("'{$key}' is not a package of this workspace");
        }
        $path = self::path($projectRoot, $key);
        $data = is_file($path) ? self::decode($path) : [];
        $data['metrics'][$id]['count'] = $count;
        if ($accepted !== null) {
            $data['metrics'][$id]['accepted'][] = $accepted;
        }
        ksort($data['metrics']);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }
        $data = ['_' => 'This package\'s share of the Semitexa quality ledger: lower is better. Written only by `bin/semitexa ai:quality`; do not edit by hand.'] + $data;
        JsonFile::writeAtomic($path, $data);
        @chmod($path, 0o666);
    }

    /**
     * @return array<string, array<string, int>> key => [metric id => count]
     */
    public static function all(string $projectRoot): array
    {
        self::refuseWhatWouldBeMissed($projectRoot);
        $out = [];
        foreach (glob($projectRoot . '/packages/*/' . self::FILE) ?: [] as $path) {
            $key = basename(dirname($path, 2));
            if (!self::owns($projectRoot, $key)) {
                continue;
            }
            foreach (self::decode($path)['metrics'] as $id => $metric) {
                $count = is_array($metric) ? ($metric['count'] ?? null) : null;
                if (!is_int($count) || $count < 0) {
                    throw new \RuntimeException("{$path}: metric '{$id}' has no whole count");
                }
                $out[$key][(string) $id] = $count;
            }
        }

        return $out;
    }

    /**
     * Every acceptance the package records hold, in the shape of the central
     * `deliberate` list, so a report shows a package's reasons beside the rest.
     *
     * @return list<array{at: string, metric: string, from: int, to: int, reason: string}>
     */
    public static function accepted(string $projectRoot): array
    {
        $out = [];
        foreach (glob($projectRoot . '/packages/*/' . self::FILE) ?: [] as $path) {
            $key = basename(dirname($path, 2));
            if (!self::owns($projectRoot, $key)) {
                continue;
            }
            foreach (self::decode($path)['metrics'] as $id => $metric) {
                foreach (is_array($metric) && is_array($metric['accepted'] ?? null) ? $metric['accepted'] : [] as $a) {
                    if (is_array($a)) {
                        $out[] = [
                            'at' => (string) ($a['at'] ?? ''),
                            'metric' => "{$id} ({$key})",
                            'from' => (int) ($a['from'] ?? 0),
                            'to' => (int) ($a['to'] ?? 0),
                            'reason' => (string) ($a['reason'] ?? ''),
                        ];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * A record the glob below cannot see must fail, not fall back to the
     * central value, which may be higher (review, dev#237): one left at the
     * package root by the first version, or one under a resources/ the
     * process cannot enter.
     */
    private static function refuseWhatWouldBeMissed(string $projectRoot): void
    {
        foreach (glob($projectRoot . '/packages/*', GLOB_ONLYDIR) ?: [] as $package) {
            if (is_file($package . '/quality.json')) {
                throw new \RuntimeException("{$package}/quality.json is a quality record at the package root, where module_structure refuses it: move it to {$package}/" . self::FILE);
            }
            $resources = $package . '/resources';
            if (is_dir($resources) && (!is_readable($resources) || !is_executable($resources))) {
                throw new \RuntimeException("{$resources} cannot be read, so its quality record (if any) cannot be counted");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $path): array
    {
        // Unreadable is a failure: read as empty, the package would fall back
        // to an older central value, and could be higher than what it accepted.
        $data = json_decode((string) @file_get_contents($path), true);
        // `metrics` is required: a record without it, or with null, would
        // fall back to the central value silently (review, dev#235).
        if (!is_array($data) || !is_array($data['metrics'] ?? null)) {
            throw new \RuntimeException("{$path} is not a readable package quality record");
        }

        return $data;
    }
}

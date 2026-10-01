<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;

/**
 * Recent recorded traces that ran a given class — the graph node's way back
 * to the requests that exercised it.
 *
 * There is no index to ask: the journal is the only cross-worker record, and
 * since the graph view each persisted trace's end line carries the FQCNs its
 * spans named (`classes`, {@see PhaseSummary::classes()}). This streams the
 * retained journal newest file first, keeps the newest matches of each file,
 * and stops at the cap. A substring test comes before json_decode, so a week
 * of journal costs a read, not a parse, and never more than a line in memory.
 *
 * An empty answer means "no persisted trace names it", not "it never ran":
 * stage mode and unmarked requests write no trace file, and end lines written
 * before `classes` existed name nothing.
 */
#[AsService]
final class TraceClassIndex
{
    public const LIMIT = 20;

    /**
     * @return list<array{trace: string, ts: string, name: string, kind: string, durationMs: float|null}>
     */
    public function forClass(string $fqcn, int $limit = self::LIMIT): array
    {
        if ($fqcn === '') {
            return [];
        }

        // The class as it appears inside the JSON line: backslashes escaped.
        $needle = substr((string) json_encode($fqcn, JSON_UNESCAPED_SLASHES), 1, -1);
        $files = glob(ObservatoryJournal::dir() . '/journal-*.ndjson') ?: [];
        rsort($files);

        $found = [];
        foreach ($files as $file) {
            $want = $limit - count($found);
            if ($want <= 0) {
                break;
            }
            // Streamed, keeping only the newest $want matches: a busy day's
            // journal runs to tens of megabytes, and a click must not hold it.
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            $matches = [];
            while (($line = fgets($handle)) !== false) {
                if (!str_contains($line, $needle)) {
                    continue;
                }
                $row = self::match($line, $fqcn);
                if ($row !== null) {
                    $matches[] = $row;
                    if (count($matches) > $want) {
                        array_shift($matches);
                    }
                }
            }
            fclose($handle);
            foreach (array_reverse($matches) as $row) {
                $found[] = $row;
            }
        }

        return $found;
    }

    /**
     * One journal line, when it is the end of a persisted trace that names $fqcn.
     *
     * @return array{trace: string, ts: string, name: string, kind: string, durationMs: float|null}|null
     */
    private static function match(string $line, string $fqcn): ?array
    {
        $row = json_decode($line, true);
        if (!is_array($row) || ($row['event'] ?? null) !== 'end' || !is_string($row['trace'] ?? null)
            || !is_array($row['classes'] ?? null) || !in_array($fqcn, $row['classes'], true)) {
            return null;
        }
        $duration = $row['durationMs'] ?? null;

        return [
            'trace' => $row['trace'],
            'ts' => is_string($row['ts'] ?? null) ? $row['ts'] : '',
            'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
            'kind' => is_string($row['kind'] ?? null) ? $row['kind'] : '',
            'durationMs' => is_int($duration) || is_float($duration) ? (float) $duration : null,
        ];
    }
}

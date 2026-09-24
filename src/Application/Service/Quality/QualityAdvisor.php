<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality;

/**
 * Where to improve next, read from the ledger rather than guessed.
 *
 * The ledger stops things getting worse; this is the other half of the loop —
 * pointing at the next thing to make better, so the numbers keep going down
 * instead of sitting at whatever they happened to be when recorded.
 *
 * Order: the metric that has gone LONGEST without improving comes first (a
 * number nobody has moved is the one nobody is looking at), and within a metric
 * the biggest key first (the largest single win). Raw counts are never compared
 * across metrics — 144 skipped tests and 2 duplicate queries are different units.
 *
 * Reads the recorded baseline and history only; it measures nothing, so it is
 * cheap enough for `ai:orient`.
 */
final class QualityAdvisor
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * @return list<array{metric: string, key: string, count: int, total: int, sees: string, since: ?string, why: string}>
     */
    public function targets(int $limit = 5): array
    {
        $baseline = $this->json(QualityLedger::BASELINE)['metrics'] ?? [];
        if (!is_array($baseline) || $baseline === []) {
            return [];
        }
        $lastMoved = $this->lastImprovement();

        // Stalest metric first; a metric with no improvement on record counts
        // from when it was first measured.
        $order = array_keys($baseline);
        usort($order, static fn (string $a, string $b): int => strcmp($lastMoved[$a] ?? '', $lastMoved[$b] ?? '') ?: strcmp($a, $b));

        $queues = [];
        foreach ($order as $metric) {
            $breakdown = (array) ($baseline[$metric]['breakdown'] ?? []);
            arsort($breakdown);
            $queues[$metric] = $breakdown;
        }

        // Round-robin across metrics so one large metric cannot fill the list.
        $out = [];
        while (count($out) < $limit && array_filter($queues) !== []) {
            foreach ($queues as $metric => &$queue) {
                if ($queue === [] || count($out) >= $limit) {
                    continue;
                }
                $key = (string) array_key_first($queue);
                $count = (int) array_shift($queue);
                $since = $lastMoved[$metric] ?? null;
                $out[] = [
                    'metric' => $metric,
                    'key' => $key,
                    'count' => $count,
                    'total' => (int) ($baseline[$metric]['total'] ?? 0),
                    'sees' => (string) ($baseline[$metric]['sees'] ?? ''),
                    'since' => $since,
                    'why' => sprintf(
                        '%s is %d of %s\'s %d; the metric last improved %s',
                        $key,
                        $count,
                        $metric,
                        (int) ($baseline[$metric]['total'] ?? 0),
                        $since === null ? 'never' : substr($since, 0, 10),
                    ),
                ];
            }
            unset($queue);
        }

        return $out;
    }

    /**
     * When each metric last went down: its last `record` event, or its first
     * reading when it never has.
     *
     * @return array<string, string> metric => ISO timestamp
     */
    private function lastImprovement(): array
    {
        $path = $this->projectRoot . '/' . QualityLedger::HISTORY;
        $out = [];
        foreach (is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
            $e = json_decode($line, true);
            if (!is_array($e) || !is_string($e['metric'] ?? null) || !is_string($e['at'] ?? null)) {
                continue;
            }
            if (($e['event'] ?? '') === 'record' || !isset($out[$e['metric']])) {
                $out[$e['metric']] = $e['at'];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $relative): array
    {
        $path = $this->projectRoot . '/' . $relative;
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) ? $data : [];
    }
}

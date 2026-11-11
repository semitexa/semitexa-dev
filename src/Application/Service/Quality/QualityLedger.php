<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality;

use Semitexa\Dev\Application\Service\Ai\Work\JsonFile;
use Semitexa\Dev\Attribute\AsQualityMetric;

/**
 * The versioned record of every quality metric, and the only thing allowed to
 * change it.
 *
 * Every earlier ratchet kept its number in a place someone edited by hand — a
 * PHP const in a test, a shell default in a release script — and raised it in
 * the same commit that grew past it, with a comment. Here the rules are in
 * code:
 *   - record() writes a reading only when it is no worse than the baseline, so
 *     an improvement is locked in and nothing is ever raised by accident;
 *   - accept() is the one way up, and it refuses without a reason, which lands
 *     in the file beside the new number, in the diff a reviewer reads;
 *   - every change appends a line to the history, so the trend is data rather
 *     than prose in comments.
 */
final class QualityLedger
{
    public const BASELINE = 'packages/semitexa-dev/resources/quality/baseline.json';
    public const HISTORY = 'packages/semitexa-dev/resources/quality/history.ndjson';

    /** A reason shorter than this is a placeholder, not a reason. */
    private const MIN_REASON = 20;

    /**
     * @param array<string, array{metric: QualityMetricInterface, meta: AsQualityMetric}> $metrics id => metric
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $metrics,
    ) {
    }

    public function exists(): bool
    {
        return is_file($this->projectRoot . '/' . self::BASELINE);
    }

    /**
     * @return list<Verdict>
     */
    public function check(string $tier = AsQualityMetric::TIER_VERIFY): array
    {
        $baseline = $this->baseline();
        $verdicts = [];
        foreach ($this->selected($tier) as $id => $entry) {
            $verdicts[] = Verdict::of($id, $entry['metric']->measure($this->projectRoot), $baseline[$id] ?? null);
        }

        return $verdicts;
    }

    /**
     * Lock in every metric that is new or better. A worse one is left alone and
     * returned, so the caller can say which needs a fix or an accept().
     *
     * @return array{recorded: list<Verdict>, refused: list<Verdict>}
     */
    public function record(string $tier = AsQualityMetric::TIER_VERIFY): array
    {
        return $this->locked(fn (): array => $this->recordLocked($tier));
    }

    /**
     * @return array{recorded: list<Verdict>, refused: list<Verdict>}
     */
    private function recordLocked(string $tier): array
    {
        $data = $this->read();
        $baseline = PackageQualityRecord::overlay($this->projectRoot, $data['metrics']);
        $records = PackageQualityRecord::all($this->projectRoot);
        $events = [];
        $recorded = [];
        $refused = [];
        foreach ($this->selected($tier) as $id => $entry) {
            $now = $entry['metric']->measure($this->projectRoot);
            $verdict = Verdict::of($id, $now, $baseline[$id] ?? null);
            if ($verdict->status === Verdict::WORSE) {
                $refused[] = $verdict;
                continue;
            }
            if ($verdict->status === Verdict::SAME) {
                continue;
            }
            $data['metrics'][$id] = $this->entry($now, $entry['meta']);
            // A package that improved lowers its own record too, or the record
            // would keep the old ceiling and let the count climb back unseen.
            // A metric new to the central file takes its reading from now, the
            // record included, or the next check would refuse it (review, dev#235).
            foreach ($records as $key => $record) {
                $is = $now->breakdown[$key] ?? 0;
                if (isset($record[$id]) && ($is < $record[$id] || ($verdict->status === Verdict::NEW && $is !== $record[$id]))) {
                    PackageQualityRecord::write($this->projectRoot, $key, $id, $is, null);
                }
            }
            $events[] = [$id, $now, $verdict->status === Verdict::NEW ? 'new' : 'record'];
            $recorded[] = $verdict;
        }
        if ($recorded !== []) {
            // History only after the baseline is safely on disk: a line for a
            // value that was never recorded would be a trend that did not happen.
            $this->write($data);
            foreach ($events as [$id, $now, $event]) {
                $this->appendHistory($id, $now, $event);
            }
        }

        return ['recorded' => $recorded, 'refused' => $refused];
    }

    /**
     * Raise one metric to its current reading, deliberately.
     *
     * @throws \InvalidArgumentException for an unknown metric or a missing reason
     */
    public function accept(string $id, string $reason): Verdict
    {
        if (!isset($this->metrics[$id])) {
            throw new \InvalidArgumentException("unknown quality metric '{$id}'");
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON) {
            throw new \InvalidArgumentException(sprintf(
                'accept needs --reason of at least %d characters saying what grew and why it has to',
                self::MIN_REASON,
            ));
        }

        return $this->locked(function () use ($id, $reason): Verdict {
            $data = $this->read();
            $now = $this->metrics[$id]['metric']->measure($this->projectRoot);
            $verdict = Verdict::of($id, $now, PackageQualityRecord::overlay($this->projectRoot, $data['metrics'])[$id] ?? null);

            // A package's key goes to that package's own record, in the PR of
            // the change that moved it; the central file only for the rest
            // (tk-quality-record-lives-with-the-package).
            $central = $verdict->status === Verdict::NEW ? $verdict->moved : [];
            foreach ($verdict->status === Verdict::NEW ? [] : $verdict->moved as $key => $move) {
                if (!PackageQualityRecord::owns($this->projectRoot, (string) $key)) {
                    $central[$key] = $move;
                    continue;
                }
                $rose = $move['to'] > $move['from'];
                PackageQualityRecord::write($this->projectRoot, (string) $key, $id, $move['to'], $rose ? [
                    'at' => gmdate('Y-m-d'), 'from' => $move['from'], 'to' => $move['to'], 'reason' => $reason,
                ] : null);
            }
            if ($verdict->status !== Verdict::NEW && $central === []) {
                return $verdict;
            }

            if ($verdict->status === Verdict::NEW) {
                // A record already holding this metric takes the reading too,
                // with the reason when it rose (review, dev#235).
                foreach (PackageQualityRecord::all($this->projectRoot) as $key => $record) {
                    $is = $now->breakdown[$key] ?? 0;
                    if (isset($record[$id]) && $is !== $record[$id]) {
                        PackageQualityRecord::write($this->projectRoot, $key, $id, $is, $is > $record[$id] ? [
                            'at' => gmdate('Y-m-d'), 'from' => $record[$id], 'to' => $is, 'reason' => $reason,
                        ] : null);
                    }
                }
            }
            $entry = $this->entry($now, $this->metrics[$id]['meta']);
            if ($verdict->status !== Verdict::NEW) {
                // Only the keys no package owns move here; a package key keeps
                // its central value as the fallback for a package without a record.
                $breakdown = (array) ($data['metrics'][$id]['breakdown'] ?? []);
                foreach ($central as $key => $move) {
                    $breakdown[$key] = $move['to'];
                }
                $entry['breakdown'] = array_filter($breakdown, static fn (mixed $n): bool => $n !== 0);
                ksort($entry['breakdown']);
                $entry['total'] = array_sum($entry['breakdown']);
            }
            $data['metrics'][$id] = $entry;
            $data['deliberate'][] = [
                'at' => gmdate('Y-m-d'),
                'metric' => $id,
                'from' => $verdict->from,
                'to' => $verdict->to,
                'keys' => $central,
                'reason' => $reason,
            ];
            $this->write($data);
            $this->appendHistory($id, $now, 'accept');

            return $verdict;
        });
    }

    /**
     * Read, measure and write as one step. Without it two agents recording at
     * once lost an update — one could write back a HIGHER reading it measured
     * before the other's lower one landed, raising the ledger without accept().
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function locked(callable $fn): mixed
    {
        $lockPath = $this->projectRoot . '/' . self::BASELINE . '.lock';
        $this->ensureDirectory($lockPath);
        $handle = @fopen($lockPath, 'c');
        // Same as the baseline and history: the container's root and the host
        // user share this tree, and a 0644 lock would lock the other one out.
        @chmod($lockPath, 0o666);
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException('cannot lock the quality ledger: ' . $lockPath);
        }
        try {
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, array{metric: QualityMetricInterface, meta: AsQualityMetric}>
     */
    private function selected(string $tier): array
    {
        return array_filter(
            $this->metrics,
            static fn (array $e): bool => $tier === AsQualityMetric::TIER_RELEASE || $e['meta']->tier === $tier,
        );
    }

    /**
     * @return array{total: int, breakdown: array<string, int>, sees: string, blind: string}
     */
    private function entry(Measurement $now, AsQualityMetric $meta): array
    {
        return ['total' => $now->total(), 'breakdown' => $now->breakdown, 'sees' => $meta->sees, 'blind' => $meta->blind];
    }

    /**
     * The baseline every comparison is made against: the central file with
     * each package's own record laid over it.
     *
     * @return array<string, mixed>
     */
    public function baseline(): array
    {
        return PackageQualityRecord::overlay($this->projectRoot, $this->read()['metrics']);
    }

    /**
     * @return array{metrics: array<string, array{total: int, breakdown: array<string, int>}>, deliberate: list<array<string, mixed>>}
     */
    private function read(): array
    {
        $path = $this->projectRoot . '/' . self::BASELINE;
        if (!is_file($path)) {
            return ['metrics' => [], 'deliberate' => []];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !is_array($data['metrics'] ?? null)) {
            // An unreadable ledger must not read as an empty one: every metric
            // would come back NEW and record() would rewrite history.
            throw new \RuntimeException(self::BASELINE . ' is not a readable quality ledger');
        }
        $data['deliberate'] = is_array($data['deliberate'] ?? null) ? array_values($data['deliberate']) : [];

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        ksort($data['metrics']);
        $data = ['_' => 'Quality metrics: lower is better. Written only by `ai:quality record` (never raises) and `ai:quality accept --reason` (raises, and says why below). Do not edit by hand.'] + $data;
        // Atomic (temp file + rename), so a concurrent check never reads half a
        // ledger, and a failed write throws instead of reporting "recorded".
        JsonFile::writeAtomic($this->projectRoot . '/' . self::BASELINE, $data);
        @chmod($this->projectRoot . '/' . self::BASELINE, 0o666);
    }

    private function appendHistory(string $id, Measurement $now, string $event): void
    {
        $line = json_encode(['at' => gmdate('c'), 'metric' => $id, 'total' => $now->total(), 'event' => $event], JSON_UNESCAPED_SLASHES);
        // The first record() of a fresh ledger appends history before the
        // baseline is written, so the directory may not exist yet.
        $path = $this->projectRoot . '/' . self::HISTORY;
        $this->ensureDirectory($path);
        if (file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException("cannot append to {$path} (the baseline was written; its history line was not)");
        }
        @chmod($path, 0o666);
    }

    private function ensureDirectory(string $file): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o775, true);
        }
    }
}

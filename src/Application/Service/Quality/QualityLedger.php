<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality;

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
        $baseline = $this->read()['metrics'];
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
        $data = $this->read();
        $recorded = [];
        $refused = [];
        foreach ($this->selected($tier) as $id => $entry) {
            $now = $entry['metric']->measure($this->projectRoot);
            $verdict = Verdict::of($id, $now, $data['metrics'][$id] ?? null);
            if ($verdict->status === Verdict::WORSE) {
                $refused[] = $verdict;
                continue;
            }
            if ($verdict->status === Verdict::SAME) {
                continue;
            }
            $data['metrics'][$id] = $this->entry($now, $entry['meta']);
            $this->appendHistory($id, $now, $verdict->status === Verdict::NEW ? 'new' : 'record');
            $recorded[] = $verdict;
        }
        if ($recorded !== []) {
            $this->write($data);
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

        $data = $this->read();
        $now = $this->metrics[$id]['metric']->measure($this->projectRoot);
        $verdict = Verdict::of($id, $now, $data['metrics'][$id] ?? null);

        $data['metrics'][$id] = $this->entry($now, $this->metrics[$id]['meta']);
        $data['deliberate'][] = [
            'at' => gmdate('Y-m-d'),
            'metric' => $id,
            'from' => $verdict->from,
            'to' => $verdict->to,
            'keys' => $verdict->moved,
            'reason' => $reason,
        ];
        $this->write($data);
        $this->appendHistory($id, $now, 'accept');

        return $verdict;
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
        $path = $this->projectRoot . '/' . self::BASELINE;
        $this->ensureDirectory($path);
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    private function appendHistory(string $id, Measurement $now, string $event): void
    {
        $line = json_encode(['at' => gmdate('c'), 'metric' => $id, 'total' => $now->total(), 'event' => $event], JSON_UNESCAPED_SLASHES);
        // The first record() of a fresh ledger appends history before the
        // baseline is written, so the directory may not exist yet.
        $path = $this->projectRoot . '/' . self::HISTORY;
        $this->ensureDirectory($path);
        file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private function ensureDirectory(string $file): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o775, true);
        }
    }
}

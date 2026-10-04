<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Receipt;

/**
 * A receipt for an ai:verify run, so "all tests pass" can be checked instead
 * of believed.
 *
 * It records what ran (the ai:verify argv, each target's verdict and exit code,
 * every process with its argv, cwd, exit code and a hash of its output) and the
 * state it ran against: a sha256 of every changed file. check() answers three
 * questions: is the receipt as it was written (digest), does the tree still
 * look like that (fingerprint), and was the verdict a pass.
 *
 * The digest catches an edited receipt, not a forged one: whoever can write
 * var/run can rewrite both. What it buys is that a claim names something that
 * can be looked at and re-run — the argv is there to run again.
 */
final class VerifyReceipts
{
    public const DIR = 'var/run/verify-receipts';

    private const KEEP = 200;

    private const READS = 'reads.ndjson';

    public function __construct(private readonly string $projectRoot) {}

    /**
     * Write the receipt for this envelope and name it in the envelope.
     *
     * @param array<string, mixed> $envelope
     * @param list<array<string, mixed>> $processes
     * @return array<string, mixed> the envelope with `receipt`
     */
    public function attach(array $envelope, array $processes): array
    {
        $receipt = [
            'artifact'     => 'semitexa-dev.verify-receipt/v1',
            'id'           => 'rcpt-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)),
            'generated_at' => $envelope['generated_at'] ?? gmdate(DATE_ATOM),
            'argv'         => array_values(array_map(static fn (mixed $arg): string => is_scalar($arg) ? (string) $arg : '', (array) ($_SERVER['argv'] ?? []))),
            'cwd'          => (string) getcwd(),
            // Who ran it: a subagent's run carries its own session, so a parent
            // can tell its own runs from the ones it was only told about.
            'run_by'       => ['agent_session' => self::env('SEMITEXA_AGENT_SESSION'), 'trace' => self::env('SEMITEXA_AI_TRACE_ID')],
            'verdict'      => $envelope['verdict'] ?? null,
            'targets'      => array_map(static fn (mixed $r): array => is_array($r) ? [
                'id'            => $r['id'] ?? null,
                'type'          => $r['type'] ?? null,
                'status'        => $r['status'] ?? null,
                'exit_code'     => $r['exit_code'] ?? null,
                'signal_sha256' => hash('sha256', is_string($r['signal'] ?? null) ? $r['signal'] : ''),
            ] : [], array_values((array) ($envelope['results'] ?? []))),
            'processes'    => $processes,
            'tree'         => $this->fingerprint(array_map(
                static fn (mixed $f): string => is_array($f) && is_string($f['path'] ?? null) ? $f['path'] : '',
                array_values((array) ($envelope['changed_files'] ?? [])),
            )),
        ];
        $receipt['digest'] = self::digest($receipt);

        try {
            $dir = $this->projectRoot . '/' . self::DIR;
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (@file_put_contents($dir . '/' . $receipt['id'] . '.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
                return $envelope;
            }
            $this->prune($dir);
        } catch (\Throwable) {
            // A receipt that could not be written is absent, never a failed run.
            return $envelope;
        }

        return $envelope + ['receipt' => ['id' => $receipt['id'], 'path' => self::DIR . '/' . $receipt['id'] . '.json', 'digest' => $receipt['digest']]];
    }

    /**
     * @return array{found: bool, id: ?string, intact: bool, verdict: ?string, generated_at: ?string, changed_since: list<string>, receipt: ?array<string, mixed>}
     */
    public function check(?string $id): array
    {
        $file = $id === null ? $this->latest() : $this->projectRoot . '/' . self::DIR . '/' . basename($id) . '.json';
        $receipt = $file !== null && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($receipt)) {
            return ['found' => false, 'id' => $id, 'intact' => false, 'verdict' => null, 'generated_at' => null, 'changed_since' => [], 'receipt' => null];
        }
        /** @var array<string, mixed> $receipt */
        $recorded = is_string($receipt['digest'] ?? null) ? $receipt['digest'] : '';
        $unsigned = $receipt;
        unset($unsigned['digest']);
        $tree = is_array($receipt['tree'] ?? null) ? $receipt['tree'] : [];
        $now = $this->fingerprint(array_map('strval', array_keys($tree)));
        $changed = [];
        foreach ($tree as $path => $hash) {
            if (($now[$path] ?? null) !== $hash) {
                $changed[] = (string) $path;
            }
        }

        return [
            'found'         => true,
            'id'            => is_string($receipt['id'] ?? null) ? $receipt['id'] : null,
            'intact'        => hash_equals($recorded, self::digest($unsigned)),
            'verdict'       => is_string($receipt['verdict'] ?? null) ? $receipt['verdict'] : null,
            'generated_at'  => is_string($receipt['generated_at'] ?? null) ? $receipt['generated_at'] : null,
            'changed_since' => $changed,
            'receipt'       => $receipt,
        ];
    }

    /** Remember that someone looked: a receipt nobody checked is a claim nobody verified. */
    public function markRead(string $id): void
    {
        $file = $this->projectRoot . '/' . self::DIR . '/' . self::READS;
        try {
            @file_put_contents($file, json_encode(['id' => basename($id), 'at' => gmdate(DATE_ATOM), 'by' => self::env('SEMITEXA_AGENT_SESSION')], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
        }
    }

    /**
     * Receipts nobody has checked with ai:verify:receipt, newest first: the
     * runs whose outcome reached nobody but the agent that ran them. A subagent
     * that saw red and reported green leaves exactly this behind.
     *
     * @return list<array{id: string, generated_at: ?string, verdict: ?string, run_by: mixed}>
     */
    public function unread(?int $withinSeconds = null): array
    {
        $read = [];
        $reads = $this->projectRoot . '/' . self::DIR . '/' . self::READS;
        foreach (is_file($reads) ? (file($reads, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry) && is_string($entry['id'] ?? null)) {
                $read[$entry['id']] = true;
            }
        }
        $cutoff = $withinSeconds === null ? null : time() - $withinSeconds;
        $unread = [];
        $files = glob($this->projectRoot . '/' . self::DIR . '/rcpt-*.json') ?: [];
        rsort($files);
        foreach ($files as $file) {
            $id = basename($file, '.json');
            if (isset($read[$id])) {
                continue;
            }
            $receipt = json_decode((string) file_get_contents($file), true);
            if (!is_array($receipt)) {
                continue;
            }
            $at = is_string($receipt['generated_at'] ?? null) ? $receipt['generated_at'] : null;
            if ($cutoff !== null && ($at === null || strtotime($at) < $cutoff)) {
                continue;
            }
            $unread[] = ['id' => $id, 'generated_at' => $at, 'verdict' => is_string($receipt['verdict'] ?? null) ? $receipt['verdict'] : null, 'run_by' => $receipt['run_by'] ?? null];
        }

        return $unread;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param list<string> $paths
     * @return array<string, ?string> path => sha256 of its content, null when it does not exist
     */
    private function fingerprint(array $paths): array
    {
        $tree = [];
        foreach ($paths as $path) {
            if ($path === '') {
                continue;
            }
            $file = $this->projectRoot . '/' . $path;
            $tree[$path] = is_file($file) ? (hash_file('sha256', $file) ?: null) : null;
        }
        ksort($tree);

        return $tree;
    }

    /** @param array<string, mixed> $receipt */
    private static function digest(array $receipt): string
    {
        return hash('sha256', (string) json_encode($receipt, JSON_UNESCAPED_SLASHES));
    }

    private function latest(): ?string
    {
        $files = glob($this->projectRoot . '/' . self::DIR . '/rcpt-*.json') ?: [];
        sort($files);

        return $files === [] ? null : end($files);
    }

    private function prune(string $dir): void
    {
        $files = glob($dir . '/rcpt-*.json') ?: [];
        sort($files);
        foreach (array_slice($files, 0, max(0, count($files) - self::KEEP)) as $old) {
            @unlink($old);
        }
    }
}

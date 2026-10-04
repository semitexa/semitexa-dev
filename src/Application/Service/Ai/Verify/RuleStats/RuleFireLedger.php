<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\RuleStats;

/**
 * Every ai:verify run's rule record, one line each, plus the runs a trace kept.
 *
 * A trace keeps a run only when the agent traced it; this ledger keeps every
 * run, so a rule's chances are not undercounted by how the run was started.
 * It lives in var/run/ (runtime state, git-ignored) and keeps the last
 * MAX_RUNS runs. Recording never fails the run that is being recorded.
 */
final class RuleFireLedger
{
    public const FILE = 'var/run/verify-rule-fires.ndjson';

    private const MAX_RUNS = 5000;

    public function __construct(private readonly string $projectRoot) {}

    /** @param array<string, mixed> $envelope */
    public function record(array $envelope): void
    {
        $run = RuleFires::fromEnvelope($envelope);
        if ($run === null) {
            return;
        }
        $file = $this->projectRoot . '/' . self::FILE;
        try {
            if (!is_dir(dirname($file))) {
                @mkdir(dirname($file), 0775, true);
            }
            @file_put_contents($file, json_encode($run, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
            if (@filesize($file) > self::MAX_RUNS * 400) {
                $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
                @file_put_contents($file, implode("\n", array_slice($lines, -self::MAX_RUNS)) . "\n", LOCK_EX);
            }
        } catch (\Throwable) {
            // Statistics about the gate must never become a reason the gate fails.
        }
    }

    /**
     * Every run known: this ledger and every verify_result a trace kept, one
     * per generated_at (a traced run is in both).
     *
     * @return list<array{at: string, chances: list<string>, fired: list<string>}>
     */
    public function runs(): array
    {
        $runs = [];
        foreach ($this->lines($this->projectRoot . '/' . self::FILE) as $line) {
            $run = json_decode($line, true);
            if (is_array($run) && is_string($run['at'] ?? null) && is_array($run['chances'] ?? null) && is_array($run['fired'] ?? null)) {
                $runs[$run['at']] = ['at' => $run['at'], 'chances' => array_values(array_filter($run['chances'], is_string(...))), 'fired' => array_values(array_filter($run['fired'], is_string(...)))];
            }
        }
        foreach (glob($this->projectRoot . '/var/ai-traces/*.ndjson') ?: [] as $trace) {
            foreach ($this->lines($trace) as $line) {
                if (!str_contains($line, '"verify_result"')) {
                    continue;
                }
                $event = json_decode($line, true);
                if (!is_array($event) || ($event['event_kind'] ?? null) !== 'verify_result' || !is_array($event['payload'] ?? null)) {
                    continue;
                }
                /** @var array<string, mixed> $payload */
                $payload = $event['payload'];
                $run = RuleFires::fromEnvelope($payload);
                if ($run !== null) {
                    $runs[$run['at']] ??= $run;
                }
            }
        }
        ksort($runs);

        return array_values($runs);
    }

    /** @return list<string> */
    private function lines(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? [] : $lines;
    }
}

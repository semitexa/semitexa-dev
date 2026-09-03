<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Trace\ObservatoryMode;
use Semitexa\Dev\Application\Service\Trace\ObservatoryReader;
use Semitexa\Dev\Application\Service\Trace\ReplayRunner;
use Semitexa\Dev\Application\Service\Trace\EntryMethodCatalog;
use Semitexa\Dev\Application\Service\Trace\SourceSliceReader;
use Semitexa\Dev\Application\Service\Trace\SpanTarget;
use Semitexa\Dev\Application\Service\Trace\TraceReader;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The AI agent's window into the Observatory — the same journal and traces the
 * human panel reads, rendered for a context budget instead of a screen.
 *
 * Design rules (ep-observatory, decision 4):
 *  - the agent consumes the DATA, never the human HTML;
 *  - summaries first, bytes on demand: `ps` is a snapshot, `show` is one
 *    process, `tail` is raw NDJSON rows — nothing dumps everything;
 *  - `tail --follow` streams new journal rows as they land and exits after
 *    --duration seconds, because an agent cannot Ctrl-C.
 *
 * Everything here is read-only over files; no server round-trip, so it works
 * with the app down (the journal is still there — often exactly when a crash
 * is being investigated).
 */
#[AsCommand(
    name: 'ai:observe',
    description: 'Observatory for agents: ps (live snapshot) | tail (journal rows, --follow streams) | show --id (one process + its trace)',
)]
final class AiObserveCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected ObservatoryReader $reader;

    #[InjectAsReadonly]
    protected TraceReader $traces;

    #[InjectAsReadonly]
    protected ReplayRunner $replayRunner;

    #[InjectAsReadonly]
    protected SourceSliceReader $source;

    public function __construct()
    {
        parent::__construct('ai:observe');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'ps | tail | show | replay')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Process id (show, replay)')
            ->addOption('mutate', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Override an input field, k=v; v parsed as JSON when it parses (replay, repeatable)')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'Filter: process kind (tail)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Filter: name substring (tail)')
            ->addOption('lines', null, InputOption::VALUE_REQUIRED, 'How many rows (tail, default 50, max 500)')
            ->addOption('follow', null, InputOption::VALUE_NONE, 'Stream new rows as they land (tail)')
            ->addOption('duration', null, InputOption::VALUE_REQUIRED, 'Seconds to follow before exiting (default 15, max 300)')
            ->addOption('source', null, InputOption::VALUE_NONE, 'Inline the source of the method/class each traced span ran (show; dev-only)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Accepted for ai:* symmetry; output is always JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->reader->isEnabled()) {
            $output->writeln((string) json_encode([
                'artifact' => 'semitexa-dev.ai-observe.error/v1',
                'error' => 'observatory-disabled',
                'hint' => 'The journal is off here: APP_ENV must be dev, or SEMITEXA_OBSERVATORY_MODE=monitor for journal-only production observability.',
            ]));

            return self::FAILURE;
        }

        return match ($input->getArgument('action')) {
            'ps' => $this->ps($output),
            'tail' => $this->tail($input, $output),
            'show' => $this->show($input, $output),
            'replay' => $this->replay($input, $output),
            default => $this->unknown($output),
        };
    }

    /**
     * Sandbox replay of one recorded process: same route, the recorded payload
     * snapshot (with --mutate overrides) through the real handler — writes
     * rolled back, queue handoffs captured — then a trace-vs-trace diff.
     */
    private function replay(InputInterface $input, OutputInterface $output): int
    {
        // Monitor mode reads the journal; it never re-executes anything.
        // ReplayRunner refuses too — this early exit just says why, first.
        if (!ObservatoryMode::full()) {
            return $this->fail(
                $output,
                'replay-requires-dev',
                'Replay executes recorded requests and is dev-only; monitor mode is journal-only by design.',
            );
        }

        $id = $this->strOption($input, 'id');
        if ($id === null) {
            return $this->fail($output, 'missing-id', 'ai:observe replay --id=<process-id> [--mutate k=v]');
        }

        $found = $this->reader->find($id);
        $traceFile = $found['end']['trace'] ?? null;
        if (!is_string($traceFile) || $traceFile === '') {
            return $this->fail(
                $output,
                'not-traced',
                'Replay needs the recorded envelope; this process has no trace. Re-run the request with ?__trace=1 first.',
            );
        }

        $mutations = [];
        foreach ((array) $input->getOption('mutate') as $pair) {
            if (!is_string($pair) || !str_contains($pair, '=')) {
                return $this->fail($output, 'bad-mutation', "each --mutate must be k=v, got: " . (string) $pair);
            }
            [$k, $v] = explode('=', $pair, 2);
            $decoded = json_decode($v, true);
            $mutations[$k] = json_last_error() === JSON_ERROR_NONE ? $decoded : $v;
        }

        $result = $this->replayRunner->replay($traceFile, $mutations);
        if (isset($result['error'])) {
            $output->writeln((string) json_encode(['artifact' => 'semitexa-dev.ai-observe.error/v1'] + $result));

            return self::FAILURE;
        }

        $envelope = ['artifact' => 'semitexa-dev.ai-observe.replay/v1', 'id' => $id] + $result;
        if (is_string($result['replay_trace'] ?? null)) {
            $envelope['diff'] = $this->diffTraces($traceFile, $result['replay_trace']);
        }

        $output->writeln((string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $result['verdict'] === 'handler_threw' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Span-level comparison of the two recordings. Timing deltas on shared
     * spans are labeled expected drift — a replay runs on a different clock
     * with different caches; STRUCTURE changes (spans or queries appearing or
     * vanishing) are what a behaviour difference actually looks like.
     *
     * @return array<string, mixed>
     */
    private function diffTraces(string $originalFile, string $replayFile): array
    {
        $profile = function (string $file): ?array {
            $trace = $this->traces->read($file);
            if ($trace === null) {
                return null;
            }
            $spans = [];
            foreach ($trace['spans'] as $span) {
                $name = (string) $span['name'];
                $spans[$name] = ($spans[$name] ?? 0) + 1;
            }

            return ['spans' => $spans, 'queries' => count($trace['queries']), 'totalMs' => $trace['meta']['totalMs']];
        };

        $orig = $profile($originalFile);
        $replay = $profile($replayFile);
        if ($orig === null || $replay === null) {
            return ['error' => 'diff-unavailable'];
        }

        $added = array_diff_key($replay['spans'], $orig['spans']);
        $missing = array_diff_key($orig['spans'], $replay['spans']);
        $countChanged = [];
        foreach (array_intersect_key($orig['spans'], $replay['spans']) as $name => $n) {
            if ($replay['spans'][$name] !== $n) {
                $countChanged[$name] = ['original' => $n, 'replay' => $replay['spans'][$name]];
            }
        }

        return [
            'spans_added' => $added,
            'spans_missing' => $missing,
            'span_count_changed' => $countChanged,
            'queries' => ['original' => $orig['queries'], 'replay' => $replay['queries']],
            'total_ms' => [
                'original' => $orig['totalMs'],
                'replay' => $replay['totalMs'],
                'note' => 'timing drift is expected; structure changes are the signal',
            ],
            'structurally_identical' => $added === [] && $missing === [] && $countChanged === []
                && $orig['queries'] === $replay['queries'],
        ];
    }

    private function fail(OutputInterface $output, string $error, string $hint): int
    {
        $output->writeln((string) json_encode([
            'artifact' => 'semitexa-dev.ai-observe.error/v1',
            'error' => $error,
            'hint' => $hint,
        ]));

        return self::FAILURE;
    }

    private function ps(OutputInterface $output): int
    {
        $snapshot = $this->reader->snapshot();

        $envelope = [
            'artifact' => 'semitexa-dev.ai-observe.ps/v1',
        ] + $snapshot + [
            'next_command' => [
                ['cmd' => 'ai:observe', 'args' => ['show', '--id=<process-id>'], 'why' => 'inspect one process, including its trace when it was recorded'],
                ['cmd' => 'ai:observe', 'args' => ['tail', '--follow', '--duration=15'], 'why' => 'watch new journal rows arrive live'],
            ],
        ];

        $output->writeln((string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function tail(InputInterface $input, OutputInterface $output): int
    {
        $kind = $this->strOption($input, 'kind');
        $name = $this->strOption($input, 'name');
        $lines = max(1, min(500, (int) ($input->getOption('lines') ?: 50)));

        foreach ($this->reader->tailRecords($lines, $kind, $name) as $row) {
            $output->writeln((string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        if (!$input->getOption('follow')) {
            return self::SUCCESS;
        }

        // Follow by file growth: remember the offset, poll for appended bytes,
        // emit whole lines. Exits on its own — an agent cannot Ctrl-C, so an
        // unbounded follow would hang the tool call that issued it.
        $duration = max(1, min(300, (int) ($input->getOption('duration') ?: 15)));
        $deadline = microtime(true) + $duration;
        $path = $this->reader->todayJournalPath();
        $offset = is_file($path) ? (int) filesize($path) : 0;
        $carry = '';

        while (microtime(true) < $deadline) {
            usleep(200_000);
            // Re-resolve EVERY iteration: at midnight the journal rolls to a
            // new dated file while the old one still exists and stops growing
            // — a follower pinned to the old path would go silent for the
            // rest of the duration.
            $current = $this->reader->todayJournalPath();
            if ($current !== $path) {
                $path = $current;
                $offset = 0;
                $carry = '';
            }
            clearstatcache(true, $path);
            if (!is_file($path)) {
                // First write of the day still pending.
                $offset = 0;
                continue;
            }
            $size = (int) filesize($path);
            if ($size < $offset) {
                // Truncated (manual cleanup): restart from the top rather
                // than waiting forever for the size to catch up.
                $offset = 0;
                $carry = '';
            }
            if ($size <= $offset) {
                continue;
            }
            $chunk = (string) file_get_contents($path, false, null, $offset, $size - $offset);
            $offset = $size;
            $carry .= $chunk;

            while (($nl = strpos($carry, "\n")) !== false) {
                $line = substr($carry, 0, $nl);
                $carry = substr($carry, $nl + 1);
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                if ($kind !== null && ($row['kind'] ?? '') !== $kind) {
                    continue;
                }
                if ($name !== null && !str_contains((string) ($row['name'] ?? ''), $name)) {
                    continue;
                }
                $output->writeln((string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        return self::SUCCESS;
    }

    private function show(InputInterface $input, OutputInterface $output): int
    {
        $id = $this->strOption($input, 'id');
        if ($id === null) {
            $output->writeln((string) json_encode([
                'artifact' => 'semitexa-dev.ai-observe.error/v1',
                'error' => 'missing-id',
                'hint' => 'ai:observe show --id=<process-id>; ids come from ps or tail.',
            ]));

            return self::FAILURE;
        }

        $found = $this->reader->find($id);
        if ($found['begin'] === null && $found['end'] === null) {
            $output->writeln((string) json_encode([
                'artifact' => 'semitexa-dev.ai-observe.error/v1',
                'error' => 'unknown-process',
                'id' => $id,
                'hint' => 'Not in the journal tail window. ai:observe ps lists what is visible.',
            ]));

            return self::FAILURE;
        }

        $envelope = [
            'artifact' => 'semitexa-dev.ai-observe.show/v1',
            'id' => $id,
            'begin' => $found['begin'],
            'end' => $found['end'],
            'status' => $found['end'] !== null ? 'done' : 'live',
        ];

        // Bytes on demand: the full span/query resolution only when this
        // process was traced, and only for the process that was asked about.
        $traceFile = $found['end']['trace'] ?? null;
        if (is_string($traceFile) && $traceFile !== '') {
            $trace = $this->traces->read($traceFile);
            if ($trace !== null) {
                if ((bool) $input->getOption('source')) {
                    if (!ObservatoryMode::full()) {
                        return $this->fail(
                            $output,
                            'source-requires-dev',
                            'Inlining source reads files from the working copy and is dev-only; monitor mode is journal-only by design.',
                        );
                    }
                    $trace = $this->withSource($trace);
                    // An object even when empty: the field is a map keyed
                    // Class::method, and a consumer indexing it must not meet
                    // a JSON array on the one trace that named no class.
                    $envelope['source'] = (object) $trace['source'];
                    unset($trace['source']);
                }
                $envelope['trace'] = $trace;
            } else {
                // The journal says a trace was written, but the file is gone
                // (rotated, or written by another instance sharing the
                // journal). Saying so beats silently looking untraced.
                $envelope['trace_missing'] = $traceFile;
            }
        } else {
            $envelope['next_command'] = [[
                'cmd' => 'browser',
                'args' => ['?__trace=1'],
                'why' => 'no trace was recorded for this process; re-run the request with the marker for full spans',
            ]];
        }

        $output->writeln((string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * Attach the source behind every span that named a class.
     *
     * Each distinct Class::method is read once and keyed under `source`; the
     * span carries only the key (`source_ref`), so a handler that ran ten
     * times costs one slice, not ten. The same resolution the HTML node page
     * uses: the recorded method when the span has one, the conventional entry
     * method otherwise, the class when neither exists.
     *
     * @param  array<string, mixed> $trace
     * @return array<string, mixed> the trace with `source_ref` on spans and a top-level `source` map
     */
    private function withSource(array $trace): array
    {
        $catalog = new EntryMethodCatalog();
        $sources = [];

        /** @var list<array<string, mixed>> $spans */
        $spans = is_array($trace['spans'] ?? null) ? $trace['spans'] : [];
        foreach ($spans as $i => $span) {
            $target = SpanTarget::of(is_array($span['context'] ?? null) ? $span['context'] : []);
            if ($target === null) {
                continue;
            }

            $key = $target->key();
            if (!array_key_exists($key, $sources)) {
                $slice = $target->method !== null
                    ? $this->source->slice($target->class, $target->method)
                    : $this->source->sliceAny($target->class, $catalog->candidates($target->class, null));
                // null stays in the map: the span still says it pointed
                // somewhere, and the consumer learns the source was unreadable
                // instead of wondering why a key is missing.
                $sources[$key] = $slice?->toArray();
            }

            $spans[$i]['source_ref'] = $key;
        }

        $trace['spans'] = $spans;
        $trace['source'] = $sources;

        return $trace;
    }

    private function unknown(OutputInterface $output): int
    {
        $output->writeln((string) json_encode([
            'artifact' => 'semitexa-dev.ai-observe.error/v1',
            'error' => 'unknown-action',
            'hint' => 'Actions: ps | tail [--kind= --name= --lines= --follow --duration=] | show --id= [--source] | replay --id= [--mutate k=v]',
        ]));

        return self::FAILURE;
    }

    private function strOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Trace;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Resolves the active trace id for a command invocation and appends an event
 * to it, without forcing every caller to re-implement the resolution order.
 *
 * Resolution order (first match wins):
 *   1. `--trace=<id>` option on the command
 *   2. `$SEMITEXA_AI_TRACE_ID` env var (lets a workflow pin one trace for a
 *      whole session without threading --trace through every invocation)
 *   3. no active trace — {@see appendIfActive()} becomes a no-op
 *
 * Errors are never thrown out: missing traces become `trace_skipped`, invalid
 * ids / write failures become `trace_error`. The goal is that trace wiring
 * must not affect the primary exit code or output of any command.
 */
final class TraceAutoAppender
{
    public const ENV_VAR = 'SEMITEXA_AI_TRACE_ID';

    public function __construct(
        private readonly TraceStore $store,
    ) {}

    public function resolveTraceId(InputInterface $input): ?string
    {
        if ($input->hasOption('trace')) {
            $explicit = $input->getOption('trace');
            if (is_string($explicit) && $explicit !== '') {
                return $explicit;
            }
        }
        $env = getenv(self::ENV_VAR);
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function appendIfActive(
        InputInterface $input,
        OutputInterface $output,
        string $eventKind,
        string $summary,
        array $payload = [],
    ): ?TraceEvent {
        $id = $this->resolveTraceId($input);
        if ($id === null) {
            return null;
        }

        try {
            TraceStore::assertValidId($id);
        } catch (\InvalidArgumentException $e) {
            $this->emit($output, 'trace_error', ['trace_id' => $id, 'error' => $e->getMessage()]);
            return null;
        }

        if (!$this->store->exists($id)) {
            $this->emit($output, 'trace_skipped', [
                'trace_id' => $id,
                'reason'   => 'trace does not exist — call ai:trace start first',
            ]);
            return null;
        }

        try {
            $event = $this->store->append($id, $eventKind, $summary, $payload);
        } catch (\RuntimeException $e) {
            $this->emit($output, 'trace_error', ['trace_id' => $id, 'error' => $e->getMessage()]);
            return null;
        }

        $this->emit($output, 'trace_appended', [
            'trace_id'   => $id,
            'event_id'   => $event->eventId,
            'event_kind' => $event->eventKind,
        ]);
        return $event;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function emit(OutputInterface $output, string $kind, array $payload): void
    {
        $output->writeln(json_encode(['kind' => $kind] + $payload, JSON_UNESCAPED_SLASHES));
    }
}

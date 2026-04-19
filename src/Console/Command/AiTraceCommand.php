<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\Ai\Trace\Trace;
use Semitexa\Dev\Ai\Trace\TraceEvent;
use Semitexa\Dev\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Ai\Trace\TraceHeader;
use Semitexa\Dev\Ai\Trace\TraceStore;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Durable per-task trace surface for agents.
 *
 *   bin/semitexa ai:trace start  --id=ship-feature-x [--topic=...] [--recipe=...]
 *   bin/semitexa ai:trace append --id=ship-feature-x --kind=verify_result --summary="..." [--payload=<json>]
 *   bin/semitexa ai:trace show   --id=ship-feature-x [--json]
 *   bin/semitexa ai:trace list   [--json]
 *
 * NDJSON is the default output (one JSON object per line); `--json` flips to
 * a single `semitexa-dev.ai-trace-report/v1` envelope. The on-disk format is
 * also NDJSON (see {@see TraceStore}) so `show` is effectively a decode +
 * re-print with schema validation.
 */
#[AsCommand(name: 'ai:trace', description: 'Durable per-task trace: start/append/show/list events across sessions')]
final class AiTraceCommand extends BaseCommand
{
    private const ACTION_START  = 'start';
    private const ACTION_APPEND = 'append';
    private const ACTION_SHOW   = 'show';
    private const ACTION_LIST   = 'list';

    #[InjectAsReadonly]
    protected TraceStore $traceStore;

    public function __construct()
    {
        parent::__construct('ai:trace');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'start | append | show | list')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Trace id (a-z, 0-9, -, _)')
            ->addOption('topic', null, InputOption::VALUE_REQUIRED, 'Human-readable topic (start only)')
            ->addOption('recipe', null, InputOption::VALUE_REQUIRED, 'Recipe id, if the trace is for a recipe (start only)')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'Event kind — see TraceEventKind (append only)')
            ->addOption('summary', null, InputOption::VALUE_REQUIRED, 'One-line summary (append only)')
            ->addOption('payload', null, InputOption::VALUE_REQUIRED, 'JSON-encoded payload (append only)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a single JSON envelope instead of NDJSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');
        $jsonMode = (bool) $input->getOption('json');
        $store = $this->traceStore;

        return match ($action) {
            self::ACTION_START  => $this->start($input, $output, $store, $jsonMode),
            self::ACTION_APPEND => $this->append($input, $output, $store, $jsonMode),
            self::ACTION_SHOW   => $this->show($input, $output, $store, $jsonMode),
            self::ACTION_LIST   => $this->list($output, $store, $jsonMode),
            default             => $this->error($output, "unknown action: '{$action}' (expected start | append | show | list)", $jsonMode),
        };
    }

    private function start(InputInterface $input, OutputInterface $output, TraceStore $store, bool $jsonMode): int
    {
        $id = $this->requireId($input, $output, $jsonMode);
        if ($id === null) {
            return self::FAILURE;
        }
        try {
            $header = $store->openOrCreate(
                $id,
                $input->getOption('topic') !== null ? (string) $input->getOption('topic') : null,
                $input->getOption('recipe') !== null ? (string) $input->getOption('recipe') : null,
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error($output, $e->getMessage(), $jsonMode);
        }

        $record = [
            'artifact' => 'semitexa-dev.ai-trace-start/v1',
            'action'   => self::ACTION_START,
            'status'   => $store->exists($id) ? 'opened' : 'created',
            'header'   => $header->toArray(),
            'path'     => $this->relPath($store->pathFor($id)),
        ];
        $output->writeln(json_encode($record, JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function append(InputInterface $input, OutputInterface $output, TraceStore $store, bool $jsonMode): int
    {
        $id = $this->requireId($input, $output, $jsonMode);
        if ($id === null) {
            return self::FAILURE;
        }

        $kind = (string) ($input->getOption('kind') ?? '');
        if ($kind === '') {
            return $this->error($output, '--kind is required for append', $jsonMode);
        }
        if (!TraceEventKind::isKnown($kind)) {
            return $this->error(
                $output,
                "unknown event kind '{$kind}'; allowed: " . implode(', ', TraceEventKind::all()),
                $jsonMode,
            );
        }

        $summary = (string) ($input->getOption('summary') ?? '');
        if ($summary === '') {
            return $this->error($output, '--summary is required for append', $jsonMode);
        }

        $payload = [];
        $payloadRaw = $input->getOption('payload');
        if ($payloadRaw !== null && $payloadRaw !== '') {
            try {
                $decoded = json_decode((string) $payloadRaw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return $this->error($output, 'invalid --payload JSON: ' . $e->getMessage(), $jsonMode);
            }
            if (!is_array($decoded)) {
                return $this->error($output, '--payload must decode to a JSON object', $jsonMode);
            }
            $payload = $decoded;
        }

        try {
            $event = $store->append($id, $kind, $summary, $payload);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error($output, $e->getMessage(), $jsonMode);
        }

        $record = [
            'artifact' => 'semitexa-dev.ai-trace-append/v1',
            'action'   => self::ACTION_APPEND,
            'trace_id' => $id,
            'event'    => $event->toArray(),
        ];
        $output->writeln(json_encode($record, JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function show(InputInterface $input, OutputInterface $output, TraceStore $store, bool $jsonMode): int
    {
        $id = $this->requireId($input, $output, $jsonMode);
        if ($id === null) {
            return self::FAILURE;
        }
        try {
            $trace = $store->read($id);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error($output, $e->getMessage(), $jsonMode);
        }

        if ($jsonMode) {
            $output->writeln(json_encode([
                'artifact'   => 'semitexa-dev.ai-trace-report/v1',
                'action'     => self::ACTION_SHOW,
                'header'     => $trace->header->toArray(),
                'events'     => array_map(static fn(TraceEvent $e) => $e->toArray(), $trace->events),
                'event_count'=> count($trace->events),
            ], JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $output->writeln(json_encode([
            'kind'        => 'summary',
            'trace_id'    => $trace->header->traceId,
            'topic'       => $trace->header->topic,
            'recipe'      => $trace->header->recipe,
            'created_at'  => $trace->header->createdAt,
            'event_count' => count($trace->events),
        ], JSON_UNESCAPED_SLASHES));
        $output->writeln(json_encode($trace->header->toArray(), JSON_UNESCAPED_SLASHES));
        foreach ($trace->events as $event) {
            $output->writeln(json_encode($event->toArray(), JSON_UNESCAPED_SLASHES));
        }
        return self::SUCCESS;
    }

    private function list(OutputInterface $output, TraceStore $store, bool $jsonMode): int
    {
        $headers = $store->list();
        $rows = array_map(static fn(TraceHeader $h) => $h->toArray(), $headers);

        if ($jsonMode) {
            $output->writeln(json_encode([
                'artifact'    => 'semitexa-dev.ai-trace-list/v1',
                'action'      => self::ACTION_LIST,
                'traces'      => $rows,
                'trace_count' => count($rows),
            ], JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $output->writeln(json_encode([
            'kind'        => 'summary',
            'trace_count' => count($rows),
        ], JSON_UNESCAPED_SLASHES));
        foreach ($rows as $row) {
            $output->writeln(json_encode(['kind' => 'trace'] + $row, JSON_UNESCAPED_SLASHES));
        }
        return self::SUCCESS;
    }

    private function requireId(InputInterface $input, OutputInterface $output, bool $jsonMode): ?string
    {
        $id = (string) ($input->getOption('id') ?? '');
        if ($id === '') {
            $this->error($output, '--id is required', $jsonMode);
            return null;
        }
        try {
            TraceStore::assertValidId($id);
        } catch (\InvalidArgumentException $e) {
            $this->error($output, $e->getMessage(), $jsonMode);
            return null;
        }
        return $id;
    }

    private function error(OutputInterface $output, string $message, bool $jsonMode): int
    {
        if ($jsonMode) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa-dev.ai-trace-report/v1',
                'status'   => 'error',
                'error'    => $message,
            ], JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln(json_encode([
                'kind'  => 'error',
                'error' => $message,
            ], JSON_UNESCAPED_SLASHES));
        }
        return self::FAILURE;
    }

    private function relPath(string $abs): string
    {
        $root = $this->getProjectRoot();
        if (str_starts_with($abs, $root)) {
            return ltrim(substr($abs, strlen($root)), '/');
        }
        return $abs;
    }
}

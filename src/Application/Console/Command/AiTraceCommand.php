<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceEvent;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceHeader;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
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
 *   bin/semitexa ai:trace list   [--limit=20] [--json]   (newest first; --limit=0 for all)
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

    /**
     * A long-lived project keeps every trace it ever started: this workspace
     * holds 1,300, and an unbounded list printed 320 KB of headers, oldest
     * first — the opposite of what anyone listing traces is looking for, and
     * more context than the rest of a cold start put together.
     */
    private const DEFAULT_LIST_LIMIT = 20;

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
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many traces list returns, newest first (0 = all)', (string) self::DEFAULT_LIST_LIMIT)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a single JSON envelope instead of NDJSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->stringArgument($input, 'action');
        $jsonMode = (bool) $input->getOption('json');
        $store = $this->traceStore;

        return match ($action) {
            self::ACTION_START  => $this->start($input, $output, $store, $jsonMode),
            self::ACTION_APPEND => $this->append($input, $output, $store, $jsonMode),
            self::ACTION_SHOW   => $this->show($input, $output, $store, $jsonMode),
            self::ACTION_LIST   => $this->list($input, $output, $store, $jsonMode),
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
            $alreadyExists = $store->exists($id);
            $header = $store->openOrCreate(
                $id,
                $this->nullableStringOption($input, 'topic'),
                $this->nullableStringOption($input, 'recipe'),
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error($output, $e->getMessage(), $jsonMode);
        }

        $record = [
            'artifact' => 'semitexa-dev.ai-trace-start/v1',
            'action'   => self::ACTION_START,
            'status'   => $alreadyExists ? 'opened' : 'created',
            'header'   => $header->toArray(),
            'path'     => $this->relPath($store->pathFor($id)),
        ];
        $this->writeJson($output, $record);
        return self::SUCCESS;
    }

    private function append(InputInterface $input, OutputInterface $output, TraceStore $store, bool $jsonMode): int
    {
        $id = $this->requireId($input, $output, $jsonMode);
        if ($id === null) {
            return self::FAILURE;
        }

        $kind = $this->stringOption($input, 'kind');
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

        $summary = $this->stringOption($input, 'summary');
        if ($summary === '') {
            return $this->error($output, '--summary is required for append', $jsonMode);
        }

        try {
            $payload = $this->decodePayload($input);
        } catch (\InvalidArgumentException $e) {
            return $this->error($output, $e->getMessage(), $jsonMode);
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
        $this->writeJson($output, $record);
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
            $this->writeJson($output, [
                'artifact'   => 'semitexa-dev.ai-trace-report/v1',
                'action'     => self::ACTION_SHOW,
                'header'     => $trace->header->toArray(),
                'events'     => array_map(static fn(TraceEvent $e) => $e->toArray(), $trace->events),
                'event_count'=> count($trace->events),
            ]);
            return self::SUCCESS;
        }

        $this->writeJson($output, [
            'kind'        => 'summary',
            'trace_id'    => $trace->header->traceId,
            'topic'       => $trace->header->topic,
            'recipe'      => $trace->header->recipe,
            'created_at'  => $trace->header->createdAt,
            'event_count' => count($trace->events),
        ]);
        $this->writeJson($output, $trace->header->toArray());
        foreach ($trace->events as $event) {
            $this->writeJson($output, $event->toArray());
        }
        return self::SUCCESS;
    }

    private function list(InputInterface $input, OutputInterface $output, TraceStore $store, bool $jsonMode): int
    {
        $limitRaw = $this->stringOption($input, 'limit');
        if (preg_match('/^\d+$/', $limitRaw) !== 1) {
            return $this->error($output, "--limit must be a non-negative integer, got '{$limitRaw}'", $jsonMode);
        }
        $limit = (int) $limitRaw;

        $headers = array_reverse($store->list());
        $total = count($headers);
        if ($limit > 0) {
            $headers = array_slice($headers, 0, $limit);
        }
        $rows = array_map(static fn(TraceHeader $h) => $h->toArray(), $headers);

        if ($jsonMode) {
            $this->writeJson($output, [
                'artifact'    => 'semitexa-dev.ai-trace-list/v1',
                'action'      => self::ACTION_LIST,
                'order'       => 'newest_first',
                'traces'      => $rows,
                'trace_count' => count($rows),
                'total'       => $total,
                'limit'       => $limit,
            ]);
            return self::SUCCESS;
        }

        $this->writeJson($output, [
            'kind'        => 'summary',
            'order'       => 'newest_first',
            'trace_count' => count($rows),
            'total'       => $total,
            'limit'       => $limit,
        ]);
        foreach ($rows as $row) {
            $this->writeJson($output, ['kind' => 'trace'] + $row);
        }
        return self::SUCCESS;
    }

    private function requireId(InputInterface $input, OutputInterface $output, bool $jsonMode): ?string
    {
        $id = $this->stringOption($input, 'id');
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
            $this->writeJson($output, [
                'artifact' => 'semitexa-dev.ai-trace-report/v1',
                'status'   => 'error',
                'error'    => $message,
            ]);
        } else {
            $this->writeJson($output, [
                'kind'  => 'error',
                'error' => $message,
            ]);
        }
        return self::FAILURE;
    }

    private function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return is_string($value) ? $value : '';
    }

    private function stringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : '';
    }

    private function nullableStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(InputInterface $input): array
    {
        $payloadRaw = $input->getOption('payload');
        if (!is_string($payloadRaw) || $payloadRaw === '') {
            return [];
        }

        try {
            $decoded = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('invalid --payload JSON: ' . $e->getMessage(), previous: $e);
        }

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('--payload must decode to a JSON object');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson(OutputInterface $output, array $payload): void
    {
        $output->writeln(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
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

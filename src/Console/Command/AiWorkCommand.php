<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\Ai\Trace\TraceEvent;
use Semitexa\Dev\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Ai\Trace\TraceStore;
use Semitexa\Dev\Ai\Work\BacklogHygiene;
use Semitexa\Dev\Ai\Work\BacklogScope;
use Semitexa\Dev\Ai\Work\EpicStore;
use Semitexa\Dev\Ai\Work\ResumeService;
use Semitexa\Dev\Ai\Work\ResumeSnapshot;
use Semitexa\Dev\Ai\Work\Task;
use Semitexa\Dev\Ai\Work\TaskStatus;
use Semitexa\Dev\Ai\Work\TaskStore;
use Semitexa\Dev\Ai\Work\WorkId;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Work item (task) CLI — the main surface for agent decomposition and resume.
 *
 *   bin/semitexa ai:work start  --id=tk-fix-wm --epic=ep-wm --title="..." [--recipe=...] [--risk=...] [--trace=...] [--context-ref=...]* [--next-step=...]
 *   bin/semitexa ai:work list   [--epic=...] [--status=new|in_progress|blocked|done] [--json]
 *   bin/semitexa ai:work show   --id=tk-fix-wm [--json]
 *   bin/semitexa ai:work update --id=tk-fix-wm [--status=...] [--title=...] [--recipe=...] [--risk=...] [--next-step=...] [--context-ref=...]*
 *   bin/semitexa ai:work note   --id=tk-fix-wm --note="..." [--next-step=...]
 *   bin/semitexa ai:work resume --id=tk-fix-wm [--tail=5]
 *
 * `start` always links the task to a trace: if `--trace` isn't supplied the
 * task id is used as the trace id, and the trace is openOrCreate'd on the
 * fly. That way every task has a durable log, and the slugs stay aligned.
 */
#[AsCommand(name: 'ai:work', description: 'Manage work tasks: start/list/show/update/resume/note (agent-facing, NDJSON)')]
final class AiWorkCommand extends BaseCommand
{
    private const ACTION_START  = 'start';
    private const ACTION_LIST   = 'list';
    private const ACTION_SHOW   = 'show';
    private const ACTION_UPDATE = 'update';
    private const ACTION_NOTE   = 'note';
    private const ACTION_RESUME = 'resume';

    private const ALLOWED_RISK = ['low', 'medium', 'high'];

    #[InjectAsReadonly]
    protected TaskStore $taskStore;

    #[InjectAsReadonly]
    protected EpicStore $epicStore;

    #[InjectAsReadonly]
    protected TraceStore $traceStore;

    #[InjectAsReadonly]
    protected ResumeService $resumeService;

    #[InjectAsReadonly]
    protected BacklogHygiene $hygiene;

    public function __construct()
    {
        parent::__construct('ai:work');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'start | list | show | update | note | resume')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Task id (a-z, 0-9, -, _)')
            ->addOption('epic', null, InputOption::VALUE_REQUIRED, 'Epic id (required on start)')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Task title')
            ->addOption('recipe', null, InputOption::VALUE_REQUIRED, 'Recipe id (see ai:task)')
            ->addOption('risk', null, InputOption::VALUE_REQUIRED, 'Risk: ' . implode('|', self::ALLOWED_RISK))
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Status: ' . implode('|', TaskStatus::all()))
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'List filter: ' . implode('|', BacklogScope::all()) . ' (default: active)')
            ->addOption('context-ref', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Path or symbol the task is anchored to (repeatable)')
            ->addOption('next-step', null, InputOption::VALUE_REQUIRED, 'One-line next step for the resuming agent')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Free-form note (appended to the linked trace)')
            ->addOption('tail', null, InputOption::VALUE_REQUIRED, 'How many trailing trace events to surface on resume (default 5)')
            ->addOption('trace', null, InputOption::VALUE_REQUIRED, 'Trace id for this task (defaults to the task id on start)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a single JSON envelope instead of NDJSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');
        $json = (bool) $input->getOption('json');

        return match ($action) {
            self::ACTION_START  => $this->start($input, $output, $json),
            self::ACTION_LIST   => $this->list($input, $output, $json),
            self::ACTION_SHOW   => $this->show($input, $output, $json),
            self::ACTION_UPDATE => $this->update($input, $output, $json),
            self::ACTION_NOTE   => $this->note($input, $output, $json),
            self::ACTION_RESUME => $this->resume($input, $output, $json),
            default             => $this->error($output, "unknown action: '{$action}' (expected start | list | show | update | note | resume)", $json),
        };
    }

    private function start(InputInterface $input, OutputInterface $output, bool $jsonMode): int
    {
        $id = $this->requireId($input, $output, $jsonMode);
        if ($id === null) {
            return self::FAILURE;
        }
        if ($this->taskStore->exists($id)) {
            return $this->error($output, "task '{$id}' already exists", $jsonMode);
        }

        $epicId = (string) ($input->getOption('epic') ?? '');
        if ($epicId === '') {
            return $this->error($output, '--epic is required for start', $jsonMode);
        }
        try {
            WorkId::assertValid($epicId, 'epic id');
        } catch (\InvalidArgumentException $e) {
            return $this->error($output, $e->getMessage(), $jsonMode);
        }
        if (!$this->epicStore->exists($epicId)) {
            return $this->error($output, "epic '{$epicId}' does not exist — run ai:epic start first", $jsonMode);
        }

        $title = (string) ($input->getOption('title') ?? '');
        if ($title === '') {
            return $this->error($output, '--title is required for start', $jsonMode);
        }

        $recipe = (string) ($input->getOption('recipe') ?? '');
        $risk = $this->normaliseRisk((string) ($input->getOption('risk') ?? 'medium'), $output, $jsonMode);
        if ($risk === null) {
            return self::FAILURE;
        }

        $traceId = (string) ($input->getOption('trace') ?? $id);
        try {
            WorkId::assertValid($traceId, 'trace id');
        } catch (\InvalidArgumentException $e) {
            return $this->error($output, $e->getMessage(), $jsonMode);
        }
        $this->traceStore->openOrCreate($traceId, topic: $title, recipe: $recipe !== '' ? $recipe : null);

        $now = date('c');
        $refs = $this->contextRefs($input);
        $task = new Task(
            id:           $id,
            epicId:       $epicId,
            title:        $title,
            status:       TaskStatus::NEW,
            recipe:       $recipe,
            risk:         $risk,
            contextRefs:  $refs,
            nextStep:     $this->optionalString($input, 'next-step'),
            traceId:      $traceId,
            createdAt:    $now,
            updatedAt:    $now,
        );
        $this->taskStore->save($task);

        $this->traceStore->append($traceId, TraceEventKind::TASK_RESULT, "task '{$id}' started — {$title}", [
            'artifact'     => 'semitexa.ai-work.task-start/v1',
            'task_id'      => $id,
            'epic_id'      => $epicId,
            'recipe'       => $recipe,
            'risk'         => $risk,
            'context_refs' => $refs,
            'next_step'    => $task->nextStep,
        ]);

        return $this->emitTask($output, $task, 'task_started', $jsonMode);
    }

    private function list(InputInterface $input, OutputInterface $output, bool $jsonMode): int
    {
        $epicId = $input->getOption('epic') !== null ? (string) $input->getOption('epic') : null;
        $statusRaw = $input->getOption('status');
        $status = null;
        if ($statusRaw !== null && $statusRaw !== '') {
            try {
                $status = TaskStatus::parse((string) $statusRaw);
            } catch (\InvalidArgumentException $e) {
                return $this->error($output, $e->getMessage(), $jsonMode);
            }
        }
        if ($epicId !== null) {
            try {
                WorkId::assertValid($epicId, 'epic id');
            } catch (\InvalidArgumentException $e) {
                return $this->error($output, $e->getMessage(), $jsonMode);
            }
        }

        $scopeRaw = (string) ($input->getOption('scope') ?? BacklogScope::ACTIVE->value);
        try {
            $scope = BacklogScope::parse($scopeRaw);
        } catch (\InvalidArgumentException $e) {
            return $this->error($output, $e->getMessage(), $jsonMode);
        }

        $all = $this->taskStore->list($epicId, $status);
        $rows = [];
        $hiddenByScope = 0;
        foreach ($all as $task) {
            $assessment = $this->hygiene->assessTask($task);
            if (!$this->hygiene->taskInScope($task, $assessment, $scope)) {
                $hiddenByScope++;
                continue;
            }
            $rows[] = $task->toArray() + [
                'quality'          => $assessment->quality->value,
                'issues'           => $assessment->issues,
                'suggested_status' => $assessment->suggestedStatus,
                'suggested_action' => $assessment->suggestedAction,
            ];
        }

        if ($jsonMode) {
            $output->writeln(json_encode([
                'artifact'        => 'semitexa.ai-work.task-list/v1',
                'epic'            => $epicId,
                'status'          => $status?->value,
                'scope'           => $scope->value,
                'tasks'           => $rows,
                'task_count'      => count($rows),
                'hidden_by_scope' => $hiddenByScope,
                'total_in_store'  => count($all),
            ], JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $output->writeln(json_encode([
            'kind'            => 'summary',
            'epic'            => $epicId,
            'status'          => $status?->value,
            'scope'           => $scope->value,
            'task_count'      => count($rows),
            'hidden_by_scope' => $hiddenByScope,
            'total_in_store'  => count($all),
        ], JSON_UNESCAPED_SLASHES));
        foreach ($rows as $row) {
            $output->writeln(json_encode(['kind' => 'task'] + $row, JSON_UNESCAPED_SLASHES));
        }
        return self::SUCCESS;
    }

    private function show(InputInterface $input, OutputInterface $output, bool $jsonMode): int
    {
        $id = $this->requireId($input, $output, $jsonMode);
        if ($id === null) {
            return self::FAILURE;
        }
        try {
            $task = $this->taskStore->get($id);
        } catch (\RuntimeException $e) {
            return $this->error($output, $e->getMessage(), $jsonMode);
        }
        return $this->emitTask($output, $task, 'task', $jsonMode);
    }

    private function update(InputInterface $input, OutputInterface $output, bool $jsonMode): int
    {
        $id = $this->requireId($input, $output, $jsonMode);
        if ($id === null) {
            return self::FAILURE;
        }
        try {
            $task = $this->taskStore->get($id);
        } catch (\RuntimeException $e) {
            return $this->error($output, $e->getMessage(), $jsonMode);
        }

        $statusRaw = $input->getOption('status');
        $status = null;
        if ($statusRaw !== null && $statusRaw !== '') {
            try {
                $status = TaskStatus::parse((string) $statusRaw);
            } catch (\InvalidArgumentException $e) {
                return $this->error($output, $e->getMessage(), $jsonMode);
            }
        }

        $title = $this->optionalString($input, 'title');
        $recipe = $this->optionalString($input, 'recipe');
        $riskRaw = $this->optionalString($input, 'risk');
        $risk = null;
        if ($riskRaw !== null) {
            $risk = $this->normaliseRisk($riskRaw, $output, $jsonMode);
            if ($risk === null) {
                return self::FAILURE;
            }
        }
        $nextStep = $this->optionalString($input, 'next-step');
        $refs = $this->contextRefs($input);
        $refsOpt = $refs === [] ? null : $refs;

        if ($title === null && $recipe === null && $risk === null && $status === null && $nextStep === null && $refsOpt === null) {
            return $this->error($output, 'update requires at least one of --title, --recipe, --risk, --status, --next-step, --context-ref', $jsonMode);
        }

        $updated = $task->with(
            title:       $title,
            status:      $status,
            recipe:      $recipe,
            risk:        $risk,
            contextRefs: $refsOpt,
            nextStep:    $nextStep,
            updatedAt:   date('c'),
        );
        $this->taskStore->save($updated);

        $kind = $status !== null ? TraceEventKind::NEXT_STEP : TraceEventKind::NOTE;
        $summary = $status !== null
            ? "task '{$id}' status → {$status->value}"
            : "task '{$id}' updated";
        $this->safeAppend($updated->traceId, $kind, $summary, [
            'artifact'  => 'semitexa.ai-work.task-update/v1',
            'task_id'   => $id,
            'changes'   => array_filter([
                'title'        => $title,
                'recipe'       => $recipe,
                'risk'         => $risk,
                'status'       => $status?->value,
                'next_step'    => $nextStep,
                'context_refs' => $refsOpt,
            ], static fn($v) => $v !== null),
        ]);

        return $this->emitTask($output, $updated, 'task_updated', $jsonMode);
    }

    private function note(InputInterface $input, OutputInterface $output, bool $jsonMode): int
    {
        $id = $this->requireId($input, $output, $jsonMode);
        if ($id === null) {
            return self::FAILURE;
        }
        try {
            $task = $this->taskStore->get($id);
        } catch (\RuntimeException $e) {
            return $this->error($output, $e->getMessage(), $jsonMode);
        }

        $note = $this->optionalString($input, 'note');
        $nextStep = $this->optionalString($input, 'next-step');
        if ($note === null && $nextStep === null) {
            return $this->error($output, 'note requires --note and/or --next-step', $jsonMode);
        }

        if ($nextStep !== null) {
            $task = $task->with(nextStep: $nextStep, updatedAt: date('c'));
            $this->taskStore->save($task);
        }

        $summary = $note !== null
            ? "note on task '{$id}': " . $this->truncate($note, 80)
            : "task '{$id}' next step updated";
        $payload = array_filter([
            'artifact'  => 'semitexa.ai-work.task-note/v1',
            'task_id'   => $id,
            'note'      => $note,
            'next_step' => $nextStep,
        ], static fn($v) => $v !== null);
        $this->safeAppend($task->traceId, TraceEventKind::NOTE, $summary, $payload);

        return $this->emitTask($output, $task, 'task_noted', $jsonMode);
    }

    private function resume(InputInterface $input, OutputInterface $output, bool $jsonMode): int
    {
        $id = $this->requireId($input, $output, $jsonMode);
        if ($id === null) {
            return self::FAILURE;
        }
        $tail = (int) ($input->getOption('tail') ?? 5);

        try {
            $snapshot = $this->resumeService->resume($id, $tail);
        } catch (\RuntimeException $e) {
            return $this->error($output, $e->getMessage(), $jsonMode);
        }

        $this->safeAppend(
            $snapshot->task->traceId,
            TraceEventKind::NEXT_STEP,
            "resuming task '{$id}' (status={$snapshot->task->status->value})",
            [
                'artifact'  => 'semitexa.ai-work.task-resume/v1',
                'task_id'   => $id,
                'status'    => $snapshot->task->status->value,
                'next_step' => $snapshot->task->nextStep,
            ],
        );

        return $this->emitResume($output, $snapshot, $jsonMode);
    }

    private function emitResume(OutputInterface $output, ResumeSnapshot $snapshot, bool $jsonMode): int
    {
        $envelope = [
            'artifact'      => 'semitexa.ai-work.task-resume/v1',
            'task'          => $snapshot->task->toArray(),
            'trace_header'  => $snapshot->traceHeader?->toArray(),
            'tail_events'   => array_map(static fn(TraceEvent $e) => $e->toArray(), $snapshot->tailEvents),
            'trace_warning' => $snapshot->traceWarning,
        ];

        if ($jsonMode) {
            $output->writeln(json_encode($envelope, JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $output->writeln(json_encode([
            'kind'         => 'summary',
            'task_id'      => $snapshot->task->id,
            'status'       => $snapshot->task->status->value,
            'epic_id'      => $snapshot->task->epicId,
            'trace_id'     => $snapshot->task->traceId,
            'tail_events'  => count($snapshot->tailEvents),
            'next_step'    => $snapshot->task->nextStep,
        ], JSON_UNESCAPED_SLASHES));
        $output->writeln(json_encode(['kind' => 'task'] + $snapshot->task->toArray(), JSON_UNESCAPED_SLASHES));
        if ($snapshot->traceHeader !== null) {
            $output->writeln(json_encode(['kind' => 'trace_header'] + $snapshot->traceHeader->toArray(), JSON_UNESCAPED_SLASHES));
        }
        foreach ($snapshot->tailEvents as $event) {
            $output->writeln(json_encode(['kind' => 'trace_event'] + $event->toArray(), JSON_UNESCAPED_SLASHES));
        }
        if ($snapshot->traceWarning !== null) {
            $output->writeln(json_encode([
                'kind'    => 'warning',
                'warning' => $snapshot->traceWarning,
            ], JSON_UNESCAPED_SLASHES));
        }
        $output->writeln(json_encode([
            'kind'   => 'next',
            'action' => 'proceed',
            'hint'   => $snapshot->task->nextStep ?? 'no next_step recorded — inspect trace with ai:trace show --id=' . $snapshot->task->traceId,
        ], JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function emitTask(OutputInterface $output, Task $task, string $kind, bool $jsonMode): int
    {
        if ($jsonMode) {
            $output->writeln(json_encode([
                'artifact'     => 'semitexa.ai-work.task/v1',
                'task'         => $task->toArray(),
                'next_command' => $this->buildTaskNextCommands($task),
            ], JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        $output->writeln(json_encode(['kind' => $kind] + $task->toArray(), JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    /**
     * @return list<array{cmd: string, args: list<string>, why: string}>
     */
    private function buildTaskNextCommands(Task $task): array
    {
        $out = [];
        $status = $task->status->value;

        if ($status === 'new') {
            $out[] = [
                'cmd'  => 'ai:work',
                'args' => ['update', '--id=' . $task->id, '--status=in_progress', '--json'],
                'why'  => 'mark the task started before editing',
            ];
        }

        if ($status === 'in_progress' || $status === 'blocked') {
            if ($task->contextRefs !== []) {
                $out[] = [
                    'cmd'  => 'ai:verify',
                    'args' => ['--files=' . implode(',', $task->contextRefs), '--json'],
                    'why'  => 'verify the current state of the files this task anchors to',
                ];
            }
            if ($task->recipe !== '' && $task->recipe !== 'unknown_task') {
                $out[] = [
                    'cmd'  => 'ai:context',
                    'args' => [$task->recipe, '--json'],
                    'why'  => 'prior art for recipe ' . $task->recipe,
                ];
            }
        }

        if ($status === 'done') {
            $out[] = [
                'cmd'  => 'ai:orient',
                'args' => ['--json'],
                'why'  => 'pick up the next task in the epic',
            ];
        }

        $out[] = [
            'cmd'  => 'ai:trace',
            'args' => ['show', '--id=' . $task->traceId, '--json'],
            'why'  => 'full trace history for this task',
        ];

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function safeAppend(string $traceId, string $kind, string $summary, array $payload): void
    {
        if ($traceId === '') {
            return;
        }
        if (!$this->traceStore->exists($traceId)) {
            return;
        }
        try {
            $this->traceStore->append($traceId, $kind, $summary, $payload);
        } catch (\RuntimeException) {
            // Trace hygiene is best-effort; never let it break the primary action.
        }
    }

    private function requireId(InputInterface $input, OutputInterface $output, bool $jsonMode): ?string
    {
        $id = (string) ($input->getOption('id') ?? '');
        if ($id === '') {
            $this->error($output, '--id is required', $jsonMode);
            return null;
        }
        try {
            WorkId::assertValid($id, 'task id');
        } catch (\InvalidArgumentException $e) {
            $this->error($output, $e->getMessage(), $jsonMode);
            return null;
        }
        return $id;
    }

    private function normaliseRisk(string $value, OutputInterface $output, bool $jsonMode): ?string
    {
        $lower = strtolower(trim($value));
        if (!in_array($lower, self::ALLOWED_RISK, true)) {
            $this->error(
                $output,
                "invalid risk '{$value}': expected one of " . implode(', ', self::ALLOWED_RISK),
                $jsonMode,
            );
            return null;
        }
        return $lower;
    }

    /**
     * @return list<string>
     */
    private function contextRefs(InputInterface $input): array
    {
        $raw = $input->getOption('context-ref');
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $ref) {
            $trimmed = trim((string) $ref);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }
        return $out;
    }

    private function optionalString(InputInterface $input, string $name): ?string
    {
        if (!$input->hasOption($name)) {
            return null;
        }
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }
        $value = (string) $value;
        return $value === '' ? null : $value;
    }

    private function truncate(string $value, int $max): string
    {
        return strlen($value) > $max ? substr($value, 0, $max - 1) . '…' : $value;
    }

    private function error(OutputInterface $output, string $message, bool $jsonMode): int
    {
        if ($jsonMode) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa.ai-work.task/v1',
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
}

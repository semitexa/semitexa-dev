<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Presence\AgentRegistry;
use Semitexa\Dev\Application\Service\Ai\Presence\BacklogActivity;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStore;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskApproval;
use Semitexa\Dev\Application\Service\Ai\Work\TaskFiling;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Semitexa\Dev\Application\Service\Ai\Work\WorkId;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A task is an idea until the owner approves it (ep-project-progress-from-backlog).
 *
 *   bin/semitexa ai:work:approve --id=tk-login --words="Так, робимо логін"
 *   bin/semitexa ai:work:approve --epic=ep-shop --words="Затверджую весь перелік"
 *   bin/semitexa ai:work:approve --id=tk-login --revoke --words="Поки відкладаємо"
 *
 * The owner usually says this to an agent, so the agent runs the command and
 * --words keeps what the owner said. A discarded task cannot be approved: an
 * epic passes over it and --id refuses it. --revoke reaches discarded tasks too,
 * because one reopened later must not come back approved. An approval already
 * given stays as it was. A bug counts by what it is, not by an approval, so
 * --revoke cannot take it back: --id says so and an epic leaves it. A bug nobody
 * wants is discarded.
 */
#[AsCommand(name: 'ai:work:approve', description: 'Approve a task or a whole epic for implementation, or return it to ideas; approved tasks and bugs count towards progress')]
final class AiWorkApproveCommand extends BaseCommand
{
    private const ARTIFACT = 'semitexa.ai-work.approval/v1';

    #[InjectAsReadonly]
    protected TaskStore $taskStore;

    #[InjectAsReadonly]
    protected EpicStore $epicStore;

    #[InjectAsReadonly]
    protected TraceStore $traceStore;

    public function __construct()
    {
        parent::__construct('ai:work:approve');
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'The task to approve')
            ->addOption('epic', null, InputOption::VALUE_REQUIRED, 'Approve every task in this epic except discarded ones')
            ->addOption('words', null, InputOption::VALUE_REQUIRED, 'The owner\'s own words')
            ->addOption('by', null, InputOption::VALUE_REQUIRED, 'Who decided (default: owner)')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Return to ideas instead of approving')
            ->addOption('json', null, InputOption::VALUE_NONE, 'One JSON envelope (always; kept for the ai:* convention)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        BacklogActivity::announce($output, new AgentRegistry(ProjectRoot::get()), ProjectRoot::get());
        $id = self::text($input, 'id');
        $epicId = self::text($input, 'epic');
        $words = self::text($input, 'words');
        if (($id === '') === ($epicId === '')) {
            return $this->fail($output, 'name what to approve: --id or --epic, one of them');
        }
        if ($words === '') {
            return $this->fail($output, '--words is required: what the owner said');
        }
        $by = self::text($input, 'by');
        if (!mb_check_encoding($words . $by, 'UTF-8')) {
            return $this->fail($output, '--words and --by must be valid UTF-8');
        }
        $revoke = (bool) $input->getOption('revoke');

        try {
            $tasks = $id !== '' ? [$this->taskStore->get($id)] : $this->tasksOfEpic($epicId, $revoke);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return $this->fail($output, $e->getMessage());
        }

        if ($id !== '' && !$revoke && $tasks[0]->status === TaskStatus::DISCARDED) {
            return $this->fail($output, "task '{$id}' is discarded: reopen it first if it is wanted after all");
        }
        if ($id !== '' && $revoke && TaskFiling::isBug($tasks[0]->recipe)) {
            return $this->fail($output, "task '{$id}' is a bug: it counts whatever its approval says; if nobody wants it, discard it with ai:work update --status=discarded");
        }

        $by = $by !== '' ? $by : 'owner';
        $now = date('c');
        $approval = $revoke ? null : new TaskApproval($by, $words, $now);

        $changed = [];
        $unchanged = [];
        $failed = [];
        foreach ($tasks as $task) {
            if ($task->isApproved() === ($approval !== null) || ($revoke && TaskFiling::isBug($task->recipe))) {
                $unchanged[] = $task->id;
                continue;
            }
            // One broken task file must not stop the rest, or a rerun stops at it forever.
            try {
                $this->taskStore->change($task->id, static fn (Task $current): Task => $current->isApproved() === ($approval !== null) ? $current : $current->withApproval($approval, $now));
            } catch (\RuntimeException | \InvalidArgumentException $e) {
                $failed[$task->id] = $e->getMessage();
                continue;
            }
            $this->trace($task, $revoke ? "task '{$task->id}' returned to ideas" : "task '{$task->id}' approved", $words, $by);
            $changed[] = $task->id;
        }

        $this->emit($output, [
            'status' => $failed === [] ? 'ok' : 'partial',
            'action' => $revoke ? 'revoked' : 'approved',
            'changed' => $changed,
            'unchanged' => $unchanged,
            'failed' => $failed,
        ]);

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<Task> */
    private function tasksOfEpic(string $epicId, bool $withDiscarded): array
    {
        WorkId::assertValid($epicId, 'epic id');
        if (!$this->epicStore->exists($epicId)) {
            throw new \RuntimeException("epic '{$epicId}' does not exist");
        }

        return array_values(array_filter(
            $this->taskStore->list($epicId),
            static fn (Task $task): bool => $withDiscarded || $task->status !== TaskStatus::DISCARDED,
        ));
    }

    /** Best effort, as in ai:work: the approval is saved, a trace that cannot be written must not undo that. */
    private function trace(Task $task, string $summary, string $words, string $by): void
    {
        if (!$this->traceStore->exists($task->traceId)) {
            return;
        }
        try {
            $this->traceStore->append($task->traceId, TraceEventKind::NOTE, $summary, [
                'artifact' => self::ARTIFACT,
                'task_id' => $task->id,
                'words' => $words,
                'by' => $by,
            ]);
        } catch (\RuntimeException) {
        }
    }

    private static function text(InputInterface $input, string $option): string
    {
        $value = $input->getOption($option);

        return is_string($value) ? trim($value) : '';
    }

    /** @param array<string, mixed> $payload */
    private function emit(OutputInterface $output, array $payload): void
    {
        $output->writeln(json_encode(['artifact' => self::ARTIFACT] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', OutputInterface::OUTPUT_RAW);
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln(json_encode(['artifact' => self::ARTIFACT, 'status' => 'error', 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', OutputInterface::OUTPUT_RAW);

        return self::FAILURE;
    }
}

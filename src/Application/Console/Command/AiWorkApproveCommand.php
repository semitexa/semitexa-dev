<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStore;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskApproval;
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
 * --words keeps what the owner said. An epic takes every task in it but the
 * discarded ones, and leaves an approval already given as it was.
 */
#[AsCommand(name: 'ai:work:approve', description: 'Approve a task or a whole epic for implementation, or return it to ideas; only approved tasks count towards progress')]
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
        $id = self::text($input, 'id');
        $epicId = self::text($input, 'epic');
        $words = self::text($input, 'words');
        if (($id === '') === ($epicId === '')) {
            return $this->fail($output, 'name what to approve: --id or --epic, one of them');
        }
        if ($words === '') {
            return $this->fail($output, '--words is required: what the owner said');
        }

        try {
            $tasks = $id !== '' ? [$this->taskStore->get($id)] : $this->tasksOfEpic($epicId);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return $this->fail($output, $e->getMessage());
        }

        $by = self::text($input, 'by');
        $now = date('c');
        $approval = (bool) $input->getOption('revoke') ? null : new TaskApproval($by !== '' ? $by : 'owner', $words, $now);

        $changed = [];
        $unchanged = [];
        foreach ($tasks as $task) {
            if ($task->isApproved() === ($approval !== null)) {
                $unchanged[] = $task->id;
                continue;
            }
            $this->taskStore->save($task->withApproval($approval, $now));
            $this->trace($task, $approval === null ? "task '{$task->id}' returned to ideas" : "task '{$task->id}' approved", $words);
            $changed[] = $task->id;
        }

        return $this->emit($output, [
            'status' => 'ok',
            'action' => $approval === null ? 'revoked' : 'approved',
            'changed' => $changed,
            'unchanged' => $unchanged,
        ]);
    }

    /** @return list<Task> */
    private function tasksOfEpic(string $epicId): array
    {
        WorkId::assertValid($epicId, 'epic id');
        if (!$this->epicStore->exists($epicId)) {
            throw new \RuntimeException("epic '{$epicId}' does not exist");
        }

        return array_values(array_filter(
            $this->taskStore->list($epicId),
            static fn (Task $task): bool => $task->status !== TaskStatus::DISCARDED,
        ));
    }

    /** Best effort, as in ai:work: the approval is saved, a trace that cannot be written must not undo that. */
    private function trace(Task $task, string $summary, string $words): void
    {
        if ($task->traceId === '' || !$this->traceStore->exists($task->traceId)) {
            return;
        }
        try {
            $this->traceStore->append($task->traceId, TraceEventKind::NOTE, $summary, [
                'artifact' => self::ARTIFACT,
                'task_id' => $task->id,
                'words' => $words,
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
    private function emit(OutputInterface $output, array $payload): int
    {
        $output->writeln(json_encode(['artifact' => self::ARTIFACT] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln(json_encode(['artifact' => self::ARTIFACT, 'status' => 'error', 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', OutputInterface::OUTPUT_RAW);

        return self::FAILURE;
    }
}

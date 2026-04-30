<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Ai\Work\BacklogHygiene;
use Semitexa\Dev\Ai\Work\BacklogQuality;
use Semitexa\Dev\Ai\Work\BacklogScope;
use Semitexa\Dev\Ai\Work\Epic;
use Semitexa\Dev\Ai\Work\EpicStatus;
use Semitexa\Dev\Ai\Work\EpicStore;
use Semitexa\Dev\Ai\Work\Task;
use Semitexa\Dev\Ai\Work\TaskStatus;
use Semitexa\Dev\Ai\Work\TaskStore;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *   bin/semitexa ai:backlog stats [--json]
 *   bin/semitexa ai:backlog clean [--apply] [--json]
 *
 * `stats` breaks the store down by quality (clean/draft/junk) and status
 * (new/in_progress/done/archived/discarded) so the operator can see what
 * pollutes the backlog before acting on it.
 *
 * `clean` lists hygiene proposals: junk epics/tasks get `status=discarded`,
 * stale done epics get `status=archived`. Dry-run by default — the
 * operator must pass `--apply` to mutate. `ai:backlog` never hard-deletes:
 * retired items remain on disk under `discarded` / `archived`, so the
 * audit trail survives.
 */
#[AsCommand(name: 'ai:backlog', description: 'Backlog hygiene: stats + cleanup proposals (dry-run; --apply to commit)')]
final class AiBacklogCommand extends BaseCommand
{
    private const string ACTION_STATS = 'stats';
    private const string ACTION_CLEAN = 'clean';

    #[InjectAsReadonly]
    protected EpicStore $epicStore;

    #[InjectAsReadonly]
    protected TaskStore $taskStore;

    #[InjectAsReadonly]
    protected BacklogHygiene $hygiene;

    public function __construct()
    {
        parent::__construct('ai:backlog');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'stats | clean')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Commit proposed cleanup actions (default: dry-run)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a single JSON envelope instead of NDJSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');
        $json = (bool) $input->getOption('json');

        return match ($action) {
            self::ACTION_STATS => $this->stats($output, $json),
            self::ACTION_CLEAN => $this->clean($input, $output, $json),
            default            => $this->error($output, "unknown action: '{$action}' (expected stats | clean)", $json),
        };
    }

    private function stats(OutputInterface $output, bool $jsonMode): int
    {
        $epics = $this->epicStore->list();
        $tasks = $this->taskStore->list();

        $epicStats = $this->bucketize($epics, fn(Epic $e) => [
            'status'  => $e->status->value,
            'quality' => $this->hygiene->assessEpic($e)->quality->value,
        ]);

        $taskStats = $this->bucketize($tasks, fn(Task $t) => [
            'status'  => $t->status->value,
            'quality' => $this->hygiene->assessTask($t)->quality->value,
        ]);

        $envelope = [
            'artifact' => 'semitexa.ai-work.backlog-stats/v1',
            'epics'    => $epicStats,
            'tasks'    => $taskStats,
        ];

        if ($jsonMode) {
            $output->writeln(json_encode($envelope, JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $output->writeln(json_encode(['kind' => 'summary'] + $envelope, JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function clean(InputInterface $input, OutputInterface $output, bool $jsonMode): int
    {
        $apply = (bool) $input->getOption('apply');

        $epicActions = [];
        foreach ($this->epicStore->list() as $epic) {
            $assessment = $this->hygiene->assessEpic($epic);
            if ($assessment->suggestedStatus === null) {
                continue;
            }
            $action = [
                'kind'             => 'epic_action',
                'id'               => $epic->id,
                'title'            => $epic->title,
                'current_status'   => $epic->status->value,
                'proposed_status'  => $assessment->suggestedStatus,
                'quality'          => $assessment->quality->value,
                'issues'           => $assessment->issues,
                'suggested_action' => $assessment->suggestedAction,
                'applied'          => false,
            ];
            if ($apply) {
                $newStatus = EpicStatus::parse($assessment->suggestedStatus);
                $updated = $epic->with(status: $newStatus, updatedAt: date('c'));
                $this->epicStore->save($updated);
                $action['applied'] = true;
            }
            $epicActions[] = $action;
        }

        $taskActions = [];
        foreach ($this->taskStore->list() as $task) {
            $assessment = $this->hygiene->assessTask($task);
            if ($assessment->suggestedStatus === null) {
                continue;
            }
            $action = [
                'kind'             => 'task_action',
                'id'               => $task->id,
                'epic_id'          => $task->epicId,
                'title'            => $task->title,
                'current_status'   => $task->status->value,
                'proposed_status'  => $assessment->suggestedStatus,
                'quality'          => $assessment->quality->value,
                'issues'           => $assessment->issues,
                'suggested_action' => $assessment->suggestedAction,
                'applied'          => false,
            ];
            if ($apply) {
                $newStatus = TaskStatus::parse($assessment->suggestedStatus);
                $updated = $task->with(status: $newStatus, updatedAt: date('c'));
                $this->taskStore->save($updated);
                $action['applied'] = true;
            }
            $taskActions[] = $action;
        }

        $envelope = [
            'artifact'     => 'semitexa.ai-work.backlog-clean/v1',
            'mode'         => $apply ? 'apply' : 'dry_run',
            'epic_actions' => $epicActions,
            'task_actions' => $taskActions,
            'epic_count'   => count($epicActions),
            'task_count'   => count($taskActions),
        ];

        if ($jsonMode) {
            $output->writeln(json_encode($envelope, JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $output->writeln(json_encode([
            'kind'       => 'summary',
            'mode'       => $envelope['mode'],
            'epic_count' => $envelope['epic_count'],
            'task_count' => $envelope['task_count'],
        ], JSON_UNESCAPED_SLASHES));
        foreach ($epicActions as $row) {
            $output->writeln(json_encode($row, JSON_UNESCAPED_SLASHES));
        }
        foreach ($taskActions as $row) {
            $output->writeln(json_encode($row, JSON_UNESCAPED_SLASHES));
        }
        return self::SUCCESS;
    }

    /**
     * @template T
     * @param list<T>                           $items
     * @param callable(T): array<string,string> $fn
     * @return array{by_quality: array<string,int>, by_status: array<string,int>, by_status_quality: array<string,int>, total: int, in_active_scope: int}
     */
    private function bucketize(array $items, callable $fn): array
    {
        $byQuality = [];
        $byStatus = [];
        $byStatusQuality = [];
        foreach (BacklogQuality::cases() as $q) {
            $byQuality[$q->value] = 0;
        }

        foreach ($items as $item) {
            $row = $fn($item);
            $status = $row['status'];
            $quality = $row['quality'];
            $byQuality[$quality] = ($byQuality[$quality] ?? 0) + 1;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $key = $status . '.' . $quality;
            $byStatusQuality[$key] = ($byStatusQuality[$key] ?? 0) + 1;
        }

        $activeScope = $this->countInActiveScope($items);

        return [
            'by_quality'        => $byQuality,
            'by_status'         => $byStatus,
            'by_status_quality' => $byStatusQuality,
            'total'             => count($items),
            'in_active_scope'   => $activeScope,
        ];
    }

    /**
     * @param list<Epic|Task> $items
     */
    private function countInActiveScope(array $items): int
    {
        $n = 0;
        foreach ($items as $item) {
            if ($item instanceof Epic) {
                $a = $this->hygiene->assessEpic($item);
                if ($this->hygiene->epicInScope($item, $a, BacklogScope::ACTIVE)) {
                    $n++;
                }
            } elseif ($item instanceof Task) {
                $a = $this->hygiene->assessTask($item);
                if ($this->hygiene->taskInScope($item, $a, BacklogScope::ACTIVE)) {
                    $n++;
                }
            }
        }
        return $n;
    }

    private function error(OutputInterface $output, string $message, bool $jsonMode): int
    {
        if ($jsonMode) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa.ai-work.backlog/v1',
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

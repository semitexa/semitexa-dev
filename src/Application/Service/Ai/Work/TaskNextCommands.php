<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

/** What to run next on a task, for the next_command of an ai:work envelope. */
final class TaskNextCommands
{
    /**
     * @return list<array{cmd: string, args: list<string>, why: string}>
     */
    public static function for(Task $task): array
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
}

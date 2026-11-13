<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * The files a task's own commits touched, for proving a task after it was committed
 * (tk-committed-task-cannot-be-proven).
 *
 * `ai:verify --trace=<task>` on a clean tree verified nothing ("incomplete"), so a task closed
 * on a red run, or before a project-wide red was fixed, could never be proven: in a consumer
 * project on 2026-11-13 the agent re-ran it for three committed tasks and got three incompletes.
 * The commits that carry "Task: <id>" say which files the task was; their current state is what
 * a run checks, and what the proof's fingerprint then holds.
 */
final readonly class TaskCommitFiles
{
    public function __construct(private string $projectRoot) {}

    /**
     * @return list<array{path: string, status: string}> workspace-relative, as --files resolves them
     */
    public function entries(string $taskId): array
    {
        $root = rtrim($this->projectRoot, '/');
        $out = [];
        foreach ((new DirtyWorkspaceScanner($this->projectRoot))->repositories() as $prefix => $repository) {
            foreach (self::touched($repository, $taskId) as $path) {
                $relative = $prefix === '' ? $path : $prefix . '/' . $path;
                // From the task's history, not typed by hand: a path no longer on disk is a deletion, not a typo.
                $there = file_exists($root . '/' . $relative) || is_link($root . '/' . $relative);
                $out[$relative] = ['path' => $relative, 'status' => $there ? ChangedFile::STATUS_MODIFIED : ChangedFile::STATUS_DELETED];
            }
        }
        ksort($out);

        return array_values($out);
    }

    /** @return list<string> repository-relative paths the commits naming the task touched */
    private static function touched(string $repository, string $taskId): array
    {
        $process = proc_open(
            ['git', '-C', $repository, '-c', 'safe.directory=' . $repository, 'log', '--format=', '--name-only', '-E', '--grep=^Task: ' . preg_quote($taskId, '/') . '$'],
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return [];
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', explode("\n", $out)), static fn (string $p): bool => $p !== '')));
    }
}

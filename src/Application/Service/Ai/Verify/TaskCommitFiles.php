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

    /**
     * Repository-relative paths the commits naming the task touched: NUL-framed, so a name git would
     * quote (`src/é.php`) is read as it is; a merge carrying the line counts against its first
     * parent; a rename names both its paths, the old one a deletion (review, dev#252).
     *
     * @return list<string>
     * @throws \RuntimeException when the history of a repository that has one cannot be read
     */
    private static function touched(string $repository, string $taskId): array
    {
        // An empty history answers with nothing; one that cannot be read (a broken ref too), with an error.
        foreach ([['rev-list', '--all', '-n', '1'], ['log', '--format=', '--name-only', '-z', '--no-renames', '--diff-merges=first-parent', '-E', '--grep=^Task: ' . preg_quote($taskId, '/') . '$']] as $args) {
            [$code, $out, $err] = self::git($repository, $args);
            if ($code !== 0) {
                throw new \RuntimeException("ai:verify --trace={$taskId}: the history of {$repository} could not be read (git {$args[0]} exited {$code}: " . trim($err) . ')');
            }
            if ($args[0] === 'rev-list' && trim($out) === '') {
                return []; // no commit yet: no task commit either
            }
        }

        return array_values(array_unique(array_filter(explode("\0", $out), static fn (string $p): bool => $p !== '' && $p !== "\n")));
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr (from a file: two pipes read in turn can deadlock)
     */
    private static function git(string $repository, array $args): array
    {
        $errFile = (string) tempnam(sys_get_temp_dir(), 'semitexa-git-');
        $process = proc_open(
            ['git', '-C', $repository, '-c', 'safe.directory=' . $repository, ...$args],
            [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            @unlink($errFile);

            return [-1, '', 'git could not be started'];
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);
        $err = (string) @file_get_contents($errFile);
        @unlink($errFile);

        return [$code, $out, $err];
    }
}

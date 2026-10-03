<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity;

/**
 * The committed and the working-tree content of a workspace path.
 *
 * The workspace root is not a repository; every package is its own. So the
 * committed side is asked of the repository that owns the path — the nearest
 * directory above it with a `.git` — at its HEAD, which is what an agent's
 * uncommitted change is measured against.
 */
final class WorkspaceRevisions
{
    public function __construct(private readonly string $projectRoot) {}

    /**
     * The content at HEAD, or null when the path is not in HEAD — a new file.
     *
     * Null means exactly that and nothing else. Every other way of not getting
     * the content (no repository owns the path, the tree cannot be listed, the
     * blob cannot be read) throws: read as "new", it would let a weakened test
     * through as a file with nothing to compare (review of dev#127).
     *
     * @throws \RuntimeException
     */
    public function committed(string $path): ?string
    {
        $repository = $this->repositoryOf($path);
        if ($repository === null) {
            throw new \RuntimeException("no git repository owns {$path}");
        }
        $relative = ltrim(substr($this->projectRoot . '/' . $path, strlen($repository)), '/');

        // A repository git cannot read (dubious ownership, corruption) fails
        // every command below the same way an unborn branch does: tell them apart.
        $readable = $this->git($repository, ['rev-parse', '--git-dir']);
        if ($readable['exit'] !== 0) {
            throw new \RuntimeException("cannot read the repository {$repository}: " . $readable['output']);
        }
        if ($this->git($repository, ['rev-parse', '--verify', '-q', 'HEAD^{commit}'])['exit'] !== 0) {
            // Unborn (a fresh `git init`): HEAD names a branch that has no
            // commit yet, so everything in it is new. Anything else that fails
            // here is a broken HEAD, which must not read as "new" (review of dev#127).
            $branch = $this->git($repository, ['symbolic-ref', '-q', 'HEAD']);
            if ($branch['exit'] === 0 && $this->git($repository, ['show-ref', '--verify', '--quiet', trim($branch['output'])])['exit'] !== 0) {
                return null;
            }
            throw new \RuntimeException("HEAD of {$repository} does not resolve to a commit");
        }
        $listing = $this->git($repository, ['ls-tree', '--name-only', 'HEAD', '--', $relative]);
        if ($listing['exit'] !== 0) {
            throw new \RuntimeException("cannot list HEAD in {$repository} for {$relative}: " . $listing['output']);
        }
        if (trim($listing['output']) === '') {
            return null;
        }
        $blob = $this->git($repository, ['show', 'HEAD:' . $relative], content: true);
        if ($blob['exit'] !== 0) {
            throw new \RuntimeException("cannot read HEAD:{$relative} in {$repository}");
        }

        return $blob['output'];
    }

    /**
     * proc_open, not exec(): exec() strips trailing whitespace from every line
     * it captures, and a committed line that differs from the working tree by
     * its trailing spaces then reads as added (review of dev#127).
     *
     * @param list<string> $arguments
     * @return array{exit: int, output: string}
     */
    private function git(string $repository, array $arguments, bool $content = false): array
    {
        // `-c safe.directory=` for this repository only: the command runs as a
        // different user inside the container, and git refuses a "dubious
        // ownership" repository outright (same as DirtyWorkspaceScanner).
        $command = ['git', '-C', $repository, '-c', 'safe.directory=' . $repository, ...$arguments];
        // A blob read sends stderr nowhere: draining two pipes one after the
        // other can block on the one not being read.
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => $content ? ['file', '/dev/null', 'w'] : ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return ['exit' => 1, 'output' => 'git could not be started'];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = '';
        if (isset($pipes[2])) {
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }
        $exit = proc_close($process);

        // Content must be the blob alone; a diagnostic is wanted otherwise.
        return ['exit' => $exit, 'output' => $content ? $stdout : rtrim($stdout . $stderr)];
    }

    public function current(string $path): ?string
    {
        $file = $this->projectRoot . '/' . $path;
        if (!is_file($file)) {
            return null;
        }
        $content = file_get_contents($file);

        return $content === false ? null : $content;
    }

    private function repositoryOf(string $path): ?string
    {
        $root = rtrim($this->projectRoot, '/');
        $dir = dirname($root . '/' . ltrim($path, '/'));
        while (strlen($dir) > strlen($root)) {
            if (is_dir($dir . '/.git') || is_file($dir . '/.git')) {
                return $dir;
            }
            $dir = dirname($dir);
        }

        return is_dir($root . '/.git') || is_file($root . '/.git') ? $root : null;
    }
}

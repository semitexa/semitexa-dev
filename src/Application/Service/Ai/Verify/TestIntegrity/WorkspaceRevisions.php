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
        // A repository without a commit has no HEAD: everything in it is new.
        if ($this->git($repository, ['rev-parse', '--verify', '-q', 'HEAD'])['exit'] !== 0) {
            return null;
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
     * @param list<string> $arguments
     * @return array{exit: int, output: string}
     */
    private function git(string $repository, array $arguments, bool $content = false): array
    {
        $output = [];
        $exit = 1;
        // `-c safe.directory=` for this repository only: the command runs as a
        // different user inside the container, and git refuses a "dubious
        // ownership" repository outright (same as DirtyWorkspaceScanner).
        exec(sprintf(
            // Content must be the blob alone; a diagnostic is wanted otherwise.
            'git -C %s -c safe.directory=%s %s %s',
            escapeshellarg($repository),
            escapeshellarg($repository),
            implode(' ', array_map('escapeshellarg', $arguments)),
            $content ? '2>/dev/null' : '2>&1',
        ), $output, $exit);

        return ['exit' => $exit, 'output' => implode("\n", $output)];
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

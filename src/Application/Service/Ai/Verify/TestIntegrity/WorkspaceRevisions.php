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

    public function committed(string $path): ?string
    {
        $repository = $this->repositoryOf($path);
        if ($repository === null) {
            return null;
        }
        $relative = ltrim(substr($this->projectRoot . '/' . $path, strlen($repository)), '/');
        $output = [];
        $exit = 1;
        // `-c safe.directory=` for this repository only: the command runs as a
        // different user inside the container, and git refuses a "dubious
        // ownership" repository outright (same as DirtyWorkspaceScanner).
        exec(sprintf(
            'git -C %s -c safe.directory=%s show %s 2>/dev/null',
            escapeshellarg($repository),
            escapeshellarg($repository),
            escapeshellarg('HEAD:' . $relative),
        ), $output, $exit);

        return $exit === 0 ? implode("\n", $output) : null;
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

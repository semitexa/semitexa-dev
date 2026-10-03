<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Evidence;

use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;

/**
 * Runtime output git is about to take as source. `var/` is not ignored as a
 * whole — the scaffold lists the subdirectories it knows — so a tool writing
 * a NEW folder there (var/e2e-proof, var/os-dev-shots, var/trace in an older
 * project) puts screenshots and request traces one `git add -A` away from a
 * public commit. Measured 2026-10-02: five such folders, none ignored.
 *
 * Reads git's own answer — a changed path git status shows is, by definition,
 * not ignored — so the scaffold .gitignore is the only list to keep.
 */
final class VarArtifactScan
{
    private const VAR = '#^(?:(?:packages|src/modules)/[^/]+/)?var/#';

    /** What the scaffold keeps under version control on purpose. */
    private const KEPT = [
        '#^(?:(?:packages|src/modules)/[^/]+/)?var/ai-work/#',
        // The work log beside the backlog: tasks name their trace by id.
        '#^(?:(?:packages|src/modules)/[^/]+/)?var/ai-traces/#',
        '#^(?:(?:packages|src/modules)/[^/]+/)?var/migrations/history/#',
        '#^(?:(?:packages|src/modules)/[^/]+/)?var/docs/README\.md$#',
        '#(?:^|/)\.gitkeep$#',
    ];

    /**
     * @param list<array{path: string, status: string}> $changed workspace-relative, as DirtyWorkspaceScanner reports
     * @return list<string> paths git would commit from a var/ directory
     */
    public static function offending(array $changed): array
    {
        $found = [];
        foreach ($changed as $row) {
            if ($row['status'] === ChangedFile::STATUS_DELETED || preg_match(self::VAR, $row['path']) !== 1) {
                continue;
            }
            foreach (self::KEPT as $kept) {
                if (preg_match($kept, $row['path']) === 1) {
                    continue 2;
                }
            }
            $found[] = $row['path'];
        }
        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /**
     * Named paths git would commit, or has: what `ai:verify --files` or
     * `--git-ref` selected. A committed screenshot is not in a clean
     * checkout's `git status`, so reading only the dirty tree let the lint
     * pass over the very file it was run for.
     *
     * Asks the repository that owns each path. `check-ignore` treats a tracked
     * file as not ignored, so committed and committable both count; a path no
     * repository owns cannot be committed at all.
     *
     * @param list<string> $paths workspace-relative
     * @return list<array{path: string, status: string}> rows for {@see offending()}
     * @throws \RuntimeException when git cannot answer: a check that cannot run must not pass
     */
    public static function committable(string $projectRoot, array $paths): array
    {
        $root = rtrim($projectRoot, '/');
        $rows = [];
        foreach ($paths as $path) {
            $repository = self::concerns($path) ? self::repositoryOf($root, $path) : null;
            if ($repository === null) {
                continue;
            }
            $cmd = sprintf(
                'git -C %s -c safe.directory=%s check-ignore -q -- %s 2>&1',
                escapeshellarg($repository),
                escapeshellarg($repository),
                escapeshellarg(ltrim(substr($root . '/' . $path, strlen($repository)), '/')),
            );
            $output = [];
            exec($cmd, $output, $code);
            if ($code === 1) {
                $rows[] = ['path' => $path, 'status' => ChangedFile::STATUS_MODIFIED];
            } elseif ($code !== 0) {
                throw new \RuntimeException('git check-ignore failed for ' . $path . ': ' . implode(' / ', $output));
            }
        }

        return $rows;
    }

    /** The nearest directory above the path, up to the project root, that is a git work tree. */
    private static function repositoryOf(string $root, string $path): ?string
    {
        $dir = dirname($root . '/' . $path);
        while (strlen($dir) >= strlen($root)) {
            // `.git` is a file in a linked worktree.
            if (file_exists($dir . '/.git')) {
                return $dir;
            }
            $dir = dirname($dir);
        }

        return null;
    }

    /** Whether a changed path is one this scan reads: what decides that ai:verify runs it. */
    public static function concerns(string $path): bool
    {
        return preg_match(self::VAR, $path) === 1;
    }
}

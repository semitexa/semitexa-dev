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
        sort($found);

        return $found;
    }

    /** Whether a changed path is one this scan reads: what decides that ai:verify runs it. */
    public static function concerns(string $path): bool
    {
        return preg_match(self::VAR, $path) === 1;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * Where a semitexa/docs page is in this project: the workspace's package
 * checkout, or the installed package.
 *
 * A hint has to name a file that is there. ai:verify sent an agent to
 * `packages/semitexa-docs/docs/AI_BEST_PRACTICES.md` in a consumer project,
 * which has no packages/ directory: the page is in vendor/semitexa/docs/docs
 * (fresh-project audit, 20.11, tk-verify-doc-ref-points-at-the-workspace-path).
 */
final class DocsPath
{
    /** Searched in order: the workspace checkout first, then the installed package. */
    private const ROOTS = ['packages/semitexa-docs/docs', 'vendor/semitexa/docs/docs'];

    /** The project-relative path of the docs page `$page`, or null when no copy of it is in this project. */
    public static function of(string $projectRoot, string $page): ?string
    {
        foreach (self::ROOTS as $root) {
            $path = $root . '/' . $page;
            if (is_file(rtrim($projectRoot, '/') . '/' . $path)) {
                return $path;
            }
        }

        return null;
    }
}

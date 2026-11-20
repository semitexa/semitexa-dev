<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * The check that reads a changed GitHub workflow: actionlint, one run per file.
 *
 * A workflow is YAML, so the planner selected nothing for it, no target named
 * it in triggeredBy, and {@see CoverageGap} reported it unread: a commit that
 * changed only a workflow verified `incomplete` and the push gate, which
 * vouches with `pass` receipts alone, could never let it through (2026-11-20,
 * semitexa-installer's docker-publish.yml gaining a build on release tags).
 *
 * Planned for the workflows of a workspace repository, `packages/<name>` or the
 * project root, where GitHub reads them: a `.github/workflows` deeper in a tree
 * is a fixture or a template, not a workflow anything runs. At every scope: it
 * is this file's syntax check, as `php -l` is a PHP file's. A deleted workflow
 * has nothing left to read.
 */
final class ActionlintTargets
{
    private const WORKFLOW = '#^(?:packages/[^/]+/)?\.github/workflows/[^/]+\.ya?ml$#';

    public static function isWorkflow(string $path): bool
    {
        return preg_match(self::WORKFLOW, $path) === 1;
    }

    /**
     * @param list<ChangedFile> $changedFiles
     *
     * @return list<VerificationTarget>
     */
    public static function of(array $changedFiles): array
    {
        $targets = [];
        foreach ($changedFiles as $file) {
            if ($file->status === ChangedFile::STATUS_DELETED || !self::isWorkflow($file->path) || isset($targets[$file->path])) {
                continue;
            }
            $targets[$file->path] = new VerificationTarget(
                type: VerificationTarget::TYPE_ACTIONLINT,
                id: "actionlint:{$file->path}",
                reason: 'a GitHub workflow changed: actionlint ' . ActionlintTool::VERSION . ' reads it (syntax, expressions, action inputs), since a broken workflow shows only when GitHub runs it',
                triggeredBy: [$file->path],
                filePath: $file->path,
            );
        }

        return array_values($targets);
    }
}

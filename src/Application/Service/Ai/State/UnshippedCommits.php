<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

/**
 * Framework packages whose branch carries commits its upstream does not
 * (tk-framework-commit-nobody-ships).
 *
 * An agent working in a project fixed core and docs in the framework
 * workspace beside it, committed on develop, and stopped: the push rule it
 * found was the project's. The commits sat for 25 hours until an operator
 * asked where they came from. ai:orient now names them on every cold start,
 * so the next session sees work that was never shipped.
 *
 * Only repositories under packages/ (the workspace layout) and only tracked
 * branches: a project without packages/ has nothing to list.
 */
final class UnshippedCommits
{
    /**
     * @return list<array{repo: string, branch: string, ahead: int, last: string}>
     */
    public static function in(string $projectRoot): array
    {
        $found = [];
        $dirs = glob(rtrim($projectRoot, '/') . '/packages/*/.git') ?: [];
        sort($dirs);
        foreach ($dirs as $git) {
            $repo = dirname($git);
            // Inherited GIT_DIR and friends would aim every query at one repository
            // (a hook exports them); safe.directory for this one only: the app
            // container runs as another user than the one owning the checkout
            // (review, dev#227; as DirtyWorkspaceScanner does).
            $git = 'env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE -u GIT_COMMON_DIR -u GIT_OBJECT_DIRECTORY -u GIT_ALTERNATE_OBJECT_DIRECTORIES -u GIT_PREFIX git -C '
                . escapeshellarg($repo) . ' -c safe.directory=' . escapeshellarg($repo) . ' ';
            $ahead = trim((string) shell_exec($git . 'rev-list --count @{u}..HEAD 2>/dev/null'));
            if ($ahead === '' || !ctype_digit($ahead) || (int) $ahead === 0) {
                continue;
            }
            $found[] = [
                'repo'   => 'packages/' . basename($repo),
                'branch' => trim((string) shell_exec($git . 'branch --show-current 2>/dev/null')),
                'ahead'  => (int) $ahead,
                'last'   => trim((string) shell_exec($git . 'log -1 --pretty=%s 2>/dev/null')),
            ];
        }

        return $found;
    }
}

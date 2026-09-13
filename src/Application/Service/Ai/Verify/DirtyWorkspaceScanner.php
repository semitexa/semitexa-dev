<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * Every uncommitted change this workspace can actually see, and every root it
 * could not ask.
 *
 * THE WORKSPACE IS NOT ONE REPOSITORY. Each `packages/semitexa-*` is its own
 * git repository, the project root is one only in a consumer install, and
 * `src/` is not versioned in the authoring workspace at all. A single
 * `git status` at the root — the obvious implementation — answers for almost
 * nothing, and there it fails outright.
 *
 * So this asks each repository in turn and, just as importantly, REPORTS what
 * it could not ask. A scan that quietly skips half the tree and returns nothing
 * reads as "half the tree is clean", which is the false green `ai:verify`
 * exists to prevent.
 *
 * Untracked files count as added: a file that does not exist in git yet is
 * exactly the one a generator just wrote and nobody has verified.
 */
final readonly class DirtyWorkspaceScanner
{
    public function __construct(private string $projectRoot)
    {
    }

    /**
     * @return list<array{path: string, status: string}>
     */
    public function changedFiles(): array
    {
        $out = [];

        foreach ($this->repositories() as $prefix => $absolute) {
            foreach ($this->statusOf($absolute) as $entry) {
                $out[] = [
                    'path' => $prefix === '' ? $entry['path'] : $prefix . '/' . $entry['path'],
                    'status' => $entry['status'],
                ];
            }
        }

        return $out;
    }

    /**
     * The reach of the answer, to be carried beside the answer.
     *
     * @return array{scanned: list<string>, unscannable: list<string>}
     */
    public function report(): array
    {
        $root = $this->root();
        $scanned = array_keys($this->repositories());
        $unscannable = [];

        if (!is_dir($root . '/.git')) {
            // Named explicitly: in the authoring workspace the root is not a
            // repository, so nothing under src/ can report a change and a
            // reader must not take silence for cleanliness.
            $unscannable[] = '(project root — not a git repository)';
        }

        foreach ($this->packageDirectories() as $rel => $abs) {
            if (!is_dir($abs . '/.git')) {
                $unscannable[] = $rel;
            }
        }

        sort($scanned);
        sort($unscannable);

        return ['scanned' => $scanned, 'unscannable' => $unscannable];
    }

    /**
     * Repository roots to ask, as repo-relative prefix => absolute path. The
     * project root answers under the empty prefix.
     *
     * @return array<string, string>
     */
    private function repositories(): array
    {
        $root = $this->root();
        $roots = [];

        if (is_dir($root . '/.git')) {
            $roots[''] = $root;
        }

        foreach ($this->packageDirectories() as $rel => $abs) {
            if (is_dir($abs . '/.git')) {
                $roots[$rel] = $abs;
            }
        }

        return $roots;
    }

    /** @return array<string, string> repo-relative => absolute */
    private function packageDirectories(): array
    {
        $root = $this->root();
        $out = [];

        foreach (glob($root . '/packages/semitexa-*', GLOB_ONLYDIR) ?: [] as $abs) {
            $out[ltrim(substr($abs, strlen($root)), '/')] = $abs;
        }

        return $out;
    }

    /**
     * @return list<array{path: string, status: string}>
     * @throws \RuntimeException when git refuses, rather than reporting a clean tree
     */
    private function statusOf(string $repository): array
    {
        // `-c safe.directory=` for THIS repository only: the command runs inside
        // the app container while the checkout is owned by the host user, and
        // git refuses a "dubious ownership" repository outright. Scoped to the
        // one path rather than `*`, and to a read-only status — this asks what
        // changed, it does not run anything out of the repository.
        $cmd = sprintf(
            'git -C %s -c safe.directory=%s status --porcelain=v1 --untracked-files=all 2>&1',
            escapeshellarg($repository),
            escapeshellarg($repository),
        );

        exec($cmd, $lines, $code);

        if ($code !== 0) {
            throw new \RuntimeException('git status failed in ' . $repository . ': ' . implode(' / ', $lines));
        }

        $out = [];
        foreach ($lines as $line) {
            if (strlen($line) < 4) {
                continue;
            }

            $path = trim(substr($line, 3));

            // A rename reads `R  old -> new`; the new name is what to verify.
            if (str_contains($path, ' -> ')) {
                $path = substr($path, strpos($path, ' -> ') + 4);
            }

            $path = trim($path, '"');
            if ($path === '') {
                continue;
            }

            $out[] = ['path' => $path, 'status' => self::statusFor(substr($line, 0, 2))];
        }

        return $out;
    }

    public static function statusFor(string $porcelainCode): string
    {
        $letters = str_replace(' ', '', $porcelainCode);

        if ($letters === '??') {
            return ChangedFile::STATUS_ADDED;
        }
        if (str_contains($letters, 'D')) {
            return ChangedFile::STATUS_DELETED;
        }
        if (str_contains($letters, 'A')) {
            return ChangedFile::STATUS_ADDED;
        }

        return ChangedFile::STATUS_MODIFIED;
    }

    private function root(): string
    {
        return rtrim($this->projectRoot, '/');
    }
}

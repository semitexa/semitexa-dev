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
     * @return list<array{path: string, status: string, originalPath?: string}>
     */
    public function changedFiles(): array
    {
        $out = [];

        foreach ($this->repositories() as $prefix => $absolute) {
            foreach ($this->statusOf($absolute) as $entry) {
                $row = [
                    'path' => self::join($prefix, $entry['path']),
                    'status' => $entry['status'],
                ];
                if (isset($entry['originalPath'])) {
                    $row['originalPath'] = self::join($prefix, $entry['originalPath']);
                }
                $out[] = $row;
            }
        }

        return $out;
    }

    /** Repository-relative path to workspace-relative path. */
    private static function join(string $prefix, string $path): string
    {
        return $prefix === '' ? $path : $prefix . '/' . $path;
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

        if (!self::isRepository($root)) {
            // Named explicitly: in the authoring workspace the root is not a
            // repository, so nothing under src/ can report a change and a
            // reader must not take silence for cleanliness.
            $unscannable[] = '(project root — not a git repository)';
        }

        foreach ($this->packageDirectories() as $rel => $abs) {
            if (!self::isRepository($abs)) {
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

        if (self::isRepository($root)) {
            $roots[''] = $root;
        }

        foreach ($this->packageDirectories() as $rel => $abs) {
            if (self::isRepository($abs)) {
                $roots[$rel] = $abs;
            }
        }

        return $roots;
    }

    /**
     * Is this path a git repository?
     *
     * `.git` is a DIRECTORY in an ordinary clone and a FILE in a linked
     * worktree — one line pointing at the real gitdir. Testing only for the
     * directory skipped every worktree checkout silently: `changedFiles()`
     * came back empty and `--dirty` reported a clean tree, which is the exact
     * false green the scan report exists to prevent. Raised in review of
     * dev#84.
     */
    private static function isRepository(string $path): bool
    {
        return is_dir($path . '/.git') || is_file($path . '/.git');
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
     * @return list<array{path: string, status: string, originalPath?: string}>
     * @throws \RuntimeException when git refuses, rather than reporting a clean tree
     */
    private function statusOf(string $repository): array
    {
        // `-c safe.directory=` for THIS repository only: the command runs inside
        // the app container while the checkout is owned by the host user, and
        // git refuses a "dubious ownership" repository outright. Scoped to the
        // one path rather than `*`, and to a read-only status — this asks what
        // changed, it does not run anything out of the repository.
        // `-z` rather than the line format, for two reasons that both ended in
        // a wrong path. Without it git QUOTES any name carrying a space, a
        // quote, a non-ASCII byte or a backslash -- `"src/a b.php"`, and
        // `\303\251` for an accented letter -- so stripping the outer quotes
        // left an escaped string that matched no file on disk. And a rename
        // arrives as `old -> new` on one line, which is indistinguishable from
        // a file genuinely named with that arrow. In `-z` each path is its own
        // NUL-terminated record, verbatim, and a rename's ORIGINAL path is the
        // record immediately after it. Raised in review of dev#84.
        $cmd = sprintf(
            'git -C %s -c safe.directory=%s status --porcelain=v1 -z --untracked-files=all 2>&1',
            escapeshellarg($repository),
            escapeshellarg($repository),
        );

        // Not exec(): it splits output on newlines and trims each one, which
        // destroys both the NUL framing and any name ending in a space. One
        // pipe, stderr folded into it, so there is no second buffer to fill
        // while this one is being read.
        $raw = '';
        $code = 1;
        $process = proc_open($cmd, [1 => ['pipe', 'w']], $pipes);
        if (is_resource($process)) {
            $raw = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $code = proc_close($process);
        }

        if ($code !== 0) {
            throw new \RuntimeException(
                'git status failed in ' . $repository . ': ' . trim(str_replace("\0", ' / ', $raw)),
            );
        }

        return self::parsePorcelainZ($raw);
    }

    /**
     * @return list<array{path: string, status: string, originalPath?: string}>
     */
    public static function parsePorcelainZ(string $raw): array
    {
        // The trailing NUL leaves an empty tail; every other record is real.
        $records = explode("\0", $raw);
        if (end($records) === '') {
            array_pop($records);
        }

        $out = [];
        for ($i = 0, $n = count($records); $i < $n; $i++) {
            $record = $records[$i];
            if (strlen($record) < 4) {
                continue;
            }

            $code = substr($record, 0, 2);
            $path = substr($record, 3);
            $letters = str_replace(' ', '', $code);

            // A rename or a copy is TWO records: this one, then the path it
            // came from. The second must be consumed either way, or it is read
            // as a status line of its own.
            $original = '';
            if ($letters !== '??' && (str_contains($letters, 'R') || str_contains($letters, 'C'))) {
                $original = $records[++$i] ?? '';
            }

            if ($path === '') {
                continue;
            }

            $entry = ['path' => $path, 'status' => self::statusFor($code)];
            // Only a RENAME carries one: a copy leaves the original where it
            // was, so the old FQCN is not broken and nothing should query it.
            if ($entry['status'] === ChangedFile::STATUS_RENAMED && $original !== '') {
                $entry['originalPath'] = $original;
            }
            $out[] = $entry;
        }

        return $out;
    }

    public static function statusFor(string $porcelainCode): string
    {
        $letters = str_replace(' ', '', $porcelainCode);

        if ($letters === '??') {
            return ChangedFile::STATUS_ADDED;
        }
        // Deleted first: `RD` is a file renamed in the index and then removed
        // from the worktree, and the new path is not there to verify.
        if (str_contains($letters, 'D')) {
            return ChangedFile::STATUS_DELETED;
        }
        if (str_contains($letters, 'R')) {
            return ChangedFile::STATUS_RENAMED;
        }
        // A copy's new path has no previous name, so it is simply added.
        if (str_contains($letters, 'A') || str_contains($letters, 'C')) {
            return ChangedFile::STATUS_ADDED;
        }

        return ChangedFile::STATUS_MODIFIED;
    }

    private function root(): string
    {
        return rtrim($this->projectRoot, '/');
    }
}

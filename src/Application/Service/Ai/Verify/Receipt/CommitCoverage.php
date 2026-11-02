<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Receipt;

use Semitexa\Dev\Application\Service\Ai\Verify\DirtyWorkspaceScanner;
use Semitexa\Dev\Application\Service\Ai\Verify\UnselectedChanges;

/**
 * Is a commit the change a receipt vouches for?
 *
 * A receipt names the bytes its run checked. A commit made after it can carry
 * more files than that, or other bytes for the same files (an edit after the
 * run, a hunk staged from somewhere else), and the receipt said nothing about
 * either: `--files=A` passed, A and B were committed. This compares the commit
 * with the receipt, file by file: every file the commit adds or changes must be
 * one the run checked, with the bytes the run checked. A deletion has no bytes
 * left for a check to read and is not asked about.
 *
 * Each workspace repository a receipt touches is its own git repository, so the
 * revision is resolved in each (HEAD, a sha, a branch); one it does not name a
 * commit in is reported, never taken as "nothing committed".
 */
final readonly class CommitCoverage
{
    public function __construct(private string $projectRoot) {}

    /**
     * @param array<string, ?string> $tree the receipt's tree: path => sha256 of what the run checked
     * @return array{revision: string, commits: array<string, string>, unresolved: list<string>, unchecked: list<string>, differs: list<string>}
     */
    public function of(array $tree, string $revision): array
    {
        $scanner = new DirtyWorkspaceScanner($this->projectRoot);
        $repositories = $scanner->repositories();
        $touched = [];
        foreach (array_keys($tree) as $path) {
            $repo = UnselectedChanges::repositoryOf((string) $path, array_keys($repositories));
            if ($repo !== null) {
                $touched[$repo] = $repositories[$repo];
            }
        }
        ksort($touched);

        $commits = [];
        $unresolved = [];
        $unchecked = [];
        $differs = [];
        foreach ($touched as $prefix => $absolute) {
            $sha = trim((string) $this->git($absolute, ['rev-parse', '--verify', '--quiet', $revision . '^{commit}']));
            if (preg_match('/\A[0-9a-f]{40,64}\z/', $sha) !== 1) {
                $unresolved[] = $prefix === '' ? '(project root)' : $prefix;
                continue;
            }
            $commits[$prefix === '' ? '(project root)' : $prefix] = $sha;
            $listing = (string) $this->git($absolute, ['show', '--format=', '--name-status', '--no-renames', '-z', $sha]);
            $fields = explode("\0", rtrim($listing, "\0"));
            for ($i = 0; $i + 1 < count($fields); $i += 2) {
                [$status, $relative] = [$fields[$i], $fields[$i + 1]];
                if ($status === 'D') {
                    continue;
                }
                $path = $prefix === '' ? $relative : $prefix . '/' . $relative;
                if (!array_key_exists($path, $tree)) {
                    $unchecked[] = $path;
                } elseif (hash('sha256', (string) $this->git($absolute, ['show', $sha . ':' . $relative])) !== $tree[$path]) {
                    $differs[] = $path;
                }
            }
        }
        sort($unchecked);
        sort($differs);

        return ['revision' => $revision, 'commits' => $commits, 'unresolved' => $unresolved, 'unchecked' => $unchecked, 'differs' => $differs];
    }

    /** @param list<string> $args */
    private function git(string $repository, array $args): ?string
    {
        // Read-only, scoped to this one repository (see DirtyWorkspaceScanner::statusOf).
        $cmd = sprintf('git -C %s -c safe.directory=%s %s 2>/dev/null', escapeshellarg($repository), escapeshellarg($repository), implode(' ', array_map('escapeshellarg', $args)));
        $out = shell_exec($cmd);

        return is_string($out) ? $out : null;
    }
}

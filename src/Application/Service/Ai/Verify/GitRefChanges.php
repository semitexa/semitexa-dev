<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * `git diff --name-status -z <ref>` for every repository the project is made of.
 *
 * A consumer project is one repository at its root. The Semitexa workspace is
 * not: its root is no repository and every package is its own, so a diff run at
 * the root failed outright ("Not a git repository") and `ai:verify --git-ref`
 * could not review a branch there at all. Each repository is diffed against the
 * same ref and its paths are prefixed with where it lives.
 *
 * A repository in which the ref does not resolve fails the whole call: a
 * review that silently left a package out would read as that package being
 * clean.
 */
final class GitRefChanges
{
    public function __construct(private readonly string $projectRoot) {}

    /**
     * The changes against the ref, read NUL-framed (`-z`): without it git
     * quotes and escapes any pathname with a tab, a newline or a non-ASCII
     * byte, and the escaped text names no file on disk (review of dev#130).
     *
     * @return list<array{path: string, status: string, originalPath?: string}> paths relative to the project root
     * @throws \RuntimeException
     */
    public function changes(string $ref): array
    {
        if ($ref === '' || str_starts_with($ref, '-')) {
            throw new \RuntimeException("'{$ref}' is not a git ref");
        }
        $repositories = (new DirtyWorkspaceScanner($this->projectRoot))->repositories();
        // Nothing to diff is not "nothing changed".
        if ($repositories === []) {
            throw new \RuntimeException("git diff against '{$ref}' failed: no git repository in {$this->projectRoot}");
        }
        $changes = [];
        foreach ($repositories as $prefix => $repository) {
            $command = ['git', '-C', $repository, '-c', 'safe.directory=' . $repository, 'diff', '--name-status', '-z', $ref, '--'];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new \RuntimeException("git diff against '{$ref}' could not start in " . ($prefix === '' ? 'the project root' : $prefix));
            }
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                throw new \RuntimeException(sprintf("git diff against '%s' failed in %s: %s", $ref, $prefix === '' ? 'the project root' : $prefix, trim(implode(' / ', array_slice(preg_split('/\R/', $stderr) ?: [], 0, 3)))));
            }
            $at = static fn (string $path): string => $prefix === '' ? $path : $prefix . '/' . $path;
            $fields = explode("\0", rtrim($stdout, "\0"));
            for ($i = 0; $i + 1 < count($fields); $i += 2) {
                $code = $fields[$i];
                $status = match ($code[0] ?? '') {
                    'A', 'C' => ChangedFile::STATUS_ADDED,
                    'D' => ChangedFile::STATUS_DELETED,
                    'R' => ChangedFile::STATUS_RENAMED,
                    default => ChangedFile::STATUS_MODIFIED,
                };
                // A rename or a copy carries two paths: old, then new.
                if ($code !== '' && ($code[0] === 'R' || $code[0] === 'C') && isset($fields[$i + 2])) {
                    $entry = ['path' => $at($fields[$i + 2]), 'status' => $status];
                    if ($code[0] === 'R') {
                        $entry['originalPath'] = $at($fields[$i + 1]);
                    }
                    $changes[] = $entry;
                    $i++;
                    continue;
                }
                $changes[] = ['path' => $at($fields[$i + 1]), 'status' => $status];
            }
        }

        return $changes;
    }
}

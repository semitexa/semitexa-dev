<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * `git diff --name-status <ref>` for every repository the project is made of.
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
     * @return list<string> name-status lines, paths relative to the project root
     * @throws \RuntimeException
     */
    public function nameStatus(string $ref): array
    {
        if ($ref === '' || str_starts_with($ref, '-')) {
            throw new \RuntimeException("'{$ref}' is not a git ref");
        }
        $repositories = (new DirtyWorkspaceScanner($this->projectRoot))->repositories();
        // Nothing to diff is not "nothing changed".
        if ($repositories === []) {
            throw new \RuntimeException("git diff against '{$ref}' failed: no git repository in {$this->projectRoot}");
        }
        $lines = [];
        foreach ($repositories as $prefix => $repository) {
            $output = [];
            $code = 1;
            exec(sprintf(
                'git -C %s -c safe.directory=%s diff --name-status %s -- 2>&1',
                escapeshellarg($repository),
                escapeshellarg($repository),
                escapeshellarg($ref),
            ), $output, $code);
            if ($code !== 0) {
                throw new \RuntimeException(sprintf("git diff against '%s' failed in %s: %s", $ref, $prefix === '' ? 'the project root' : $prefix, implode(' / ', array_slice($output, 0, 3))));
            }
            foreach ($output as $line) {
                $fields = explode("\t", $line);
                if (count($fields) < 2) {
                    continue;
                }
                $status = array_shift($fields);
                $lines[] = $status . "\t" . implode("\t", array_map(static fn (string $p): string => $prefix === '' ? $p : $prefix . '/' . $p, $fields));
            }
        }

        return $lines;
    }
}

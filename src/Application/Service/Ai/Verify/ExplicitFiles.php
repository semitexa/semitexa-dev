<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * What a path named in `ai:verify --files` is: changed, deleted, or a mistake
 * (tk-verify-missing-path-is-not-modified).
 *
 * Every --files path used to be taken as modified. A typo was too: on
 * 2026-11-08 an agent passed `src/.../Payload/GuardLoginPayload.php` for a file
 * that lives under `Payload/Request/`, got status M, "every file was read by at
 * least one check" and a red syntax result ("file no longer exists"), and spent
 * about twelve steps reading receipts before it found its own typo.
 *
 * A path on disk is modified, as before. A path gone from disk that git still
 * knows (at HEAD or in the index) is a deletion, which is what "no longer
 * exists" is for. Anything else is refused up front, naming any file of the
 * same name nearby, so the typo is the first thing the caller reads.
 */
final class ExplicitFiles
{
    /** How many files one suggestion search may look at, so a typo near the root stays cheap. */
    private const SEARCH_LIMIT = 20_000;

    private const SKIP = ['.git' => true, 'vendor' => true, 'node_modules' => true, 'var' => true];

    public function __construct(private readonly string $projectRoot) {}

    /**
     * @return array{path: string, status: string}
     * @throws \RuntimeException for a path that is neither on disk nor known to git
     */
    public function resolve(string $path): array
    {
        $root = rtrim($this->projectRoot, '/');
        $absolute = str_starts_with($path, '/') ? $path : $root . '/' . $path;
        if (file_exists($absolute)) {
            return ['path' => $path, 'status' => ChangedFile::STATUS_MODIFIED];
        }
        if ($this->knownToGit($path)) {
            return ['path' => $path, 'status' => ChangedFile::STATUS_DELETED];
        }

        $message = "--files: {$path} does not exist, and git knows no such file";
        $near = $this->sameName($path);
        if ($near !== []) {
            $message .= '. A file of that name: ' . implode(', ', $near);
        }

        throw new \RuntimeException($message);
    }

    /** Whether the repository holding the path has it at HEAD or in its index: a deletion, not a typo. */
    private function knownToGit(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return false;
        }
        $path = (string) preg_replace('#^(\./)+#', '', $path);
        $repositories = (new DirtyWorkspaceScanner($this->projectRoot))->repositories();
        // The longest prefix wins: packages/x before the root.
        uksort($repositories, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($repositories as $prefix => $repository) {
            if ($prefix !== '' && !str_starts_with($path, $prefix . '/')) {
                continue;
            }
            $inRepo = $prefix === '' ? $path : substr($path, strlen($prefix) + 1);

            return self::git($repository, ['cat-file', '-e', 'HEAD:' . $inRepo])
                || self::git($repository, ['ls-files', '--error-unmatch', '--', $inRepo]);
        }

        return false;
    }

    /** @param list<string> $args */
    private static function git(string $repository, array $args): bool
    {
        $process = proc_open(
            ['git', '-C', $repository, '-c', 'safe.directory=' . $repository, ...$args],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        return is_resource($process) && proc_close($process) === 0;
    }

    /**
     * Files named like the missing one, under the nearest directory that exists.
     *
     * @return list<string> project-relative paths, at most three
     */
    private function sameName(string $path): array
    {
        $root = rtrim($this->projectRoot, '/');
        $name = basename($path);
        $dir = dirname($path);
        while ($dir !== '.' && $dir !== '/' && $dir !== '' && !is_dir($root . '/' . $dir)) {
            $dir = dirname($dir);
        }
        $base = $dir === '.' || $dir === '' ? $root : $root . '/' . ltrim($dir, '/');
        if (!is_dir($base)) {
            return [];
        }

        $found = [];
        $seen = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $f): bool => !($f->isDir() && isset(self::SKIP[$f->getFilename()])),
        ));
        try {
            foreach ($files as $file) {
                if (++$seen > self::SEARCH_LIMIT || count($found) === 3) {
                    break;
                }
                if ($file->getFilename() === $name) {
                    $found[] = ltrim(substr($file->getPathname(), strlen($root)), '/');
                }
            }
        } catch (\UnexpectedValueException) {
            // An unreadable directory ends the search; the refusal stands without a hint.
        }
        sort($found);

        return $found;
    }
}

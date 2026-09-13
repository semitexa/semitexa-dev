<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Writer;

use Semitexa\Dev\Application\Service\Ai\Verify\ProcessRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\ShellProcessRunner;
use Semitexa\Dev\Application\Service\Generation\Contract\FileWriterInterface;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationResult;

/**
 * Writes generated files into a project.
 *
 * Three properties this has to hold, because it is the point where a generator
 * stops being a plan and starts changing someone's repository:
 *
 *  - **Decide before touching disk.** Every path is validated and every
 *    conflict found up front. A batch containing one unusable path writes
 *    nothing at all — a generator asking for `../` is a defect in the
 *    generator, and half-applying it makes that defect somebody's cleanup job.
 *  - **Publish whole files or none.** Content goes to a temporary file beside
 *    the target and is moved into place, so a reader never sees a half-written
 *    class, and a failure part-way leaves no wreckage.
 *  - **Say what actually happened.** `mkdir()` and `file_put_contents()` used
 *    to have their return values dropped, so a read-only mount or a full disk
 *    still reported the file as `created`. The filesystem's answer now reaches
 *    the caller, and `created` lists what is on disk rather than what was
 *    intended.
 */
final class SafeFileWriter implements FileWriterInterface
{
    /** Generated files are source, not executables. */
    private const FILE_MODE = 0644;

    private readonly ProcessRunner $processRunner;

    public function __construct(
        private readonly string $basePath,
        private readonly string $commandName = 'unknown',
        ?ProcessRunner $processRunner = null,
    ) {
        // Bounded by construction. The syntax check used to hand-roll proc_open
        // with two pipes drained in sequence — the exact shape that deadlocks
        // when the child fills the second pipe before closing the first, and
        // which once hung ai:verify for thirteen minutes.
        $this->processRunner = $processRunner ?? new ShellProcessRunner(timeoutSeconds: 30.0, maxOutputBytes: 262144);
    }

    public function write(array $files, bool $force = false): GenerationResult
    {
        $plans = [];
        $conflicts = [];
        $errors = [];

        // --- preflight: nothing below this block has touched the disk yet ---
        foreach ($files as $file) {
            $refusal = $this->refusePath($file->path);
            if ($refusal !== null) {
                $errors[] = $refusal;
                continue;
            }

            if (isset($plans[$file->path])) {
                // Silently keeping the last one published one file and reported
                // success for two. A generator that plans the same path twice
                // has a defect, and half-applying it hides which half won.
                $errors[] = [
                    'path' => $file->path,
                    'reason' => 'duplicate',
                    'detail' => 'the batch plans this path more than once; only one of them could ever be written',
                ];
                continue;
            }

            $plans[$file->path] = ['full' => $this->basePath . '/' . $file->path, 'content' => $file->content];
        }

        if ($errors !== []) {
            return $this->result('rejected', [], $conflicts, $errors);
        }

        foreach ($plans as $relative => $plan) {
            if (file_exists($plan['full']) && !$force) {
                $conflicts[] = $relative;
                unset($plans[$relative]);
            }
        }

        // --- from here on, the disk changes ---
        $created = [];
        foreach ($plans as $relative => $plan) {
            $outcome = $this->publish($plan['full'], $plan['content'], $force);

            if ($outcome === null) {
                $created[] = $relative;
                continue;
            }

            if ($outcome['reason'] === 'conflict') {
                // It was not there during preflight. Someone else got there
                // first, and exclusive creation is what caught it.
                $conflicts[] = $relative;
                continue;
            }

            $errors[] = ['path' => $relative] + $outcome;
        }

        $status = match (true) {
            $created !== [] && ($conflicts !== [] || $errors !== []) => 'partial',
            $created === [] && $errors !== [] => 'error',
            $created === [] && $conflicts !== [] => 'conflict',
            default => 'success',
        };

        return $this->result($status, $created, $conflicts, $errors);
    }

    /**
     * @param list<string> $created
     * @param list<string> $conflicts
     * @param list<array{path: string, reason: string, detail: string}> $errors
     */
    private function result(string $status, array $created, array $conflicts, array $errors): GenerationResult
    {
        $nextSteps = [];
        if ($conflicts !== []) {
            $nextSteps[] = 'Use --force to overwrite conflicting files';
        }
        if ($status === 'rejected') {
            $nextSteps[] = 'Fix the generator: the paths above cannot be written into a project';
        }

        return new GenerationResult(
            command: $this->commandName,
            status: $status,
            created: $created,
            skipped: [],
            conflicts: $conflicts,
            next_steps: $nextSteps,
            verify: $this->verifyCreated($created),
            errors: $errors === [] ? null : $errors,
        );
    }

    /**
     * Why this path may not be written, or null when it may.
     *
     * @return array{path: string, reason: string, detail: string}|null
     */
    private function refusePath(string $relative): ?array
    {
        $refuse = static fn(string $reason, string $detail): array
            => ['path' => $relative, 'reason' => $reason, 'detail' => $detail];

        if (trim($relative) === '') {
            return $refuse('empty', 'the planned path is empty');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $relative) === 1) {
            return $refuse('control', 'the path contains a control character');
        }

        if (str_contains($relative, '\\')) {
            return $refuse('backslash', 'the path contains a backslash; generated paths are /-separated');
        }

        if (str_starts_with($relative, '/') || preg_match('#^[A-Za-z]:#', $relative) === 1) {
            return $refuse('absolute', 'the path is absolute; generated paths are relative to the project root');
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return $refuse('traversal', "the path contains a '{$segment}' segment");
            }

            // `src//Thing.php` and `src/Thing.php` are the same file to the
            // filesystem and two different strings to the duplicate check, so a
            // batch planning both passed preflight, wrote one file, and reported
            // two as created — with the second content and no error. One
            // spelling per file, and the spelling is the one the caller gave.
            // Raised in review of dev#83.
            if ($segment === '') {
                return $refuse('alias', 'the path has an empty segment; one file must have exactly one spelling');
            }
        }

        return $this->refuseResolvedLocation($relative);
    }

    /**
     * The string being well-formed says nothing about where it LANDS: a symlink
     * already inside the project resolves elsewhere while the path still reads
     * as a subdirectory. A generator has no business writing through one, so any
     * link on the way to the target — or at it — is refused rather than
     * followed.
     *
     * @return array{path: string, reason: string, detail: string}|null
     */
    private function refuseResolvedLocation(string $relative): ?array
    {
        $refuse = static fn(string $reason, string $detail): array
            => ['path' => $relative, 'reason' => $reason, 'detail' => $detail];

        $root = realpath($this->basePath);
        if ($root === false) {
            // No project root on disk yet: there is nothing to escape through.
            return null;
        }

        $full = $this->basePath . '/' . $relative;

        if (is_link($full)) {
            return $refuse('symlink', 'the target is a symbolic link');
        }

        $existing = $full;
        while (!file_exists($existing)) {
            $parent = dirname($existing);
            if ($parent === $existing) {
                break;
            }
            $existing = $parent;
        }

        // realpath collapses every link in the prefix, so one answer covers the
        // whole ancestry.
        $real = realpath($existing);
        if ($real === false) {
            return $refuse('unresolvable', 'no part of the path could be resolved on disk');
        }

        if ($real !== $root && !str_starts_with($real, $root . '/')) {
            return $refuse('escape', 'the path resolves outside the project root');
        }

        return null;
    }

    /**
     * Put the content at $fullPath, whole or not at all.
     *
     * @return array{reason: string, detail: string}|null null on success
     */
    private function publish(string $fullPath, string $content, bool $force): ?array
    {
        $dir = dirname($fullPath);
        // `!mkdir && !is_dir` tolerates a concurrent writer creating the
        // directory first — mkdir returns false but the directory now exists,
        // which is not an error — while still failing on a real one.
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['reason' => 'mkdir_failed', 'detail' => "could not create directory {$dir}"];
        }

        $temp = $dir . '/.' . basename($fullPath) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temp, $content) === false) {
            @unlink($temp);
            return ['reason' => 'write_failed', 'detail' => 'could not write the temporary file (permissions, disk full, or a directory in the way)'];
        }
        @chmod($temp, self::FILE_MODE);

        if ($force) {
            // rename() replaces atomically: readers see the old file or the new
            // one, never a partial write.
            if (!@rename($temp, $fullPath)) {
                @unlink($temp);
                return ['reason' => 'publish_failed', 'detail' => "could not move the generated file into place at {$fullPath}"];
            }

            return null;
        }

        // Without --force, creation must be EXCLUSIVE, and the check and the
        // create must be one step — a file that appeared since preflight has to
        // lose the race, not get overwritten. link() fails if the target
        // exists, which is exactly that guarantee, and it publishes whole
        // content like rename() does.
        if (@link($temp, $fullPath)) {
            @unlink($temp);
            return null;
        }
        @unlink($temp);

        if (file_exists($fullPath)) {
            return ['reason' => 'conflict', 'detail' => 'the file appeared after preflight and was not overwritten'];
        }

        // No hard links on this filesystem. Fall back to an exclusive open,
        // which keeps the race guarantee; it gives up whole-file publication,
        // so say so rather than pretend otherwise.
        $handle = @fopen($fullPath, 'xb');
        if ($handle === false) {
            return file_exists($fullPath)
                ? ['reason' => 'conflict', 'detail' => 'the file appeared after preflight and was not overwritten']
                : ['reason' => 'publish_failed', 'detail' => "could not create {$fullPath}"];
        }

        $written = @fwrite($handle, $content);
        fclose($handle);
        if ($written === false || $written !== strlen($content)) {
            @unlink($fullPath);
            return ['reason' => 'write_failed', 'detail' => "could not write the whole of {$fullPath}"];
        }
        @chmod($fullPath, self::FILE_MODE);

        return null;
    }

    /**
     * Run `php -l` on each newly-created .php file. Returns null when no PHP
     * files were created (keeps the envelope small for non-PHP outputs).
     *
     * @param list<string> $created
     * @return array{status: string, checked: int, errors: list<array{file: string, message: string}>, reason?: string}|null
     */
    private function verifyCreated(array $created): ?array
    {
        $phpFiles = array_values(array_filter(
            $created,
            static fn(string $rel): bool => str_ends_with($rel, '.php'),
        ));

        if ($phpFiles === []) {
            return null;
        }

        $phpBin = $this->resolvePhpBinary();
        if ($phpBin === null) {
            return ['status' => 'skipped', 'checked' => 0, 'errors' => []];
        }

        $errors = [];
        // Checks that actually COMPLETED. The early return below used to report
        // count($errors), so one clean file plus one parse error plus a runner
        // failure said "checked 1" — the number of problems, not the number of
        // files looked at. Raised in review of dev#83.
        $checked = 0;

        foreach ($phpFiles as $rel) {
            $full = $this->basePath . '/' . $rel;
            if (!is_file($full)) {
                $errors[] = ['file' => $rel, 'message' => 'created file is missing during verification'];
                $checked++;
                continue;
            }

            $outcome = $this->processRunner->run([$phpBin, '-l', '-n', $full], $this->basePath);

            // The runner reports its OWN failures through `failure`: a timeout,
            // a host without POSIX process groups, a subprocess that would not
            // start. Those say nothing about the generated file, and treating
            // them as syntax errors blamed a perfectly good one — and failed
            // the command for it.
            if (($outcome['failure'] ?? null) !== null) {
                // Whatever was already found stands. Returning `skipped` here
                // threw away a real parse error found in an earlier file — and
                // GenerationExitCode treats skipped as success, so the command
                // exited 0 having written code known not to parse.
                return $errors === []
                    ? ['status' => 'skipped', 'checked' => 0, 'errors' => [], 'reason' => (string) $outcome['failure']]
                    : [
                        'status' => 'fail',
                        'checked' => $checked,
                        'errors' => $errors,
                        'reason' => 'stopped early: ' . (string) $outcome['failure'],
                    ];
            }

            // The runner answered about this file, so the check completed —
            // whatever it says next.
            $checked++;

            if ($outcome['exit'] !== 0) {
                $message = trim($outcome['output']);
                $errors[] = [
                    'file'    => $rel,
                    'message' => $message !== '' ? $message : "php -l exited with code {$outcome['exit']}",
                ];
            }
        }

        return [
            'status'  => $errors === [] ? 'pass' : 'fail',
            'checked' => $checked,
            'errors'  => $errors,
        ];
    }

    private function resolvePhpBinary(): ?string
    {
        if (PHP_BINARY !== '' && (is_file(PHP_BINARY) || is_executable(PHP_BINARY))) {
            return PHP_BINARY;
        }

        $resolved = trim((string) @shell_exec('command -v php 2>/dev/null'));

        return $resolved !== '' ? $resolved : null;
    }
}

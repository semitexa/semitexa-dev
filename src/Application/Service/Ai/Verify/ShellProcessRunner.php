<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * Default {@see ProcessRunner} backed by `proc_open`. Captures stdout + stderr
 * into a single buffer (we only ever surface the last signal line, so the merge
 * is fine — and matches how `php -l` already prints to stdout).
 */
final class ShellProcessRunner implements ProcessRunner
{
    public function run(array $command, string $cwd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // Silenced deliberately: a missing binary is an expected outcome here
        // (callers probe for optional tools like `gh`), and it is already
        // reported structurally below. The raw PHP warning added nothing and
        // corrupted --json output by printing ahead of the envelope.
        $proc = @proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            return ['exit' => 1, 'output' => 'failed to spawn: ' . implode(' ', $command)];
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [
            'exit'   => $exit,
            'output' => trim($stdout . "\n" . $stderr),
        ];
    }
}

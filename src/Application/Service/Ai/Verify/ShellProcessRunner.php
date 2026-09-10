<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * Default {@see ProcessRunner} backed by `proc_open`. Captures stdout + stderr
 * into a single buffer (we only ever surface the last signal line, so the merge
 * is fine — and matches how `php -l` already prints to stdout).
 *
 * The merge happens in the KERNEL (stderr redirected onto the stdout pipe),
 * not by reading two pipes one after the other. Two pipes drained in sequence
 * deadlock the moment the child fills the second one before closing the
 * first: the child blocks on write, this side blocks on read, and nothing
 * moves. That needs 64 KB of stderr normally, but only one 4 KB page once the
 * kernel's per-user pipe quota is spent — which a Swoole host with hundreds of
 * worker pipes reaches — and skills-sync.sh --check hung ai:verify for
 * thirteen minutes that way (BusyBox find printing its usage to stderr).
 * With one pipe there is nothing to wait on but the child itself.
 */
final class ShellProcessRunner implements ProcessRunner
{
    public function run(array $command, string $cwd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],
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
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($proc);

        return [
            'exit'   => $exit,
            'output' => trim($output),
        ];
    }
}

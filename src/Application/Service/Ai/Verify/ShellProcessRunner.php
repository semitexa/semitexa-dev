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
    // A new session owns both the command and its descendants. pcntl_exec is
    // not available in the shipped runtime, so a tiny PHP supervisor waits
    // for the argv-style command in that session instead. No shell quoting,
    // extra binary, or Composer dependency is involved.
    private const LAUNCHER = <<<'PHP'
if (!function_exists('posix_setsid') || posix_setsid() < 0) {
    fwrite(STDERR, "could not establish subprocess session\n");
    exit(125);
}
$child = proc_open(array_slice($argv, 1), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $unused);
if (!is_resource($child)) {
    fwrite(STDERR, "could not start subprocess\n");
    exit(125);
}
exit(proc_close($child));
PHP;

    public function __construct(
        private readonly float $timeoutSeconds = 120.0,
        private readonly int $maxOutputBytes = 4194304,
    ) {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $maxOutputBytes < 128) {
            throw new \InvalidArgumentException('Process limits require a finite positive timeout and at least 128 output bytes.');
        }
    }

    public function run(array $command, string $cwd): array
    {
        if ($command === [] || !function_exists('posix_kill') || !function_exists('posix_setsid')) {
            return ['exit' => 125, 'output' => 'Bounded subprocess execution requires a command and POSIX process groups.', 'failure' => 'unsupported'];
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],
        ];
        // Silenced deliberately: a missing binary is an expected outcome here
        // (callers probe for optional tools like `gh`), and it is already
        // reported structurally below. The raw PHP warning added nothing and
        // corrupted --json output by printing ahead of the envelope.
        $started = hrtime(true);
        $proc = @proc_open([PHP_BINARY, '-r', self::LAUNCHER, '--', ...$command], $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            return ['exit' => 125, 'output' => 'failed to spawn subprocess supervisor', 'failure' => 'spawn'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $status = proc_get_status($proc);
        $pid = $status['pid'];
        $output = '';
        $exit = -1;
        $failure = null;
        try {
            while (true) {
                $chunk = fread($pipes[1], 8192);
                if ($chunk === false) {
                    $failure = 'read';
                    break;
                }
                $remaining = $this->maxOutputBytes - strlen($output);
                $output .= substr($chunk, 0, $remaining);
                if (strlen($chunk) > $remaining) {
                    $failure = 'output_limit';
                    break;
                }
                $status = proc_get_status($proc);
                if (!$status['running'] && $status['exitcode'] >= 0) {
                    $exit = $status['exitcode'];
                }
                if (!$status['running'] && feof($pipes[1])) {
                    break;
                }
                if ((hrtime(true) - $started) / 1e9 >= $this->timeoutSeconds) {
                    $failure = 'timeout';
                    break;
                }
                if ($chunk === '') {
                    // Even a descendant that inherits stdout after the direct
                    // command exits remains bounded by the same deadline.
                    usleep(10000);
                }
            }
        } finally {
            // Kill the group while its leader PID is still reserved (before
            // proc_close reaps it). Also stops background descendants on a
            // nominally successful command; this is not a daemon launcher.
            @posix_kill(-$pid, 15);
            if ($failure !== null) {
                @proc_terminate($proc, 15);
                usleep(50000);
            }
            @posix_kill(-$pid, 9);
            if ($failure !== null) {
                @proc_terminate($proc, 9);
            }
            fclose($pipes[1]);
            $closedExit = proc_close($proc);
        }
        if ($failure !== null) {
            $signal = "\nsubprocess stopped: " . $failure;
            return [
                'exit' => $failure === 'timeout' ? 124 : 125,
                'output' => trim(substr($output, 0, $this->maxOutputBytes - strlen($signal)) . $signal),
                'failure' => $failure,
            ];
        }
        $exit = $exit >= 0 ? $exit : $closedExit;
        return ['exit' => $exit, 'output' => trim($output) ?: ($exit === 0 ? '' : 'subprocess exited with status ' . $exit)];
    }
}

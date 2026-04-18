<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Verify;

/**
 * Tiny shell-out abstraction so tests can replace `php -l` / `phpunit` invocation
 * without spawning real subprocesses. Implementations must return the captured
 * combined stdout+stderr so {@see VerificationExecutor} can extract a signal line.
 */
interface ProcessRunner
{
    /**
     * @param list<string> $command argv-style; the runner is responsible for safe quoting
     * @return array{exit: int, output: string}
     */
    public function run(array $command, string $cwd): array;
}

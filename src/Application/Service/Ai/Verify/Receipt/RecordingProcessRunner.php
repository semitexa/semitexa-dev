<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Receipt;

use Semitexa\Dev\Application\Service\Ai\Verify\ProcessRunner;

/**
 * Runs what it is given and remembers it: argv, cwd, exit code, a hash of the
 * output and how long it took. The receipt of an ai:verify run is built from
 * this, so it names the commands that actually ran rather than the ones the
 * plan meant to run.
 */
final class RecordingProcessRunner implements ProcessRunner
{
    /** @var list<array{argv: list<string>, cwd: string, exit: int, output_sha256: string, output_bytes: int, ms: int}> */
    private array $calls = [];

    public function __construct(private readonly ProcessRunner $inner) {}

    public function run(array $command, string $cwd): array
    {
        $started = hrtime(true);
        $result = $this->inner->run($command, $cwd);
        $this->calls[] = [
            'argv'          => $command,
            'cwd'           => $cwd,
            'exit'          => $result['exit'],
            'output_sha256' => hash('sha256', $result['output']),
            'output_bytes'  => strlen($result['output']),
            'ms'            => intdiv(hrtime(true) - $started, 1_000_000),
        ];

        return $result;
    }

    /** @return list<array{argv: list<string>, cwd: string, exit: int, output_sha256: string, output_bytes: int, ms: int}> */
    public function calls(): array
    {
        return $this->calls;
    }
}

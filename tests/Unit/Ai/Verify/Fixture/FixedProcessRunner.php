<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Fixture;

use Semitexa\Dev\Application\Service\Ai\Verify\ProcessRunner;

/** Answers every command with the same result, running nothing. */
final class FixedProcessRunner implements ProcessRunner
{
    /** @param array{exit: int, output: string, failure?: string} $result */
    public function __construct(private readonly array $result) {}

    public function run(array $command, string $cwd): array
    {
        return $this->result;
    }
}

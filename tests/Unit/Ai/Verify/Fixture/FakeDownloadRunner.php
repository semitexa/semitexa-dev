<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Fixture;

use Semitexa\Dev\Application\Service\Ai\Verify\ProcessRunner;

/**
 * Stands in for curl and tar: a "download" writes the given bytes where
 * `--output` points, or fails with the given message, and nothing touches the
 * network.
 */
final class FakeDownloadRunner implements ProcessRunner
{
    /** @var list<string> the program of each command run, in order */
    public array $programs = [];

    public ?string $url = null;

    public function __construct(
        private readonly ?string $body,
        private readonly string $error = '',
    ) {}

    public function run(array $command, string $cwd): array
    {
        $this->programs[] = $command[0] ?? '';
        if (($command[0] ?? '') !== 'curl') {
            return ['exit' => 1, 'output' => 'not expected to run'];
        }
        $this->url = $command[count($command) - 1];
        if ($this->body === null) {
            return ['exit' => 6, 'output' => $this->error];
        }
        $at = array_search('--output', $command, true);
        file_put_contents($command[(int) $at + 1], $this->body);

        return ['exit' => 0, 'output' => ''];
    }
}

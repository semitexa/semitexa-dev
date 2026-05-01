<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Data;

final readonly class GenerationResult
{
    /**
     * @param string $command
     * @param string $status
     * @param list<string> $created
     * @param list<string> $skipped
     * @param list<string> $conflicts
     * @param list<string> $next_steps
     * @param list<string> $replay_args
     * @param array{status: string, checked: int, errors: list<array{file: string, message: string}>}|null $verify
     * @param array{status: string, checks: array<string, array{status: string, summary: string}>}|null $lint
     */
    public function __construct(
        public string $command,
        public string $status,
        public array $created = [],
        public array $skipped = [],
        public array $conflicts = [],
        public array $next_steps = [],
        public array $replay_args = [],
        public ?array $verify = null,
        public ?array $lint = null,
    ) {}

    public function withLint(?array $lint): self
    {
        return new self(
            command: $this->command,
            status: $this->status,
            created: $this->created,
            skipped: $this->skipped,
            conflicts: $this->conflicts,
            next_steps: $this->next_steps,
            replay_args: $this->replay_args,
            verify: $this->verify,
            lint: $lint,
        );
    }

    /**
     * @param list<string> $replayArgs
     */
    public function withReplayArgs(array $replayArgs): self
    {
        return new self(
            command: $this->command,
            status: $this->status,
            created: $this->created,
            skipped: $this->skipped,
            conflicts: $this->conflicts,
            next_steps: $this->next_steps,
            replay_args: $replayArgs,
            verify: $this->verify,
            lint: $this->lint,
        );
    }

    public function toArray(): array
    {
        $out = [
            'command' => $this->command,
            'status' => $this->status,
            'created' => $this->created,
            'skipped' => $this->skipped,
            'conflicts' => $this->conflicts,
            'next_steps' => $this->next_steps,
            'replay_args' => $this->replay_args,
        ];
        if ($this->verify !== null) {
            $out['verify'] = $this->verify;
        }
        if ($this->lint !== null) {
            $out['lint'] = $this->lint;
        }
        return $out;
    }
}

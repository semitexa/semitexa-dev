<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Data;

final readonly class CommandCapability
{
    /**
     * @param string $name
     * @param string $kind
     * @param string $summary
     * @param string $use_when
     * @param string $avoid_when
     * @param array<string, array{type: string, description: string}> $required_inputs
     * @param array<string, array{type: string, description: string, default?: mixed}> $optional_inputs
     * @param array<string, string> $outputs
     * @param list<string> $supports
     * @param list<string> $follow_up
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $summary,
        public string $use_when,
        public string $avoid_when,
        public array $required_inputs = [],
        public array $optional_inputs = [],
        public array $outputs = [],
        public array $supports = [],
        public array $follow_up = [],
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'kind' => $this->kind,
            'summary' => $this->summary,
            'use_when' => $this->use_when,
            'avoid_when' => $this->avoid_when,
            'required_inputs' => $this->required_inputs,
            'optional_inputs' => $this->optional_inputs,
            'outputs' => $this->outputs,
            'supports' => $this->supports,
            'follow_up' => $this->follow_up,
        ];
    }
}

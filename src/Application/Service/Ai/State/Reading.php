<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

/**
 * What a computed criterion's evidence says right now (tk-state-computed-criteria):
 * named numbers read on this run, or why they could not be read. A score
 * remembers the reading it was given against, so the state can say when the
 * evidence has moved since: the points then need a look, whichever way it went.
 */
final readonly class Reading
{
    /**
     * @param array<string, int> $values name => number, e.g. tests.skip-calls => 87
     */
    public function __construct(
        public string $criterionId,
        public array $values,
        public ?string $failure = null,
    ) {}

    public static function failed(string $criterionId, string $why): self
    {
        return new self($criterionId, [], $why);
    }

    public function text(): string
    {
        if ($this->failure !== null) {
            return 'could not read: ' . $this->failure;
        }
        $parts = [];
        foreach ($this->values as $name => $value) {
            $parts[] = "{$name} {$value}";
        }

        return implode(', ', $parts);
    }

    /**
     * Whether the evidence moved since a score's reading: null when either
     * side has nothing to compare (no reading kept, or none taken now).
     *
     * @param array<string, int>|null $then
     */
    public function changedSince(?array $then): ?bool
    {
        if ($then === null || $this->failure !== null) {
            return null;
        }
        $now = $this->values;
        ksort($now);
        ksort($then);

        return $now !== $then;
    }
}

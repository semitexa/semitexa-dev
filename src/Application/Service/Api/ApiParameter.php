<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Api;

/**
 * One constructor parameter. For an attribute this is the whole API: what can
 * be written between the parentheses, and what happens when it is left out.
 */
final readonly class ApiParameter
{
    /**
     * @param list<string> $cases The enum's cases when the type is an enum, so
     *                            the value can be written without a second lookup.
     * @param list<string> $implementers What can be passed when the type is an
     *                                   interface, when that fits on one line.
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?string $default,
        public string $summary,
        public array $cases = [],
        public array $implementers = [],
        public bool $variadic = false,
        public bool $byReference = false,
    ) {}

    /**
     * @param list<string> $implementers
     */
    public function withImplementers(array $implementers): self
    {
        return new self($this->name, $this->type, $this->default, $this->summary, $this->cases, $implementers, $this->variadic, $this->byReference);
    }

    /**
     * @return array{name: string, type: string, default: ?string, required: bool, variadic: bool, by_reference: bool, summary: string, cases: list<string>, implementers: list<string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'default' => $this->default,
            'required' => $this->default === null && !$this->variadic,
            'variadic' => $this->variadic,
            'by_reference' => $this->byReference,
            'summary' => $this->summary,
            'cases' => $this->cases,
            'implementers' => $this->implementers,
        ];
    }

    public function signature(): string
    {
        return trim($this->type . ' ' . ($this->byReference ? '&' : '') . ($this->variadic ? '...' : '') . '$' . $this->name)
            . ($this->default === null ? '' : ' = ' . $this->default);
    }
}

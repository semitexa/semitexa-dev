<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Charter;

/**
 * One way the owner will know the project works: a number to reach, a thing a
 * user can do, a date. With a source it is computed (a quality metric, a
 * check) and the state can measure it; without one it is judged, and needs a
 * proof before it counts.
 */
final readonly class SuccessSign
{
    public function __construct(
        public string $id,
        public string $text,
        public ?string $source = null,
    ) {}

    public function isComputed(): bool
    {
        return $this->source !== null && trim($this->source) !== '';
    }

    /** @return array{id: string, text: string, kind: string, source: ?string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'kind' => $this->isComputed() ? 'computed' : 'judged',
            'source' => $this->source,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $text = $data['text'] ?? null;
        $source = $data['source'] ?? null;
        if (!is_string($id) || $id === '' || !is_string($text) || trim($text) === '' || ($source !== null && !is_string($source))) {
            throw new \RuntimeException('a sign of success needs an id and a text (and, if any, a source string)');
        }

        return new self($id, $text, $source);
    }
}

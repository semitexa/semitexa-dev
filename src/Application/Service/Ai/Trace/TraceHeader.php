<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Trace;

/**
 * First line of every trace file. Stored as NDJSON with `"kind":"header"`.
 *
 * Schema is deliberately minimal. Anything task-specific goes on events, not
 * the header — traces may be read by tools that don't know about topics or
 * recipes, and they must be able to ignore what they don't recognise.
 *
 * Treat `schemaVersion` as the compatibility contract: bump it only when a
 * reader written against v1 would genuinely be wrong about v2 data.
 */
final readonly class TraceHeader
{
    public const SCHEMA_VERSION = 'semitexa-dev.ai-trace/v1';
    public const KIND           = 'header';

    public function __construct(
        public string $schemaVersion,
        public string $traceId,
        public string $createdAt,
        public ?string $topic = null,
        public ?string $recipe = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind'           => self::KIND,
            'schema_version' => $this->schemaVersion,
            'trace_id'       => $this->traceId,
            'created_at'     => $this->createdAt,
            'topic'          => $this->topic,
            'recipe'         => $this->recipe,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            schemaVersion: (string) ($row['schema_version'] ?? self::SCHEMA_VERSION),
            traceId:       (string) ($row['trace_id'] ?? ''),
            createdAt:     (string) ($row['created_at'] ?? ''),
            topic:         isset($row['topic']) ? (string) $row['topic'] : null,
            recipe:        isset($row['recipe']) ? (string) $row['recipe'] : null,
        );
    }
}

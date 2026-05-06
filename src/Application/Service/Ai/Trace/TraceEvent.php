<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Trace;

/**
 * One appended entry on a trace. Stored as NDJSON with `"kind":"event"`.
 *
 * `eventId` is a monotonic 1-based counter assigned by {@see TraceStore} on
 * append — readers must not assume it maps to wall-clock order under clock
 * skew, only that earlier ids came earlier within the same trace file.
 *
 * `summary` is free-form but should compress the event to one readable line,
 * matching the `signal` convention used by {@see \Semitexa\Dev\Application\Service\Ai\Verify\VerificationResult}.
 *
 * `payload` is the structured tail — exact JSON-compatible shape is left to
 * the emitter, but it SHOULD mirror the artifact envelope of the command that
 * produced it (e.g. `semitexa-dev.verify-report/v1`) so a downstream reader
 * can cross-reference without re-parsing.
 */
final readonly class TraceEvent
{
    public const KIND = 'event';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public int $eventId,
        public string $at,
        public string $eventKind,
        public string $summary,
        public array $payload = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind'       => self::KIND,
            'event_id'   => $this->eventId,
            'at'         => $this->at,
            'event_kind' => $this->eventKind,
            'summary'    => $this->summary,
            'payload'    => $this->payload,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $payload = $row['payload'] ?? [];
        return new self(
            eventId:   (int) ($row['event_id'] ?? 0),
            at:        (string) ($row['at'] ?? ''),
            eventKind: (string) ($row['event_kind'] ?? 'unknown'),
            summary:   (string) ($row['summary'] ?? ''),
            payload:   is_array($payload) ? $payload : [],
        );
    }
}

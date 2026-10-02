<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Evidence;

/**
 * The passport of one piece of evidence: `var/evidence/<id>/meta.json`, kept
 * beside the file it describes. Private until a publication is approved, and
 * gone after `expiresAt`.
 */
final readonly class EvidenceRecord
{
    public const SCHEMA = 'semitexa.evidence/v1';

    /**
     * @param list<array{to: string, approved_at: string, approved_by: string}> $publications
     */
    public function __construct(
        public string $id,
        public EvidenceKind $kind,
        public EvidenceData $data,
        public string $file,
        public string $sha256,
        public int $bytes,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public string $createdBy,
        public string $source,
        public string $note,
        public array $publications = [],
    ) {
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function withPublication(string $to, string $approvedBy, \DateTimeImmutable $at): self
    {
        return new self(
            $this->id, $this->kind, $this->data, $this->file, $this->sha256, $this->bytes,
            $this->createdAt, $this->expiresAt, $this->createdBy, $this->source, $this->note,
            [...$this->publications, ['to' => $to, 'approved_at' => $at->format(DATE_ATOM), 'approved_by' => $approvedBy]],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'id' => $this->id,
            'kind' => $this->kind->value,
            'data' => $this->data->value,
            'file' => $this->file,
            'sha256' => $this->sha256,
            'bytes' => $this->bytes,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
            'created_by' => $this->createdBy,
            'source' => $this->source,
            'note' => $this->note,
            'visibility' => $this->publications === [] ? 'private' : 'published',
            'publications' => $this->publications,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @throws \UnexpectedValueException on a passport this code did not write
     */
    public static function fromArray(array $row): self
    {
        if (($row['schema'] ?? null) !== self::SCHEMA) {
            throw new \UnexpectedValueException('not a ' . self::SCHEMA . ' passport');
        }
        $kind = EvidenceKind::tryFrom((string) ($row['kind'] ?? ''));
        $data = EvidenceData::tryFrom((string) ($row['data'] ?? ''));
        if ($kind === null || $data === null || !is_string($row['id'] ?? null) || !is_string($row['file'] ?? null)) {
            throw new \UnexpectedValueException('passport is missing its id, file, kind or data');
        }
        $publications = [];
        foreach (is_array($row['publications'] ?? null) ? $row['publications'] : [] as $p) {
            if (is_array($p)) {
                $publications[] = ['to' => (string) ($p['to'] ?? ''), 'approved_at' => (string) ($p['approved_at'] ?? ''), 'approved_by' => (string) ($p['approved_by'] ?? '')];
            }
        }

        return new self(
            $row['id'],
            $kind,
            $data,
            $row['file'],
            (string) ($row['sha256'] ?? ''),
            (int) ($row['bytes'] ?? 0),
            new \DateTimeImmutable((string) ($row['created_at'] ?? 'now')),
            new \DateTimeImmutable((string) ($row['expires_at'] ?? '@0')),
            (string) ($row['created_by'] ?? ''),
            (string) ($row['source'] ?? ''),
            (string) ($row['note'] ?? ''),
            $publications,
        );
    }
}

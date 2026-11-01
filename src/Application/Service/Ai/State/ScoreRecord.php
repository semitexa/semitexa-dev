<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

use Semitexa\Dev\Application\Service\Ai\Charter\Blank;
use Semitexa\Dev\Application\Service\Ai\Decision\Decision;

/**
 * The points one criterion holds, with the proof that set them, who set them,
 * when, and until when they count (tk-state-judged-expiry). Past its expiry a
 * score is stale: it shows as unknown and adds nothing until proven again,
 * so the state never reports the last number for something nobody re-checked.
 */
final readonly class ScoreRecord
{
    public function __construct(
        public string $criterionId,
        public int $points,
        public string $evidence,
        public string $by,
        public string $scoredAt,
        public string $expiresAt,
    ) {}

    public function isCurrent(\DateTimeImmutable $now): bool
    {
        return new \DateTimeImmutable($this->expiresAt) > $now;
    }

    public function toScore(): CriterionScore
    {
        return new CriterionScore($this->criterionId, $this->points, $this->evidence);
    }

    /** @return array{criterion: string, points: int, evidence: string, by: string, scored_at: string, expires_at: string} */
    public function toArray(): array
    {
        return [
            'criterion' => $this->criterionId,
            'points' => $this->points,
            'evidence' => $this->evidence,
            'by' => $this->by,
            'scored_at' => $this->scoredAt,
            'expires_at' => $this->expiresAt,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $text = static function (string $key) use ($data): string {
            $value = $data[$key] ?? null;
            if (!is_string($value) || Blank::is($value)) {
                throw new \RuntimeException("score {$key} must be non-empty text");
            }

            return $value;
        };
        $points = $data['points'] ?? null;
        if (!is_int($points) || $points < 0) {
            throw new \RuntimeException('score points must be a whole number, 0 or more');
        }
        foreach (['scored_at', 'expires_at'] as $key) {
            if (!Decision::isInstant($text($key))) {
                throw new \RuntimeException("score {$key} must be an ISO-8601 time with its offset");
            }
        }

        return new self($text('criterion'), $points, $text('evidence'), $text('by'), $text('scored_at'), $text('expires_at'));
    }
}

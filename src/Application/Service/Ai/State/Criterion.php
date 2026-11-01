<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

/** One thing a phase is scored on, with its weight and where its evidence comes from. */
final readonly class Criterion
{
    public function __construct(
        public string $id,
        public Phase $phase,
        public string $title,
        public int $weight,
        public CriterionKind $kind,
        public string $evidence,
    ) {
        if (preg_match('/\A[a-z]+\.[a-z0-9-]+\z/', $id) !== 1) {
            throw new \InvalidArgumentException("criterion id \"{$id}\" must read <phase>.<name>");
        }
        if (!str_starts_with($id, $phase->value . '.')) {
            throw new \InvalidArgumentException("criterion \"{$id}\" is declared under {$phase->value}");
        }
        if ($weight < 1 || $weight > 100) {
            throw new \InvalidArgumentException("criterion \"{$id}\" weighs {$weight}: a weight is 1 to 100");
        }
        if (trim($title) === '' || trim($evidence) === '') {
            throw new \InvalidArgumentException("criterion \"{$id}\" needs a title and the evidence it is scored from");
        }
    }

    /** @return array{id: string, phase: string, title: string, weight: int, kind: string, evidence: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'phase' => $this->phase->value,
            'title' => $this->title,
            'weight' => $this->weight,
            'kind' => $this->kind->value,
            'evidence' => $this->evidence,
        ];
    }
}

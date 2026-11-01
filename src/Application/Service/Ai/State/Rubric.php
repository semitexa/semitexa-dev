<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

/**
 * The criteria a project's state is scored on. Each phase present weighs 100
 * in total, so its score reads as a percentage and two scores on the same
 * rubric compare; a rubric that does not add up is refused at construction.
 */
final readonly class Rubric
{
    /** @var list<Criterion> */
    public array $criteria;

    /** @param list<Criterion> $criteria */
    public function __construct(array $criteria)
    {
        $ids = [];
        $weights = [];
        foreach ($criteria as $criterion) {
            if (isset($ids[$criterion->id])) {
                throw new \InvalidArgumentException("criterion \"{$criterion->id}\" is declared twice");
            }
            $ids[$criterion->id] = true;
            $weights[$criterion->phase->value] = ($weights[$criterion->phase->value] ?? 0) + $criterion->weight;
        }
        foreach ($weights as $phase => $total) {
            if ($total !== 100) {
                throw new \InvalidArgumentException("{$phase} weighs {$total}: a phase's criteria weigh 100 together");
            }
        }
        $this->criteria = $criteria;
    }

    /** @return list<Criterion> */
    public function forPhase(Phase $phase): array
    {
        return array_values(array_filter($this->criteria, static fn (Criterion $c): bool => $c->phase === $phase));
    }

    /** @return list<Phase> the phases this rubric scores, in work, right, fast order */
    public function phases(): array
    {
        return array_values(array_filter(Phase::cases(), fn (Phase $p): bool => $this->forPhase($p) !== []));
    }

    public function criterion(string $id): ?Criterion
    {
        foreach ($this->criteria as $criterion) {
            if ($criterion->id === $id) {
                return $criterion;
            }
        }

        return null;
    }
}

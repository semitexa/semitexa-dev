<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

/**
 * A phase's percentage from a rubric and the scores given to its criteria.
 * A criterion with no score counts nothing and is listed as unscored: the
 * number never borrows a point it has no evidence for. A phase none of whose
 * criteria is scored has no number at all (null), not a measured 0.
 */
final class StateScore
{
    /**
     * @param list<CriterionScore> $scores
     * @return array<string, array{percent: ?int, points: int, weight: int, unscored: list<string>}> by phase
     */
    public static function phases(Rubric $rubric, array $scores): array
    {
        $byId = [];
        foreach ($scores as $score) {
            $criterion = $rubric->criterion($score->criterionId);
            if ($criterion === null) {
                throw new \InvalidArgumentException("a score for \"{$score->criterionId}\", which the rubric does not declare");
            }
            if (isset($byId[$score->criterionId])) {
                throw new \InvalidArgumentException("\"{$score->criterionId}\" is scored twice");
            }
            if ($score->points < 0 || $score->points > $criterion->weight) {
                throw new \InvalidArgumentException("\"{$score->criterionId}\" scores {$score->points} of {$criterion->weight}");
            }
            if (trim($score->evidence) === '') {
                throw new \InvalidArgumentException("\"{$score->criterionId}\" is scored without its evidence");
            }
            $byId[$score->criterionId] = $score;
        }

        $phases = [];
        foreach ($rubric->phases() as $phase) {
            $points = 0;
            $weight = 0;
            $unscored = [];
            foreach ($rubric->forPhase($phase) as $criterion) {
                $weight += $criterion->weight;
                if (isset($byId[$criterion->id])) {
                    $points += $byId[$criterion->id]->points;
                } else {
                    $unscored[] = $criterion->id;
                }
            }
            $scored = count($unscored) < count($rubric->forPhase($phase));
            $phases[$phase->value] = [
                // A phase weighs 100 (Rubric), so its points are its percent.
                'percent' => $scored ? intdiv($points * 100, $weight) : null,
                'points' => $points,
                'weight' => $weight,
                'unscored' => $unscored,
            ];
        }

        return $phases;
    }
}

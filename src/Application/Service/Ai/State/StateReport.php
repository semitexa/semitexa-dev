<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

/**
 * What ai:state shows (tk-state-command): each phase's percent from the
 * scores that are still current, every criterion with its points, kind,
 * evidence, age and status (current | stale | unscored), and what would
 * raise each phase most: the criteria by weight minus points, an unknown one
 * counting its whole weight.
 */
final class StateReport
{
    /**
     * @param array<string, ScoreRecord> $records
     * @param array<string, Reading> $readings the computed criteria's evidence as read on this run
     * @param array<string, CriterionFormula> $formulas the criteria Semitexa scores itself from their readings
     * @return array{phases: array<string, array{percent: ?int, unknown: int, criteria: list<array<string, mixed>>, raise: list<array{criterion: string, title: string, gap: int}>}>, orphans: list<array{criterion: string, points: int, why: string}>}
     */
    public static function build(Rubric $rubric, array $records, \DateTimeImmutable $now, array $readings = [], array $formulas = []): array
    {
        // A criterion with a formula and a reading it can use is scored here,
        // from what was measured on this run; a recorded score for it is not
        // counted (status `computed`). Without a usable reading it falls back
        // to its record, so a failed read never zeroes it.
        $computed = [];
        foreach ($formulas as $id => $formula) {
            $criterion = $rubric->criterion($id);
            $reading = $readings[$id] ?? null;
            if ($criterion !== null && $reading !== null && $reading->failure === null) {
                $points = $formula->points($reading->values, $criterion->weight);
                if ($points !== null) {
                    $computed[$id] = ['points' => $points, 'evidence' => $reading->text() . ' — ' . $formula->text];
                }
            }
        }

        // A score the rubric no longer holds (renamed, reweighted, hand-edited)
        // is shown apart, never counted and never fatal to the whole report.
        $orphans = [];
        foreach ($records as $id => $record) {
            $criterion = $rubric->criterion($id);
            if ($criterion === null || $record->points < 0 || $record->points > $criterion->weight) {
                $orphans[] = ['criterion' => $id, 'points' => $record->points, 'why' => match (true) {
                    $criterion === null => 'no criterion of this rubric',
                    $record->points < 0 => 'below 0',
                    default => "above its weight {$criterion->weight}",
                }];
                unset($records[$id]);
            }
        }
        $current = array_filter($records, static fn (ScoreRecord $r): bool => $r->isCurrent($now) && !isset($computed[$r->criterionId]));
        $scores = array_values(array_map(static fn (ScoreRecord $r): CriterionScore => $r->toScore(), $current));
        foreach ($computed as $id => $c) {
            $scores[] = new CriterionScore($id, $c['points'], $c['evidence']);
        }
        $percents = StateScore::phases($rubric, $scores);

        $phases = [];
        foreach ($rubric->phases() as $phase) {
            $criteria = [];
            $raise = [];
            foreach ($rubric->forPhase($phase) as $criterion) {
                $record = $records[$criterion->id] ?? null;
                if (isset($computed[$criterion->id])) {
                    $criteria[] = [
                        'criterion' => $criterion->id,
                        'title' => $criterion->title,
                        'weight' => $criterion->weight,
                        'kind' => $criterion->kind->value,
                        'points' => $computed[$criterion->id]['points'],
                        'status' => 'computed',
                        'evidence' => $computed[$criterion->id]['evidence'],
                        'by' => 'semitexa',
                        'age_days' => 0,
                        'expires_at' => null,
                        'reading' => $readings[$criterion->id]->text(),
                        'reading_changed' => null,
                    ];
                    $gap = $criterion->weight - $computed[$criterion->id]['points'];
                    if ($gap > 0) {
                        $raise[] = ['criterion' => $criterion->id, 'title' => $criterion->title, 'gap' => $gap];
                    }
                    continue;
                }
                $status = match (true) {
                    $record === null => 'unscored',
                    $record->isCurrent($now) => 'current',
                    default => 'stale',
                };
                $points = $status === 'current' ? $record?->points : null;
                $criteria[] = [
                    'criterion' => $criterion->id,
                    'title' => $criterion->title,
                    'weight' => $criterion->weight,
                    'kind' => $criterion->kind->value,
                    'points' => $points,
                    'status' => $status,
                    'evidence' => $record?->evidence,
                    'by' => $record?->by,
                    'age_days' => $record === null ? null : intdiv(max(0, $now->getTimestamp() - (new \DateTimeImmutable($record->scoredAt))->getTimestamp()), 86400),
                    'expires_at' => $record?->expiresAt,
                    // Measured now, and whether it moved since the points were
                    // given: a moved reading asks for a look, it does not rescore.
                    'reading' => isset($readings[$criterion->id]) ? $readings[$criterion->id]->text() : null,
                    'reading_changed' => isset($readings[$criterion->id]) ? $readings[$criterion->id]->changedSince($record?->reading) : null,
                ];
                $gap = $criterion->weight - ($points ?? 0);
                if ($gap > 0) {
                    $raise[] = ['criterion' => $criterion->id, 'title' => $criterion->title, 'gap' => $gap];
                }
            }
            usort($raise, static fn (array $a, array $b): int => [$b['gap'], $a['criterion']] <=> [$a['gap'], $b['criterion']]);
            $phases[$phase->value] = [
                'percent' => $percents[$phase->value]['percent'],
                'unknown' => count($percents[$phase->value]['unscored']),
                'criteria' => $criteria,
                'raise' => array_slice($raise, 0, 3),
            ];
        }

        return ['phases' => $phases, 'orphans' => $orphans];
    }

    /**
     * "work 97% · right 78% · fast 33%", "?" for a phase with nothing current,
     * and "(N unknown)" after a number some criteria of which are stale or
     * unscored: that number is a floor, not a measurement.
     *
     * @param array{phases: array<string, array{percent: ?int, unknown: int}>} $report
     */
    public static function line(array $report): string
    {
        $parts = [];
        foreach ($report['phases'] as $phase => $data) {
            $parts[] = $phase . ' ' . match (true) {
                $data['percent'] === null => '?',
                $data['unknown'] > 0 => $data['percent'] . '% (' . $data['unknown'] . ' unknown)',
                default => $data['percent'] . '%',
            };
        }

        return implode(' · ', $parts);
    }
}

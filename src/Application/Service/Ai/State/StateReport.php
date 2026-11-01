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
     * @return array{phases: array<string, array{percent: ?int, unknown: int, criteria: list<array<string, mixed>>, raise: list<array{criterion: string, title: string, gap: int}>}>, orphans: list<array{criterion: string, points: int, why: string}>}
     */
    public static function build(Rubric $rubric, array $records, \DateTimeImmutable $now): array
    {
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
        $current = array_filter($records, static fn (ScoreRecord $r): bool => $r->isCurrent($now));
        $percents = StateScore::phases($rubric, array_values(array_map(static fn (ScoreRecord $r): CriterionScore => $r->toScore(), $current)));

        $phases = [];
        foreach ($rubric->phases() as $phase) {
            $criteria = [];
            $raise = [];
            foreach ($rubric->forPhase($phase) as $criterion) {
                $record = $records[$criterion->id] ?? null;
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

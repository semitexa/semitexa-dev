<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Orient;

/**
 * What `ai:orient --json` answers by default: the first call of a cold start,
 * so every byte of it is read before any work (tk-orient-cold-start-too-big).
 *
 * On 2026-11-12 the full envelope was 22.8 KB: every in-progress task with its
 * whole next step and context refs, every unread red receipt of the day, five
 * traces with their last event. The brief keeps what decides the next move:
 * counts, each task's id, title, age and holder, which one it suggests (its
 * next step is in suggest_next), the receipts as numbers, the first quality
 * target. `--full` still gives everything.
 */
final class OrientBrief
{
    /** An in-progress task untouched this long is probably abandoned, not being worked. */
    public const STALE_AFTER_DAYS = 14;

    private const TRACES = 3;
    private const RECEIPT_IDS = 3;

    /**
     * @param array<string, mixed> $envelope the full ai:orient envelope
     * @return array<string, mixed>
     */
    public static function of(array $envelope, ?string $suggestedTaskId, ?string $session, int $now): array
    {
        $backlog = is_array($envelope['backlog'] ?? null) ? $envelope['backlog'] : [];
        $backlog['in_progress_tasks'] = array_map(
            static fn (array $t): array => self::task($t, $t['id'] === $suggestedTaskId, $now),
            is_array($backlog['in_progress_tasks'] ?? null) ? $backlog['in_progress_tasks'] : [],
        );
        $backlog['blocked_tasks'] = array_map(
            static fn (array $t): array => self::task($t, false, $now),
            is_array($backlog['blocked_tasks'] ?? null) ? $backlog['blocked_tasks'] : [],
        );
        $envelope['backlog'] = $backlog;

        $traces = is_array($envelope['recent_traces'] ?? null) ? $envelope['recent_traces'] : [];
        $envelope['recent_traces'] = array_map(static fn (array $t): array => [
            'id' => $t['id'] ?? null,
            'topic' => $t['topic'] ?? null,
            'last_at' => $t['last_event']['at'] ?? null,
        ], array_slice($traces, 0, self::TRACES));

        $receipts = is_array($envelope['unread_failed_verify'] ?? null) ? $envelope['unread_failed_verify'] : [];
        $mine = array_values(array_filter($receipts, static fn (array $r): bool => $session !== null && ($r['run_by']['agent_session'] ?? null) === $session));
        $envelope['unread_failed_verify'] = [
            'count' => count($receipts),
            'this_session' => count($mine),
            'other_sessions' => count($receipts) - count($mine),
            'latest' => array_column(array_slice($receipts, 0, self::RECEIPT_IDS), 'id'),
        ];

        // The first target says where to look; the others are a --full away.
        $envelope['quality_next'] = array_slice(is_array($envelope['quality_next'] ?? null) ? $envelope['quality_next'] : [], 0, 1);
        $envelope['brief'] = 'ai:orient --full --json gives every field: whole next steps, context refs, every receipt and trace';

        return $envelope;
    }

    /**
     * The epic the work is under: the newest in-progress task's, else an epic
     * marked in progress, else the newest blocked task's. A done, archived or
     * discarded epic is never active, whatever task under it was left open
     * (tk-orient-cold-start-too-big).
     *
     * @param list<\Semitexa\Dev\Application\Service\Ai\Work\Task> $inProgress
     * @param list<\Semitexa\Dev\Application\Service\Ai\Work\Task> $blocked
     * @param list<\Semitexa\Dev\Application\Service\Ai\Work\Epic> $epics
     */
    public static function activeEpicId(array $inProgress, array $blocked, array $epics): ?string
    {
        $closed = [];
        foreach ($epics as $epic) {
            if (in_array($epic->status->value, ['done', 'archived', 'discarded'], true)) {
                $closed[$epic->id] = true;
            }
        }
        foreach ($inProgress as $task) {
            if (!isset($closed[$task->epicId])) {
                return $task->epicId;
            }
        }
        foreach ($epics as $epic) {
            if ($epic->status->value === 'in_progress') {
                return $epic->id;
            }
        }
        foreach ($blocked as $task) {
            if (!isset($closed[$task->epicId])) {
                return $task->epicId;
            }
        }

        return null;
    }

    /** A task another live session holds is not ours to resume unasked: said before the suggestion. */
    public static function heldElsewhere(?string $taskId, ?string $holder, ?string $you): string
    {
        return $taskId !== null && $holder !== null && $holder !== $you
            ? "Task `{$taskId}` is held by `{$holder}`, a live session: coordinate with it or pick other work. "
            : '';
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private static function task(array $task, bool $suggested, int $now): array
    {
        $brief = [
            'id' => $task['id'] ?? null,
            'title' => $task['title'] ?? null,
            'updated_at' => $task['updated_at'] ?? null,
        ];
        if (array_key_exists('held_by', $task)) {
            $brief['held_by'] = $task['held_by'];
        }
        $updated = is_string($task['updated_at'] ?? null) ? strtotime($task['updated_at']) : false;
        if ($updated !== false && $now - $updated > self::STALE_AFTER_DAYS * 86400) {
            $brief['stale'] = true;
        }
        if ($suggested) {
            $brief['suggested'] = true; // its next step is in suggest_next, once
        }

        return $brief;
    }
}

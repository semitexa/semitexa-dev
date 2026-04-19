<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Work;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;

/**
 * Hygiene checks over the backlog.
 *
 * Two jobs:
 *   - assess a single epic / task → {@see HygieneAssessment} (quality,
 *     issue slugs, suggested status, suggested cleanup action).
 *   - scope a listing → {@see epicInScope()}, {@see taskInScope()} apply
 *     the visibility rules for {@see BacklogScope}.
 *
 * No persistence. The store is the single writer; hygiene only reads and
 * proposes. `ai:backlog clean --apply` is what actually mutates state, and
 * it does so through {@see EpicStore} / {@see TaskStore}.
 *
 *   --- Placeholder / junk heuristics (epic) ---
 *   placeholder-id       id matches /^ep-[a-z0-9]{1,2}$/    (ep-a, ep-b, ep-1)
 *   placeholder-title    title length ≤ 2, or is a known placeholder token
 *                        (test, tmp, foo, bar, baz, t, x)
 *   no-goal              goal is empty or < GOAL_MIN_LEN chars
 *   no-tasks             zero tasks AND status != done/archived
 *   stale-done           status=done AND older than DONE_RECENT_DAYS
 *
 *   --- Placeholder / junk heuristics (task) ---
 *   placeholder-id       id matches /^tk-[a-z0-9]{1,2}$/
 *   placeholder-title    title length ≤ 2, or is a known placeholder token
 *   no-next-step         next_step missing AND status != done
 *   no-context           context_refs empty AND status != done
 *   orphan               epic_id does not exist in the epic store
 *
 *   Quality rollup:
 *     quality=JUNK  if placeholder-id + (placeholder-title OR no-goal),
 *                   or orphan,
 *                   or placeholder-id alone + no real metadata.
 *     quality=DRAFT if any issue but not junk.
 *     quality=CLEAN if no issues.
 */
#[AsService]
final class BacklogHygiene
{
    public const int GOAL_MIN_LEN      = 15;
    public const int TITLE_MIN_LEN     = 3;

    private const array PLACEHOLDER_TITLES = [
        't', 'tt', 'ttt', 'x', 'xx', 'test', 'tmp', 'temp', 'foo', 'bar', 'baz',
        'todo', 'wip', 'draft',
    ];

    #[InjectAsReadonly]
    protected EpicStore $epicStore;

    #[InjectAsReadonly]
    protected TaskStore $taskStore;

    public function assessEpic(Epic $epic): HygieneAssessment
    {
        $issues = [];

        $placeholderId    = $this->isPlaceholderEpicId($epic->id);
        $placeholderTitle = $this->isPlaceholderTitle($epic->title);
        $noGoal           = trim($epic->goal) === '' || mb_strlen(trim($epic->goal)) < self::GOAL_MIN_LEN;
        $noTasks          = count($epic->taskIds) === 0
            && $epic->status !== EpicStatus::DONE
            && $epic->status !== EpicStatus::ARCHIVED;

        if ($placeholderId) {
            $issues[] = 'placeholder-id';
        }
        if ($placeholderTitle) {
            $issues[] = 'placeholder-title';
        }
        if ($noGoal) {
            $issues[] = 'no-goal';
        }
        if ($noTasks) {
            $issues[] = 'no-tasks';
        }
        if ($epic->status === EpicStatus::DONE && $this->isStale($epic->updatedAt)) {
            $issues[] = 'stale-done';
        }

        $isJunk = $placeholderId && ($placeholderTitle || $noGoal);

        $quality = match (true) {
            $isJunk          => BacklogQuality::JUNK,
            $issues === []   => BacklogQuality::CLEAN,
            default          => BacklogQuality::DRAFT,
        };

        [$suggestedStatus, $suggestedAction] = match (true) {
            $quality === BacklogQuality::JUNK && !$epic->status->isDiscarded()
                => [EpicStatus::DISCARDED->value, 'discard placeholder epic'],
            $epic->status === EpicStatus::DONE && $this->isStale($epic->updatedAt)
                => [EpicStatus::ARCHIVED->value, 'archive stale completed epic'],
            default => [null, null],
        };

        return new HygieneAssessment($quality, $issues, $suggestedStatus, $suggestedAction);
    }

    public function assessTask(Task $task): HygieneAssessment
    {
        $issues = [];

        $placeholderId    = $this->isPlaceholderTaskId($task->id);
        $placeholderTitle = $this->isPlaceholderTitle($task->title);
        $noNextStep       = ($task->nextStep === null || trim($task->nextStep) === '')
            && $task->status !== TaskStatus::DONE;
        $noContext        = $task->contextRefs === [] && $task->status !== TaskStatus::DONE;
        $orphan           = !$this->epicStore->exists($task->epicId);

        if ($placeholderId) {
            $issues[] = 'placeholder-id';
        }
        if ($placeholderTitle) {
            $issues[] = 'placeholder-title';
        }
        if ($noNextStep) {
            $issues[] = 'no-next-step';
        }
        if ($noContext) {
            $issues[] = 'no-context';
        }
        if ($orphan) {
            $issues[] = 'orphan';
        }

        $isJunk = $orphan
            || ($placeholderId && ($placeholderTitle || $noNextStep));

        $quality = match (true) {
            $isJunk          => BacklogQuality::JUNK,
            $issues === []   => BacklogQuality::CLEAN,
            default          => BacklogQuality::DRAFT,
        };

        [$suggestedStatus, $suggestedAction] = match (true) {
            $quality === BacklogQuality::JUNK && !$task->status->isDiscarded()
                => [TaskStatus::DISCARDED->value, 'discard placeholder task'],
            default => [null, null],
        };

        return new HygieneAssessment($quality, $issues, $suggestedStatus, $suggestedAction);
    }

    public function epicInScope(Epic $epic, HygieneAssessment $assessment, BacklogScope $scope): bool
    {
        return match ($scope) {
            BacklogScope::ALL       => true,
            BacklogScope::ACTIVE    => $this->epicIsInActiveView($epic, $assessment),
            BacklogScope::DRAFTS    => $assessment->quality === BacklogQuality::DRAFT
                                        && !$epic->status->isDiscarded(),
            BacklogScope::DONE      => $epic->status === EpicStatus::DONE,
            BacklogScope::ARCHIVE   => $epic->status === EpicStatus::ARCHIVED,
            BacklogScope::DISCARDED => $epic->status === EpicStatus::DISCARDED,
            BacklogScope::JUNK      => $assessment->quality === BacklogQuality::JUNK
                                        && !$epic->status->isDiscarded(),
        };
    }

    public function taskInScope(Task $task, HygieneAssessment $assessment, BacklogScope $scope): bool
    {
        return match ($scope) {
            BacklogScope::ALL       => true,
            BacklogScope::ACTIVE    => $this->taskIsInActiveView($task, $assessment),
            BacklogScope::DRAFTS    => $assessment->quality === BacklogQuality::DRAFT
                                        && !$task->status->isDiscarded(),
            BacklogScope::DONE      => $task->status === TaskStatus::DONE,
            BacklogScope::ARCHIVE   => false, // tasks don't have an archived status
            BacklogScope::DISCARDED => $task->status === TaskStatus::DISCARDED,
            BacklogScope::JUNK      => $assessment->quality === BacklogQuality::JUNK
                                        && !$task->status->isDiscarded(),
        };
    }

    private function epicIsInActiveView(Epic $epic, HygieneAssessment $assessment): bool
    {
        if ($epic->status->isDiscarded() || $epic->status === EpicStatus::ARCHIVED) {
            return false;
        }
        if ($assessment->quality === BacklogQuality::JUNK) {
            return false;
        }
        if ($epic->status === EpicStatus::DONE) {
            return !$this->isStale($epic->updatedAt);
        }
        return $epic->status->isActive();
    }

    private function taskIsInActiveView(Task $task, HygieneAssessment $assessment): bool
    {
        if ($task->status->isDiscarded()) {
            return false;
        }
        if ($assessment->quality === BacklogQuality::JUNK) {
            return false;
        }
        if ($task->status === TaskStatus::DONE) {
            return !$this->isStale($task->updatedAt);
        }
        return $task->status->isActive();
    }

    private function isPlaceholderEpicId(string $id): bool
    {
        return (bool) preg_match('/^ep-[a-z0-9]{1,2}$/i', $id)
            || (bool) preg_match('/^epic-[a-z0-9]{1,2}$/i', $id)
            || in_array(strtolower($id), ['ep', 'epic', 'test', 'tmp'], true);
    }

    private function isPlaceholderTaskId(string $id): bool
    {
        return (bool) preg_match('/^tk-[a-z0-9]{1,2}$/i', $id)
            || (bool) preg_match('/^task-[a-z0-9]{1,2}$/i', $id)
            || in_array(strtolower($id), ['tk', 'task', 'test', 'tmp'], true);
    }

    private function isPlaceholderTitle(string $title): bool
    {
        $t = strtolower(trim($title));
        if ($t === '') {
            return true;
        }
        if (mb_strlen($t) < self::TITLE_MIN_LEN) {
            return true;
        }
        return in_array($t, self::PLACEHOLDER_TITLES, true);
    }

    private function isStale(string $isoTimestamp): bool
    {
        if ($isoTimestamp === '') {
            return false;
        }
        try {
            $ts = (new \DateTimeImmutable($isoTimestamp))->getTimestamp();
        } catch (\Exception) {
            return false;
        }
        $ageDays = (int) floor((time() - $ts) / 86_400);
        return $ageDays > BacklogScope::DONE_RECENT_DAYS;
    }
}

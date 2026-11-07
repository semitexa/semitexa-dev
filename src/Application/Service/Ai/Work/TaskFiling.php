<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

use Semitexa\Dev\Application\Service\Ai\State\Phase;

/**
 * What a task is the moment it is filed (ep-project-progress-from-backlog).
 *
 * New work is a proposal: an idea until the owner approves it. A bug, or a
 * right or fast ticket, is something found, not something proposed: an agent
 * files it without leaving its task, and it counts from then on, so a found bug
 * moves the percent at once. A bug is what the backlog already calls one, a
 * task under a bug recipe, and it belongs to work unless it says otherwise.
 */
final class TaskFiling
{
    public const RULE = 'ai:decision project-progress-model';

    /**
     * The recipes defects are filed under; the classifier files a bug report
     * ("fix the 500 on /login") as debug_investigate.
     */
    public const BUG_RECIPES = ['fix_bug', 'bugfix', 'debug_investigate'];

    private const BY_RULE = 'rule';

    /** @throws \InvalidArgumentException on a value that is not a phase */
    public static function parsePhase(?string $value): ?Phase
    {
        if ($value === null) {
            return null;
        }
        $phase = Phase::tryFrom(strtolower(trim($value)));
        if ($phase === null) {
            throw new \InvalidArgumentException("invalid phase '{$value}': expected one of work, right, fast");
        }

        return $phase;
    }

    public static function phaseOf(?Phase $given, string $recipe): ?Phase
    {
        return $given ?? (self::isBug($recipe) ? Phase::WORK : null);
    }

    public static function approvalOnFiling(?Phase $phase, string $recipe, string $now): ?TaskApproval
    {
        if (self::isBug($recipe)) {
            return new TaskApproval(self::BY_RULE, 'a bug counts from the moment it is filed: ' . self::RULE, $now);
        }
        if ($phase === Phase::RIGHT || $phase === Phase::FAST) {
            return new TaskApproval(self::BY_RULE, 'right and fast tickets count from the moment they are filed: ' . self::RULE, $now);
        }

        return null;
    }

    /**
     * A task after an edit. An approval given by rule lasts only while the rule
     * holds, or a ticket filed under right and moved to work would be approved
     * work nobody approved. The owner's approval stays whatever the phase. An
     * edit never approves: only filing and ai:work:approve do.
     */
    public static function afterEdit(Task $task, string $now): Task
    {
        $phase = self::phaseOf($task->phase, $task->recipe);
        if ($phase !== $task->phase) {
            $task = $task->with(phase: $phase);
        }
        if ($task->approval?->by === self::BY_RULE && self::approvalOnFiling($phase, $task->recipe, $now) === null) {
            return $task->withApproval(null, $now);
        }

        return $task;
    }

    /** Approved by the owner's word, not only by the rule of filing. */
    public static function hasOwnersApproval(Task $task): bool
    {
        return $task->approval !== null && $task->approval->by !== self::BY_RULE;
    }

    public static function isBug(string $recipe): bool
    {
        return in_array($recipe, self::BUG_RECIPES, true);
    }
}

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
 *
 * In the stabilization mode ({@see \Semitexa\Dev\Application\Service\Ai\Decision\Stabilization},
 * read by the commands and passed in as a flag) the rule approves only a
 * blocker, a failed release criterion named by its class, filed with evidence:
 * a command and its output. Every other finding is an idea.
 */
final class TaskFiling
{
    public const RULE = 'ai:decision project-progress-model';

    /**
     * The recipes defects are filed under; the classifier files a bug report
     * ("fix the 500 on /login") as debug_investigate.
     */
    public const BUG_RECIPES = ['fix_bug', 'bugfix', 'debug_investigate'];

    /**
     * What a release cannot ship with (ai:decision stabilization-2026-11): a
     * crash in a core path, a memory or coroutine leak, a security defect, a
     * failing ai:verify or test suite, a public doc or claim a command's
     * output contradicts, a published bench budget missed.
     */
    public const BLOCKERS = ['crash', 'leak', 'security', 'verify-failure', 'docs-contradicted', 'bench-budget'];

    private const BY_RULE = 'rule';

    private const BLOCKER_WORDS = 'blocker ';

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

    /**
     * @param bool $stabilization whether the stabilization mode is on
     * @param string|null $blocker one of BLOCKERS, when the filing names one
     * @param string|null $evidence "<command> => <its output>"
     */
    public static function approvalOnFiling(?Phase $phase, string $recipe, string $now, bool $stabilization = false, ?string $blocker = null, ?string $evidence = null): ?TaskApproval
    {
        if (!self::countsByRule($phase, $recipe)) {
            return null;
        }
        if (!$stabilization) {
            return new TaskApproval(self::BY_RULE, (self::isBug($recipe) ? 'a bug counts from the moment it is filed: ' : 'right and fast tickets count from the moment they are filed: ') . self::RULE, $now);
        }
        if ($blocker === null || !self::isBlocker($blocker) || !self::isEvidence($evidence)) {
            return null;
        }

        // The class and the evidence are kept in the approval's words: the task's own record, with no new field.
        return new TaskApproval(self::BY_RULE, self::BLOCKER_WORDS . $blocker . ': ' . trim((string) $evidence), $now);
    }

    /** What the rule of filing speaks of at all: a bug, or a right or fast ticket. */
    public static function countsByRule(?Phase $phase, string $recipe): bool
    {
        return self::isBug($recipe) || $phase === Phase::RIGHT || $phase === Phase::FAST;
    }

    /** Whether an edit moves the task to another recipe or phase, which files it anew. */
    public static function isRefiled(Task $before, ?string $recipe, ?Phase $phase): bool
    {
        return ($recipe !== null && $recipe !== $before->recipe) || ($phase !== null && $phase !== $before->phase);
    }

    /**
     * Why a filing is refused, or null. An unknown blocker class and evidence
     * that is no command with its output are refused in any mode. In the
     * stabilization mode a task under a bug recipe counts by its recipe, so it
     * cannot be an idea: it is filed as a blocker with evidence, or without
     * the recipe.
     */
    public static function refusal(string $recipe, bool $stabilization, ?string $blocker, ?string $evidence): ?string
    {
        if ($blocker !== null && !self::isBlocker($blocker)) {
            return "invalid blocker '{$blocker}': expected one of " . implode(', ', self::BLOCKERS);
        }
        if ($evidence !== null && !self::isEvidence($evidence)) {
            return '--evidence is a command and its output: --evidence="<command> => <its output>"';
        }
        if ($blocker !== null && $evidence === null) {
            return '--blocker needs its proof: add --evidence="<command> => <its output>"';
        }
        if ($stabilization && self::isBug($recipe) && $blocker === null) {
            return 'stabilization mode: a bug is filed only as a blocker with evidence: add --blocker=<' . implode('|', self::BLOCKERS) . '> and --evidence="<command> => <its output>". Anything else is an idea: file it without --recipe.';
        }

        return null;
    }

    /**
     * Why an edit is refused in the stabilization mode, or null: a task nobody
     * approved does not become a bug by an edit, since a bug counts by its
     * recipe alone.
     */
    public static function refusalOfEdit(Task $task, ?string $recipe, bool $stabilization): ?string
    {
        if ($stabilization && $recipe !== null && self::isBug($recipe) && !self::isBug($task->recipe) && !$task->isApproved()) {
            return 'stabilization mode: an edit does not make a task a bug. File the bug with ai:work start --recipe=' . $recipe . ' --blocker=<class> --evidence="<command> => <its output>", or leave this one an idea.';
        }

        return null;
    }

    /** The blocker class a task was approved under by rule, or null. */
    public static function blockerOf(Task $task): ?string
    {
        if ($task->approval?->by !== self::BY_RULE || preg_match('/^' . self::BLOCKER_WORDS . '([a-z-]+): /', $task->approval->words, $found) !== 1) {
            return null;
        }

        return self::isBlocker($found[1]) ? $found[1] : null;
    }

    public static function isBlocker(string $class): bool
    {
        return in_array($class, self::BLOCKERS, true);
    }

    /** A command, `=>`, and what it printed. */
    public static function isEvidence(?string $evidence): bool
    {
        return $evidence !== null && preg_match('/\S\s*=>\s*\S/u', $evidence) === 1;
    }

    /**
     * A task after an edit. An approval given by rule lasts only while the rule
     * holds, or a ticket filed under right and moved to work would be approved
     * work nobody approved. The owner's approval stays whatever the phase. An
     * edit never approves: only filing and ai:work:approve do.
     *
     * @param bool $stabilization the stabilization mode is on AND this edit
     *        moved the task to another recipe or phase, which files it anew:
     *        then only a blocker's approval by rule holds. Any other edit
     *        leaves an approval given before the mode alone, since the open
     *        backlog is triaged on the owner's yes, not by whoever edits it.
     */
    public static function afterEdit(Task $task, string $now, bool $stabilization = false): Task
    {
        $phase = self::phaseOf($task->phase, $task->recipe);
        if ($phase !== $task->phase) {
            $task = $task->with(phase: $phase);
        }
        if ($task->approval?->by !== self::BY_RULE) {
            return $task;
        }
        if (self::approvalOnFiling($phase, $task->recipe, $now) === null || ($stabilization && self::blockerOf($task) === null)) {
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

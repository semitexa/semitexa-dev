<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Presence;

use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;

/**
 * Taking a task, so two agents do not work the same one without knowing.
 *
 * Moving a task to in_progress claims it for the current agent session;
 * finishing, blocking or discarding it lets go. If another LIVE agent holds it,
 * the move is refused with who that is and what they said they are doing — the
 * one fact that decides whether to wait, coordinate, or `--take-over`. A holder
 * that went quiet does not block anyone: silence past the live window is how an
 * abandoned session reads.
 */
final class TaskClaim
{
    /**
     * Any update to a task another live agent holds is refused without
     * --take-over — a title edit or a `done` included, not only a move to
     * in_progress.
     *
     * @return string|null why the update is refused, or null when it may proceed
     */
    public static function claim(string $projectRoot, string $taskId, ?TaskStatus $to, bool $takeOver): ?string
    {
        $registry = new AgentRegistry($projectRoot);

        // A lock or release that cannot be written is a refusal too, not an
        // exception past the caller's refusal branch.
        try {
            return $registry->locked(static function () use ($registry, $taskId, $to, $takeOver): ?string {
                return self::claimLocked($registry, $taskId, $to, $takeOver);
            });
        } catch (\RuntimeException $e) {
            return "could not update the claim on '{$taskId}': " . $e->getMessage();
        }
    }

    private static function claimLocked(AgentRegistry $registry, string $taskId, ?TaskStatus $to, bool $takeOver): ?string
    {
        $self = $registry->current();
        $declared = getenv(AgentRegistry::ENV);
        if ($self === null && is_string($declared) && $declared !== '') {
            // The agent believes it is present; a claim made now would be
            // recorded nowhere and a second agent would get no refusal.
            return sprintf(
                "%s=%s is not a live session (it ended or is missing) — run ai:agent join again, or unset %s",
                AgentRegistry::ENV,
                $declared,
                AgentRegistry::ENV,
            );
        }

        $holder = $registry->holderOf($taskId, $self?->id);
        if ($holder !== null && !$takeOver) {
            return sprintf(
                "task '%s' is held by %s (%s, active %d min ago): %s — coordinate with them, or pass --take-over",
                $taskId,
                $holder->id,
                $holder->agent,
                intdiv($holder->secondsSilent(time()), 60),
                $holder->intent,
            );
        }
        if ($to === null) {
            return null;
        }
        if ($holder !== null) {
            $registry->release($holder->id);
        }
        if ($to !== TaskStatus::IN_PROGRESS) {
            if ($self !== null && $self->task === $taskId) {
                $registry->release($self->id);
            }

            return null;
        }
        if ($self !== null) {
            $registry->recordTask($self, $taskId);  // throws: caught in claim() as a refusal
        }

        return null;
    }
}

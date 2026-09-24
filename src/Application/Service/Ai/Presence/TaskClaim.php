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
     * @return string|null why the move is refused, or null when it may proceed
     */
    public static function claim(string $projectRoot, string $taskId, ?TaskStatus $to, bool $takeOver): ?string
    {
        if ($to === null) {
            return null;
        }
        $registry = new AgentRegistry($projectRoot);
        $self = $registry->current();

        if ($to !== TaskStatus::IN_PROGRESS) {
            if ($self !== null && $self->task === $taskId) {
                $registry->release($self->id);
            }

            return null;
        }

        return $registry->locked(static function () use ($registry, $self, $taskId, $takeOver): ?string {
            return self::claimLocked($registry, $self, $taskId, $takeOver);
        });
    }

    private static function claimLocked(AgentRegistry $registry, ?AgentSession $self, string $taskId, bool $takeOver): ?string
    {
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
        if ($holder !== null) {
            $registry->release($holder->id);
        }
        $registry->beat($taskId);

        return null;
    }
}

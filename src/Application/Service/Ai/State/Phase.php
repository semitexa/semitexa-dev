<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

/** Make it work, make it right, make it fast: the three lenses the state is read through. */
enum Phase: string
{
    case WORK = 'work';
    case RIGHT = 'right';
    case FAST = 'fast';

    /** The phase a criterion id names before its dot ("work.installs"). */
    public static function forCriterion(string $id): self
    {
        $phase = self::tryFrom((string) strstr($id, '.', true));
        if ($phase === null) {
            throw new \InvalidArgumentException("criterion id \"{$id}\" must start with work., right. or fast.");
        }

        return $phase;
    }
}

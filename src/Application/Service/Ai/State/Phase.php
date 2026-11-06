<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

/** Make it work, make it right, make it fast: the three lenses the state is read through. */
enum Phase: string
{
    case WORK = 'work';
    case RIGHT = 'right';
    case FAST = 'fast';
}

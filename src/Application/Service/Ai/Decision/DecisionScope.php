<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Decision;

/** Whether a decision holds until revoked, or allows one act and is spent by it. */
enum DecisionScope: string
{
    case STANDING = 'standing';
    case ONCE = 'once';
}

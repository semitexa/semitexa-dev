<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

/**
 * Computed: a command or file gives the points (the quality ledger, phpstan,
 * a release report). Judged: a person or agent scores it, with a proof that
 * expires; once expired the score is unknown, never the last number.
 */
enum CriterionKind: string
{
    case COMPUTED = 'computed';
    case JUDGED = 'judged';
}

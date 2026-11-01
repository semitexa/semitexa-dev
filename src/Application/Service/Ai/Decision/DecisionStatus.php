<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Decision;

/**
 * What a decision says now. Expired is never stored: it is read off the
 * expiry at the moment of asking, so a decision cannot outlive its date
 * because nobody rewrote the file.
 */
enum DecisionStatus: string
{
    case ACTIVE = 'active';
    case CONSUMED = 'consumed';
    case REVOKED = 'revoked';
    case EXPIRED = 'expired';
}

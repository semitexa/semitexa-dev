<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

/**
 * Control-flow signal, not an error: thrown from inside the replay's
 * transaction after the handler finished, so TransactionManager's Throwable
 * path rolls everything back. The handler's outcome rides along — a replay
 * that COMMITTED has failed its one hard guarantee, and forcing every exit
 * through the rollback branch is what makes that guarantee structural rather
 * than a code path someone can miss.
 */
final class ReplayRollbackSignal extends \RuntimeException
{
    public function __construct(public readonly mixed $result)
    {
        parent::__construct('replay rollback');
    }
}

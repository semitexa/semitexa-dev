<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Support;

use Semitexa\Dev\Application\Service\Generation\Data\GenerationResult;
use Symfony\Component\Console\Command\Command;

/**
 * Turns a {@see GenerationResult} into a process exit code, in one place so
 * every make command answers the same way.
 *
 * Every one of them used to return SUCCESS whatever the writer said: a
 * conflict was a warning printed for a human to read, and a refused path or a
 * failed `php -l` was nothing at all. Three things that depend on the code
 * could not tell a finished generation from one that wrote nothing — a script,
 * a CI step, and `make` itself, whose dispatcher stops on the first non-zero
 * step and therefore never stopped.
 *
 * The code answers ONE question: did the command do all of what was asked?
 * It is not a taxonomy of what went wrong — WHICH files landed, which
 * conflicted, what the filesystem said and whether the result verifies stay
 * separate fields in the envelope, so a caller can act on each without
 * decoding an integer.
 */
final class GenerationExitCode
{
    /**
     * Statuses that mean the writer did not do everything it was asked to.
     * `planned` (and any dry-run status) is absent on purpose: nothing was
     * asked to be written, so nothing can have failed to write.
     */
    private const INCOMPLETE = ['rejected', 'error', 'conflict', 'partial'];

    public static function forResult(GenerationResult $result): int
    {
        if (in_array($result->status, self::INCOMPLETE, true)) {
            return Command::FAILURE;
        }

        // Written, but does it parse? A generator that emits code which does
        // not run has failed at the thing it exists for, and `verify` is a
        // `php -l` of the files this command just wrote — about them and
        // nothing else. `skipped` is not `fail`: a check that could not run is
        // not evidence of a problem.
        if (($result->verify['status'] ?? null) === 'fail') {
            return Command::FAILURE;
        }

        // `lint` is deliberately NOT consulted. PostWriteLinter runs lint:di
        // and lint:handlers over the WHOLE project, so a single pre-existing
        // violation anywhere would make every make:* exit 1 while writing the
        // file correctly — the command reporting somebody else's problem as its
        // own. The result still carries the lint block for a reader to act on.
        return Command::SUCCESS;
    }
}

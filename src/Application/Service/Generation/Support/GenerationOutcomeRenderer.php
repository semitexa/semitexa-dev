<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Support;

use Semitexa\Dev\Application\Service\Generation\Data\GenerationResult;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Says, to a human, why a `make:*` command did not write what it was asked to.
 *
 * The envelope carries three separate reasons a generation can come up short —
 * a path that already exists (`conflicts`), a path the writer refused or the
 * filesystem rejected (`errors`), and code that was written but does not parse
 * (`verify.errors`) — and `--json` has always shown all three. Plain text
 * showed only the first, so a refused write printed nothing at all and exited
 * non-zero: the operator saw a command that did nothing, said nothing, and
 * failed, while the reason sat in a field they had no reason to ask for.
 *
 * In one place because nine commands render this, and nine copies is how the
 * third reason came to be missing from all of them.
 */
final class GenerationOutcomeRenderer
{
    /**
     * Everything that went wrong, in the order the writer met it. Silent when
     * nothing did — the caller prints its own success line.
     */
    public static function renderProblems(SymfonyStyle $io, GenerationResult $result): void
    {
        if ($result->conflicts) {
            $io->warning(
                "These files already exist and were left alone:\n"
                . self::bulleted($result->conflicts)
                . "\nRe-run with --force to overwrite them."
            );
        }

        if ($result->errors) {
            $lines = [];
            foreach ($result->errors as $error) {
                $path = $error['path'] === '' ? '(empty path)' : $error['path'];
                $lines[] = "{$path} — {$error['reason']}: {$error['detail']}";
            }
            $io->error("The writer refused this batch:\n" . self::bulleted($lines));
        }

        $verifyErrors = $result->verify['errors'] ?? [];
        if ($verifyErrors !== []) {
            $lines = [];
            foreach ($verifyErrors as $error) {
                $lines[] = "{$error['file']}: {$error['message']}";
            }
            $io->error("Written, but it does not parse:\n" . self::bulleted($lines));
        }
    }

    /** @param list<string> $items */
    private static function bulleted(array $items): string
    {
        return implode("\n", array_map(static fn (string $item) => "  - {$item}", $items));
    }
}

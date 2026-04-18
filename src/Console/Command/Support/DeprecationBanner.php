<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command\Support;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Soft-deprecation signal for Phase 5 legacy commands. Emits a one-line
 * NDJSON record on stderr (so stdout envelopes stay byte-identical for
 * callers that pipe them through `jq`) at the start of the legacy command.
 *
 *   {"kind":"deprecated","command":"describe:project","replacement":"ai:ask project", …}
 *
 * Exit code and stdout are untouched — deprecation is purely informational
 * during the migration window.
 *
 * Delegation suppression: when a new-surface alias (`ai:ask`, `dev:graph:*`)
 * forwards to a legacy target, the user is already on the new surface — the
 * banner would be noise. {@see CommandDelegator} flips the env var below so
 * this helper skips the emission.
 */
final class DeprecationBanner
{
    public const SUPPRESS_ENV = 'SEMITEXA_DEV_SUPPRESS_DEPRECATION';

    public static function emit(
        OutputInterface $output,
        string $legacyCommand,
        string $replacement,
        ?string $message = null,
    ): void {
        if (getenv(self::SUPPRESS_ENV) === '1') {
            return;
        }
        $line = [
            'kind'        => 'deprecated',
            'command'     => $legacyCommand,
            'replacement' => $replacement,
        ];
        if ($message !== null) {
            $line['message'] = $message;
        }

        $sink = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $sink->writeln(json_encode($line, JSON_UNESCAPED_SLASHES));
    }
}

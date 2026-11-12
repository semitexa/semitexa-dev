<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Support;

use Semitexa\Dev\Application\Service\Generation\Data\GenerationResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What every make:* checks before planning a single file, answered the same
 * way by all of them.
 *
 * Two things went wrong here, once per generator:
 *
 * - A rejected input was printed as a Symfony error block even under --json,
 *   so a caller parsing stdout got nothing to parse. The exit code was right;
 *   the envelope an agent reads was missing.
 * - `--module` was never checked. Generators write into src/modules/<Module>/,
 *   and a module there is discovered by its directory alone, so a typo
 *   (`Playgound`) planned cleanly, recommended --write, and then quietly
 *   started a second module. Creating one is make:module's job.
 */
final class GenerationPreflight
{
    public const REASON_MISSING_OPTION = 'missing_option';
    public const REASON_INVALID_OPTION = 'invalid_option';
    public const REASON_UNKNOWN_MODULE = 'unknown_module';

    /**
     * @param list<string> $required options that must be non-empty
     *
     * @return int|null an exit code when the input was rejected, null to proceed
     */
    public static function check(
        InputInterface $input,
        OutputInterface $output,
        string $command,
        array $required,
        string $projectRoot,
    ): ?int {
        // Every problem in one answer (tk-make-page-errors-one-at-a-time): make:page
        // without --name, --path and --module used to take three calls to learn them.
        $errors = [];
        $replay = [];
        foreach ($required as $option) {
            $value = $input->getOption($option);
            if ($value) {
                $replay[] = '--' . $option . '=' . (is_scalar($value) ? (string) $value : '');
                continue;
            }
            $allowed = self::allowedValues($option, $projectRoot);
            $error = [
                'path' => '',
                'reason' => self::REASON_MISSING_OPTION,
                'detail' => "Missing required option: --{$option}" . ($allowed !== [] ? ' (one of: ' . implode(', ', $allowed) . ')' : ''),
                'option' => $option,
            ];
            if ($allowed !== []) {
                $error['allowed'] = $allowed;
            }
            $errors[] = $error;
            $replay[] = '--' . $option . '=<' . $option . '>';
        }

        $closest = null;
        $newModule = null;
        $given = $input->hasOption('module') ? $input->getOption('module') : null;
        if (is_string($given) && $given !== '') {
            $module = (new NameInflector())->toStudly($given);
            $existing = self::existingModules($projectRoot);
            if (!in_array($module, $existing, true)) {
                $closest = self::closest($module, $existing);
                $newModule = $module;
                $errors[] = [
                    'path' => '',
                    'reason' => self::REASON_UNKNOWN_MODULE,
                    'detail' => sprintf("Module '%s' does not exist under src/modules/.", $module)
                        . ($closest !== null ? sprintf(" Did you mean '%s'?", $closest) : '')
                        . sprintf(' To start a new module, run make:module --name=%s first.', $module)
                        . ($existing !== [] ? ' Existing: ' . implode(', ', $existing) . '.' : ''),
                    'option' => 'module',
                ];
            }
        }

        return $errors === [] ? null : self::refuse($input, $output, $command, $errors, $replay, $closest, $newModule);
    }

    /**
     * Values a required option can take, where the set is known: the modules
     * that exist, the HTTP methods.
     *
     * @return list<string>
     */
    private static function allowedValues(string $option, string $projectRoot): array
    {
        return match ($option) {
            'module' => self::existingModules($projectRoot),
            'method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
            default => [],
        };
    }

    /**
     * @param list<array<string, mixed>> $errors
     * @param list<string> $replay the required options as given, a placeholder for each missing one
     */
    private static function refuse(
        InputInterface $input,
        OutputInterface $output,
        string $command,
        array $errors,
        array $replay,
        ?string $suggestedModule,
        ?string $newModule,
    ): int {
        if ($input->hasOption('json') && $input->getOption('json')) {
            $result = new GenerationResult(command: $command, status: 'rejected', replay_args: $replay, errors: $errors);
            $output->writeln((new JsonResultFormatter())->formatRejection($result, $suggestedModule, $newModule));

            return Command::FAILURE;
        }

        (new SymfonyStyle($input, $output))->error(array_map(static fn (array $e): string => (string) $e['detail'], $errors));

        return Command::FAILURE;
    }

    /**
     * Refuse the input: a generation-result envelope under --json, an error
     * block otherwise. Exit code is FAILURE either way.
     */
    public static function reject(
        InputInterface $input,
        OutputInterface $output,
        string $command,
        string $reason,
        string $detail,
        ?string $suggestedModule = null,
        ?string $newModule = null,
    ): int {
        if ($input->hasOption('json') && $input->getOption('json')) {
            $result = new GenerationResult(
                command: $command,
                status: 'rejected',
                errors: [['path' => '', 'reason' => $reason, 'detail' => $detail]],
            );
            $output->writeln((new JsonResultFormatter())->formatRejection($result, $suggestedModule, $newModule));

            return Command::FAILURE;
        }

        (new SymfonyStyle($input, $output))->error($detail);

        return Command::FAILURE;
    }

    /**
     * @return list<string>
     */
    private static function existingModules(string $projectRoot): array
    {
        $dir = $projectRoot . '/src/modules';
        if (!is_dir($dir)) {
            return [];
        }

        $modules = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry[0] !== '.' && is_dir($dir . '/' . $entry)) {
                $modules[] = $entry;
            }
        }
        sort($modules);

        return $modules;
    }

    /**
     * @param list<string> $candidates
     */
    private static function closest(string $module, array $candidates): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            $distance = levenshtein(strtolower($module), strtolower($candidate));
            if ($distance < $bestDistance) {
                [$best, $bestDistance] = [$candidate, $distance];
            }
        }

        return $best !== null && $bestDistance <= max(2, intdiv(strlen($module), 4)) ? $best : null;
    }
}

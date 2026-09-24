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
        foreach ($required as $option) {
            if (!$input->getOption($option)) {
                return self::reject($input, $output, $command, self::REASON_MISSING_OPTION, "Missing required option: --{$option}");
            }
        }

        if (!$input->hasOption('module') || !is_string($input->getOption('module')) || $input->getOption('module') === '') {
            return null;
        }

        $module = (new NameInflector())->toStudly($input->getOption('module'));
        $existing = self::existingModules($projectRoot);
        if (in_array($module, $existing, true)) {
            return null;
        }

        $closest = self::closest($module, $existing);
        $detail = sprintf("Module '%s' does not exist under src/modules/.", $module)
            . ($closest !== null ? sprintf(" Did you mean '%s'?", $closest) : '')
            . sprintf(' To start a new module, run make:module --name=%s first.', $module)
            . ($existing !== [] ? ' Existing: ' . implode(', ', $existing) . '.' : '');

        return self::reject($input, $output, $command, self::REASON_UNKNOWN_MODULE, $detail, $closest, $module);
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

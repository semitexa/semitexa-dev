<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command\Support;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Phase 5 consolidation helper: thin forward of an incoming Input+Output to
 * a legacy command, copying only the options the target actually declares.
 *
 * The goal is that `ai:ask` and `dev:graph:*` can ship as additive aliases
 * during the deprecation window without duplicating option wiring. Once the
 * logic migrates into the new surface, these wrappers will flip direction
 * (legacy → new) and the helper will still be the right shape.
 *
 * Non-goals: this is not a generic proxy. It understands Symfony option
 * types (NONE/REQUIRED/OPTIONAL/IS_ARRAY) and nothing else.
 */
final class CommandDelegator
{
    public static function run(
        Application $app,
        string $targetCommand,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        try {
            $target = $app->find($targetCommand);
        } catch (CommandNotFoundException $e) {
            $output->writeln(json_encode([
                'kind'  => 'error',
                'error' => "target command '{$targetCommand}' not registered: " . $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES));
            return 1;
        }

        $params = ['command' => $targetCommand];
        foreach ($target->getDefinition()->getOptions() as $name => $option) {
            if (!$input->hasOption($name)) {
                continue;
            }
            $value = $input->getOption($name);
            if ($value === null || $value === false || $value === []) {
                continue;
            }
            $params["--{$name}"] = $value;
        }

        foreach ($target->getDefinition()->getArguments() as $name => $argument) {
            if ($name === 'command' || !$input->hasArgument($name)) {
                continue;
            }
            $value = $input->getArgument($name);
            if ($value === null || $value === []) {
                continue;
            }
            $params[$name] = $value;
        }

        $subInput = new ArrayInput($params);
        $subInput->setInteractive($input->isInteractive());

        // Suppress the legacy command's deprecation banner: the caller is
        // already on the new surface, so re-emitting the deprecation would
        // be noise.
        $prior = getenv(DeprecationBanner::SUPPRESS_ENV);
        putenv(DeprecationBanner::SUPPRESS_ENV . '=1');
        try {
            return $target->run($subInput, $output);
        } finally {
            if ($prior === false) {
                putenv(DeprecationBanner::SUPPRESS_ENV);
            } else {
                putenv(DeprecationBanner::SUPPRESS_ENV . '=' . $prior);
            }
        }
    }
}

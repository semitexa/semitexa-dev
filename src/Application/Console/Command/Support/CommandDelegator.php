<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command\Support;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Thin forward of an incoming Input+Output to another command, copying only
 * the options the target actually declares.
 *
 * Used by `ai:ask` to dispatch to the dev:graph:* family and logs:app from
 * a single subject argument.
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

        return $target->run($subInput, $output);
    }
}

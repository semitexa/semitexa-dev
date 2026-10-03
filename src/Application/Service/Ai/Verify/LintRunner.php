<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs one planned command gate — a lint, or a docs gate — and reports it as a
 * verification result.
 *
 * A lint states why it exists in a `RATIONALE` constant on its command class,
 * the same contract as a Semitexa PHPStan rule. When the lint fails, that
 * reason ends the signal and travels as a violation named after the lint — so
 * a failing lint is no longer a red result with an empty `violations[]` and a
 * last output line, but a rule with a name and a history.
 */
final class LintRunner
{
    public function __construct(
        private readonly Application $application,
    ) {}

    /**
     * @param bool $optional the command belongs to optional tooling (semitexa/docs):
     *                       its absence skips the gate instead of failing it
     */
    public function run(VerificationTarget $target, bool $optional = false): VerificationResult
    {
        $commandName = $target->commandName;
        if ($commandName === null) {
            return $this->incomplete($target, "{$target->type} target missing commandName");
        }
        try {
            $command = $this->application->find($commandName);
        } catch (CommandNotFoundException) {
            if ($optional) {
                return new VerificationResult(
                    target:   $target,
                    status:   VerificationResult::STATUS_SKIPPED,
                    exitCode: 0,
                    signal:   "semitexa/docs command {$commandName} unavailable; optional documentation tooling",
                    required: false,
                );
            }
            // A planned gate that does not exist is a defect in the plan, not a
            // reason to pass. Reporting it as skipped is how five lints named
            // with a stale prefix went unrun while ai:verify kept saying pass.
            return $this->incomplete($target, "command {$commandName} is not registered");
        }

        $buffer = new BufferedOutput();
        $input = new ArrayInput(['command' => $commandName] + $target->commandInput);
        $input->setInteractive(false);

        try {
            $exit = $command->run($input, $buffer);
        } catch (\Throwable $e) {
            // Same reasoning: a gate that blew up did not clear the change.
            return $this->incomplete(
                $target,
                "{$commandName} threw " . $e::class . ': ' . SignalText::compress($e->getMessage()),
            );
        }

        $signal = SignalText::lastLine($buffer->fetch());
        if ($exit === 0) {
            return new VerificationResult(target: $target, status: VerificationResult::STATUS_PASS, exitCode: 0, signal: $signal);
        }

        $why = self::rationaleOf($command);

        return new VerificationResult(
            target:      $target,
            status:      VerificationResult::STATUS_FAIL,
            exitCode:    $exit,
            signal:      $why === '' ? $signal : $signal . ' — ' . $why,
            diagnostics: [[
                'check'         => $target->type,
                'severity'      => 'error',
                'rule'          => $commandName,
                'identifier'    => $commandName,
                'path'          => '',
                'line'          => 0,
                'message'       => $signal,
                'rationale'     => $why,
                'suggested_fix' => "Run bin/semitexa {$commandName} for every finding, fix them, and re-run ai:verify.",
            ]],
        );
    }

    /** The lint's own RATIONALE, read from the class behind any lazy wrapper. */
    public static function rationaleOf(Command $command): string
    {
        if ($command instanceof LazyCommand) {
            $command = $command->getCommand();
        }
        $class = new \ReflectionClass($command);
        $why = $class->hasConstant('RATIONALE') ? $class->getConstant('RATIONALE') : null;

        return is_string($why) ? $why : '';
    }

    private function incomplete(VerificationTarget $target, string $reason): VerificationResult
    {
        return new VerificationResult(
            target:   $target,
            status:   VerificationResult::STATUS_INCOMPLETE,
            exitCode: 1,
            signal:   $reason,
        );
    }
}

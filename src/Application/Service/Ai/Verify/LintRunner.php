<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

use Composer\InstalledVersions;
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
    /**
     * Lints a sibling package registers, keyed by command, valued by package.
     * semitexa/dev does not require these packages, so a project without one
     * has no such command and none of its rules to break: the gate is skipped.
     * With the package installed a missing command is still a defect.
     */
    public const PACKAGE_LINTS = [
        'lint:access' => 'semitexa/authorization',
    ];

    /** @var \Closure(string): bool */
    private readonly \Closure $isInstalled;

    /**
     * @param (\Closure(string): bool)|null $isInstalled whether a Composer package is installed
     */
    public function __construct(
        private readonly Application $application,
        ?\Closure $isInstalled = null,
    ) {
        $this->isInstalled = $isInstalled ?? static fn (string $package): bool => InstalledVersions::isInstalled($package);
    }

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
            $provider = self::PACKAGE_LINTS[$commandName] ?? null;
            if ($provider !== null && !($this->isInstalled)($provider)) {
                return new VerificationResult(
                    target:   $target,
                    status:   VerificationResult::STATUS_SKIPPED,
                    exitCode: 0,
                    signal:   "{$commandName} unavailable; {$provider} is not installed, so its rules do not apply",
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

        $text = $buffer->fetch();
        $signal = SignalText::lastLine($text);
        if ($exit === 0) {
            return new VerificationResult(target: $target, status: VerificationResult::STATUS_PASS, exitCode: 0, signal: $signal);
        }

        $why = self::rationaleOf($command);
        // The command exactly as verify ran it: bare, docs:lint in a project
        // reads vendor/semitexa/docs and lists findings that are not the
        // agent's (Guard run 2026-11-08), so the fix pointed at the wrong pages.
        $omitted = [];
        $command = self::commandLine($commandName, $target->commandInput, $omitted);
        // The count stays outside the command, so what follows "Run" can be
        // pasted into a shell as it is.
        $fix = 'Run ' . $command
            . ($omitted === [] ? '' : ' (' . implode(', ', array_map(static fn (string $option, int $count): string => "{$count} more {$option} value(s) not shown", array_keys($omitted), $omitted)) . '; ai:verify checks them all)')
            . ' for every finding, fix them, and re-run ai:verify.';
        $base = [
            'check'         => $target->type,
            'severity'      => 'error',
            'rule'          => $commandName,
            'identifier'    => $commandName,
        ];
        // Read for the docs gates alone, whose every `page.md:line` row is a
        // finding. Other lints print located rows that are not errors —
        // lint:mechanisms lists "not installed here" remarks the same way — and
        // rows with no line at all; turning those into violations would send an
        // agent after what nobody can fix, the very chase this stops.
        $findings = $target->type === VerificationTarget::TYPE_DOCS ? self::findingsIn($text) : [];
        // Each finding where it is. One violation with path '' and line 0 told
        // an agent that it failed and not what to fix: it guessed, and removed
        // its own code until the gate went quiet.
        $diagnostics = $findings === []
            ? [$base + ['path' => '', 'line' => 0, 'message' => $signal, 'rationale' => $why, 'suggested_fix' => $fix]]
            : array_map(static fn (array $f): array => $base + [
                'path' => $f['path'],
                'line' => $f['line'],
                'message' => $f['text'] !== '' ? $f['text'] : $signal,
                'rationale' => $why,
                'suggested_fix' => $fix,
            ], $findings);
        $first = $findings === [] ? '' : " First: {$findings[0]['path']}:{$findings[0]['line']}" . ($findings[0]['text'] !== '' ? " {$findings[0]['text']}" : '') . '.';

        return new VerificationResult(
            target:      $target,
            status:      VerificationResult::STATUS_FAIL,
            exitCode:    $exit,
            signal:      $signal . $first . ($why === '' ? '' : ' — ' . $why),
            diagnostics: $diagnostics,
        );
    }

    /** How many located findings a failing lint reports, at most. */
    private const MAX_FINDINGS = 20;

    /** How many values of one option a suggested command spells out. */
    private const MAX_VALUES_SHOWN = 3;

    /**
     * Rows of a docs gate's output that start with `page.md:line`, as its
     * table of findings prints them in the "where" column.
     *
     * @return list<array{path: string, line: int, text: string}>
     */
    private static function findingsIn(string $text): array
    {
        $found = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $row) {
            if (preg_match('#^\s*(\S+?\.md):(\d+)(?:\s+(.*))?$#u', $row, $m) !== 1) {
                continue;
            }
            // An empty column prints as a lone dash (docs:lint's "did you mean").
            $cells = trim((string) preg_replace(['/\s{2,}/u', '/(?:\s+[—-])+\s*$/u'], [' ', ''], ' ' . ($m[3] ?? '')));
            $found[] = ['path' => $m[1], 'line' => (int) $m[2], 'text' => SignalText::compress($cells)];
            if (count($found) === self::MAX_FINDINGS) {
                break;
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, int> $omitted values left out per option, filled in
     */
    private static function commandLine(string $commandName, array $input, array &$omitted = []): string
    {
        $parts = ['bin/semitexa', $commandName];
        foreach ($input as $option => $value) {
            $values = is_array($value) ? array_values($value) : [$value];
            // A gate fed every changed file (lint:test-integrity's --path) would
            // otherwise print them all, once per violation.
            foreach (array_slice($values, 0, self::MAX_VALUES_SHOWN) as $one) {
                if ($one === true) {
                    $parts[] = (string) $option;
                } elseif ($one !== false && $one !== null && is_scalar($one)) {
                    $one = (string) $one;
                    $parts[] = $option . '=' . (preg_match('#^[\w./:@%+-]+$#', $one) === 1 ? $one : escapeshellarg($one));
                }
            }
            if (count($values) > self::MAX_VALUES_SHOWN) {
                $omitted[(string) $option] = count($values) - self::MAX_VALUES_SHOWN;
            }
        }

        return implode(' ', $parts);
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

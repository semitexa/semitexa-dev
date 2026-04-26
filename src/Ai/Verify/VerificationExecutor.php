<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Verify;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs every {@see VerificationTarget} in a {@see VerificationPlan} and
 * collects per-target outcomes. Three dispatch paths:
 *
 *   - lint    → Application::find()->run() with BufferedOutput
 *               (matches {@see \Semitexa\Dev\Generation\Verifier\PostWriteLinter})
 *   - syntax  → `php -l <file>`
 *   - phpunit → `vendor/bin/phpunit --filter <class>`
 *
 * The executor never throws on target failure — failures show up in the
 * returned {@see VerificationResult} list so the command can emit them as
 * NDJSON. Anything we genuinely can't run (missing command, missing phpunit
 * binary) is reported as `skipped` with a one-line reason.
 */
final class VerificationExecutor
{
    public function __construct(
        private readonly Application $application,
        private readonly string $projectRoot,
        private readonly ProcessRunner $processRunner = new ShellProcessRunner(),
        private readonly ?string $phpBinary = null,
        private readonly ?string $phpunitBinary = null,
    ) {}

    /**
     * @return list<VerificationResult>
     */
    public function execute(VerificationPlan $plan): array
    {
        $results = [];
        foreach ($plan->targets as $target) {
            $results[] = match ($target->type) {
                VerificationTarget::TYPE_LINT    => $this->runLint($target),
                VerificationTarget::TYPE_SYNTAX  => $this->runSyntax($target),
                VerificationTarget::TYPE_PHPUNIT => $this->runPhpunit($target),
                default                          => new VerificationResult(
                    target:   $target,
                    status:   VerificationResult::STATUS_SKIPPED,
                    exitCode: 0,
                    signal:   "unknown target type: {$target->type}",
                ),
            };
        }
        return $results;
    }

    private function runLint(VerificationTarget $target): VerificationResult
    {
        $commandName = $target->commandName;
        if ($commandName === null) {
            return $this->skipped($target, 'lint target missing commandName');
        }
        try {
            $command = $this->application->find($commandName);
        } catch (CommandNotFoundException) {
            return $this->skipped($target, "command {$commandName} is not registered");
        }

        $buffer = new BufferedOutput();
        $input = new ArrayInput(['command' => $commandName]);
        $input->setInteractive(false);

        try {
            $exit = $command->run($input, $buffer);
        } catch (\Throwable $e) {
            return $this->skipped(
                $target,
                "{$commandName} threw " . $e::class . ': ' . $this->compress($e->getMessage()),
            );
        }

        return new VerificationResult(
            target:   $target,
            status:   $exit === 0 ? VerificationResult::STATUS_PASS : VerificationResult::STATUS_FAIL,
            exitCode: $exit,
            signal:   $this->lastSignalLine($buffer->fetch()),
        );
    }

    private function runSyntax(VerificationTarget $target): VerificationResult
    {
        $rel = $target->filePath;
        if ($rel === null) {
            return $this->skipped($target, 'syntax target missing filePath');
        }
        $abs = $this->projectRoot . '/' . ltrim($rel, '/');
        if (!is_file($abs)) {
            return $this->skipped($target, "file no longer exists: {$rel}");
        }
        $php = $this->phpBinary ?? (PHP_BINARY ?: 'php');
        $r = $this->processRunner->run([$php, '-l', $abs], $this->projectRoot);

        return new VerificationResult(
            target:   $target,
            status:   $r['exit'] === 0 ? VerificationResult::STATUS_PASS : VerificationResult::STATUS_FAIL,
            exitCode: $r['exit'],
            signal:   $this->lastSignalLine($r['output']),
        );
    }

    private function runPhpunit(VerificationTarget $target): VerificationResult
    {
        $filter = $target->testFilter;
        if ($filter === null) {
            return $this->skipped($target, 'phpunit target missing testFilter');
        }
        $binary = $this->phpunitBinary ?? $this->discoverPhpunitBinary();
        if ($binary === null) {
            return $this->skipped($target, 'phpunit binary not found (looked for vendor/bin/phpunit)');
        }

        // Pass the test file positionally when the planner knows it. phpunit.xml
        // typically only includes `tests/`, so a bare `--filter` against a class
        // living under `packages/*/tests/` matches nothing → exit 0 + "No tests
        // executed!" → false-pass. The positional path forces phpunit to load
        // the file regardless of <testsuite> config.
        $command = [$binary, '--filter', $filter, '--no-output'];
        if ($target->filePath !== null && is_file($this->projectRoot . '/' . $target->filePath)) {
            $command[] = $target->filePath;
        }

        $r = $this->processRunner->run($command, $this->projectRoot);

        // phpunit exits 0 when filter+suite resolve to zero tests. Treat that as
        // a discovery failure rather than success — otherwise installer/scaffold
        // changes can silently report "pass" while the suite would actually fail.
        $noTestsExecuted = str_contains($r['output'], 'No tests executed!');

        $signal = $this->lastSignalLine($r['output']);
        if ($signal === '') {
            $signal = "phpunit --filter {$filter} → exit {$r['exit']}";
        }
        if ($noTestsExecuted) {
            $signal = "phpunit --filter {$filter} matched no tests (discovery gap, not a pass)";
        }

        $status = ($r['exit'] === 0 && ! $noTestsExecuted)
            ? VerificationResult::STATUS_PASS
            : VerificationResult::STATUS_FAIL;

        return new VerificationResult(
            target:   $target,
            status:   $status,
            exitCode: $r['exit'],
            signal:   $signal,
        );
    }

    private function discoverPhpunitBinary(): ?string
    {
        foreach (['/vendor/bin/phpunit', '/bin/phpunit'] as $candidate) {
            $abs = $this->projectRoot . $candidate;
            if (is_file($abs) && is_executable($abs)) {
                return $abs;
            }
        }
        return null;
    }

    private function skipped(VerificationTarget $target, string $reason): VerificationResult
    {
        return new VerificationResult(
            target:   $target,
            status:   VerificationResult::STATUS_SKIPPED,
            exitCode: 0,
            signal:   $reason,
        );
    }

    private function lastSignalLine(string $output): string
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim(preg_replace('/\[[a-zA-Z]+\]/', '', $lines[$i]) ?? '');
            if ($line === '') {
                continue;
            }
            return $this->compress($line);
        }
        return '';
    }

    private function compress(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return strlen($value) > 240 ? substr($value, 0, 237) . '...' : $value;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Verifier;

use Semitexa\Dev\Generation\Data\GenerationResult;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs the framework's lint commands after a successful make:* write so the
 * generation envelope carries a trustworthy "did the new code pass DI/handler
 * lint?" signal back to the caller (humans or LLMs).
 *
 * Lint failures do NOT fail the make:* command — the files were written
 * successfully; lint outcome is reported through the GenerationResult.lint
 * field and the operator decides what to do.
 */
final class PostWriteLinter
{
    private const CHECKS = [
        'handlers' => 'semitexa:lint:handlers',
        'di'       => 'semitexa:lint:di',
    ];

    public function __construct(
        private readonly ?Application $application,
    ) {}

    public function lintAfterWrite(GenerationResult $result): GenerationResult
    {
        if ($this->application === null) {
            return $result;
        }

        $createdPhp = array_filter(
            $result->created,
            static fn(string $rel): bool => str_ends_with($rel, '.php'),
        );
        if ($createdPhp === []) {
            return $result;
        }

        $checks = [];
        $aggregateStatus = 'pass';

        foreach (self::CHECKS as $key => $commandName) {
            $checks[$key] = $this->runCheck($commandName);
            if ($checks[$key]['status'] === 'fail') {
                $aggregateStatus = 'fail';
            } elseif ($checks[$key]['status'] === 'skipped' && $aggregateStatus !== 'fail') {
                $aggregateStatus = 'skipped';
            }
        }

        return $result->withLint([
            'status' => $aggregateStatus,
            'checks' => $checks,
        ]);
    }

    /**
     * @return array{status: string, summary: string}
     */
    private function runCheck(string $commandName): array
    {
        try {
            $command = $this->application->find($commandName);
        } catch (CommandNotFoundException) {
            return ['status' => 'skipped', 'summary' => "command {$commandName} is not registered"];
        }

        $buffer = new BufferedOutput();
        $input = new ArrayInput(['command' => $commandName]);
        $input->setInteractive(false);

        try {
            $exitCode = $command->run($input, $buffer);
        } catch (\Throwable $e) {
            return [
                'status'  => 'skipped',
                'summary' => "{$commandName} threw " . $e::class . ': ' . $this->compress($e->getMessage()),
            ];
        }

        return [
            'status'  => $exitCode === 0 ? 'pass' : 'fail',
            'summary' => $this->lastSignalLine($buffer->fetch()),
        ];
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

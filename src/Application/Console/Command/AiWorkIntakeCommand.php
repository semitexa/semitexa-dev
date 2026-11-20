<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Intake\AppLogSignals;
use Semitexa\Dev\Application\Service\Ai\Intake\RuntimeIntake;
use Semitexa\Dev\Application\Service\Ai\Work\WorkId;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the running project logged as errors, into the backlog as ideas
 * (tk-intake-from-runtime), so it develops against real use and not only
 * against conversation.
 *
 *   bin/semitexa ai:work:intake            what it would file: writes nothing
 *   bin/semitexa ai:work:intake --write    files the new signals, updates the counts
 *
 * One idea per signal, its id made from the signal, so running it again files
 * nothing twice. The evidence stays in the log on this machine: a task names
 * the class, the file and the route, never the exception's message.
 */
#[AsCommand(name: 'ai:work:intake', description: 'Errors the running project logged become backlog ideas, one per signal and linked to the log: dry-run by default, --write files them')]
final class AiWorkIntakeCommand extends BaseCommand
{
    private const ARTIFACT = 'semitexa.ai-work.intake/v1';

    private const DEFAULT_LIMIT = 10;

    #[InjectAsReadonly]
    protected RuntimeIntake $intake;

    public function __construct()
    {
        parent::__construct('ai:work:intake');
    }

    protected function configure(): void
    {
        $this
            ->addOption('write', null, InputOption::VALUE_NONE, 'File the new signals and update the counts (without it nothing is written)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many new ideas in one run, the loudest first', (string) self::DEFAULT_LIMIT)
            ->addOption('epic', null, InputOption::VALUE_REQUIRED, 'The epic the ideas go under (' . RuntimeIntake::EPIC . ' is made on the first --write; another must exist)', RuntimeIntake::EPIC)
            ->addOption('json', null, InputOption::VALUE_NONE, 'One JSON envelope (always; kept for the ai:* convention)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $write = (bool) $input->getOption('write');
        $limit = $input->getOption('limit');
        if (!is_string($limit) || !ctype_digit($limit) || (int) $limit < 1) {
            return $this->fail($output, '--limit takes a whole number of at least 1');
        }
        $epicId = $input->getOption('epic');
        $epicId = is_string($epicId) ? trim($epicId) : '';
        try {
            WorkId::assertValid($epicId, 'epic id');
        } catch (\InvalidArgumentException $e) {
            return $this->fail($output, $e->getMessage());
        }
        if ($epicId !== RuntimeIntake::EPIC && !$this->intake->epicExists($epicId)) {
            return $this->fail($output, "epic '{$epicId}' does not exist: run ai:epic start first, or leave --epic out for " . RuntimeIntake::EPIC);
        }

        $root = ProjectRoot::get();
        $path = AppLogSignals::locate($root);
        $mode = $write ? 'write' : 'dry-run';
        if ($path === null) {
            // No file to read is not a quiet project: where the log went is said.
            return $this->emit($output, [
                'mode' => $mode,
                'source' => ['sink' => 'stderr', 'file' => null],
                'note' => 'LOG_SINK=stderr: the application log goes to the container\'s log collector and no file is written, so there is nothing here to read. Set LOG_SINK=both to keep a file as well.',
            ]);
        }
        $log = self::relative($path, $root);
        if (!is_file($path)) {
            return $this->emit($output, [
                'mode' => $mode,
                'source' => ['sink' => 'file', 'file' => $log],
                'note' => "{$log} does not exist yet: the project has logged nothing to read.",
            ]);
        }

        try {
            $read = AppLogSignals::read($path, $root);
            $result = $this->intake->run($read['signals'], $write, (int) $limit, $epicId, $log, date('c'));
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return $this->fail($output, $e->getMessage());
        }

        return $this->emit($output, [
            'mode' => $mode,
            'source' => ['sink' => 'file', 'file' => $log],
            'read' => ['lines' => $read['lines'], 'errors' => $read['errors'], 'unreadable' => $read['unreadable']],
            'signals' => count($read['signals']),
            'epic' => $epicId,
            'new' => $result['new'],
            'seen_again' => $result['seen_again'],
            'kept' => $result['kept'],
            'closed' => $result['closed'],
            'unchanged' => $result['unchanged'],
            'over_limit' => $result['over_limit'],
            'epic_created' => $result['epic_created'],
            'skipped_environment' => $read['environment'],
            'next_command' => self::next($write, $result['new'] !== [] || $result['seen_again'] !== [], $result['over_limit'], $epicId),
        ]);
    }

    /** @return list<array{cmd: string, args: list<string>, why: string}> */
    private static function next(bool $write, bool $changes, int $overLimit, string $epicId): array
    {
        if (!$write) {
            return $changes ? [['cmd' => 'ai:work:intake', 'args' => ['--write', '--json'], 'why' => 'file the new signals as ideas and update the counts']] : [];
        }
        $next = [];
        if ($changes) {
            $next[] = ['cmd' => 'ai:work', 'args' => ['list', '--epic=' . $epicId, '--json'], 'why' => 'show the owner the ideas: approve the defects (ai:work:approve --id=<id>), discard the environment'];
        }
        if ($overLimit > 0) {
            $next[] = ['cmd' => 'ai:work:intake', 'args' => ['--write', '--json'], 'why' => "{$overLimit} more signals wait past --limit"];
        }

        return $next;
    }

    private static function relative(string $path, string $root): string
    {
        $prefix = rtrim($root, '/') . '/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    /** @param array<string, mixed> $payload */
    private function emit(OutputInterface $output, array $payload): int
    {
        $output->writeln(json_encode(['artifact' => self::ARTIFACT] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}', OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln(json_encode(['artifact' => self::ARTIFACT, 'status' => 'error', 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', OutputInterface::OUTPUT_RAW);

        return self::FAILURE;
    }
}

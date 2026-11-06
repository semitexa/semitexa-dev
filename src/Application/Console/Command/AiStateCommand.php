<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Environment;
use Semitexa\Dev\Application\Service\Ai\Decision\Expiry;
use Semitexa\Dev\Application\Service\Ai\State\BacklogProgress;
use Semitexa\Dev\Application\Service\Ai\State\FrameworkEvidence;
use Semitexa\Dev\Application\Service\Ai\State\FrameworkRubric;
use Semitexa\Dev\Application\Service\Ai\State\ScoreRecord;
use Semitexa\Dev\Application\Service\Ai\State\ScoreStore;
use Semitexa\Dev\Application\Service\Ai\State\StateReport;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The project's state: make it work · right · fast (ep-system-state).
 *
 *   bin/semitexa ai:state                        done over approved per phase, from the backlog, for the code in ./src
 *   bin/semitexa ai:state --with-framework       the same with the framework's tasks, and its rubric until those are tickets
 *   bin/semitexa ai:state score --criterion=right.test-quality --points=7 --evidence="ai:quality check: skip-calls 87"
 *
 * SEMITEXA_STATE_WITH_FRAMEWORK=1 in .env turns the option on for good.
 * A score counts until its expiry (30 days unless --expires says otherwise);
 * after that it is stale, shown as unknown and adds nothing until scored again.
 */
#[AsCommand(name: 'ai:state', description: 'The project state, work · right · fast: done over approved tasks per phase, for ./src, with the framework on request: show | score')]
final class AiStateCommand extends BaseCommand
{
    private const ARTIFACT = 'semitexa.ai-state/v1';
    private const DEFAULT_DAYS = 30;
    private const WITH_FRAMEWORK_ENV = 'SEMITEXA_STATE_WITH_FRAMEWORK';

    #[InjectAsReadonly]
    protected ScoreStore $scoreStore;

    #[InjectAsReadonly]
    protected TaskStore $taskStore;

    public function __construct()
    {
        parent::__construct('ai:state');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'show | score', 'show')
            ->addOption('with-framework', null, InputOption::VALUE_NONE, 'show: count the framework\'s code as well as ./src (or ' . self::WITH_FRAMEWORK_ENV . '=1)')
            ->addOption('criterion', null, InputOption::VALUE_REQUIRED, 'score: the criterion id, e.g. right.test-quality')
            ->addOption('points', null, InputOption::VALUE_REQUIRED, 'score: its points, 0 to its weight')
            ->addOption('evidence', null, InputOption::VALUE_REQUIRED, 'score: the command, PR or report that sets them')
            ->addOption('by', null, InputOption::VALUE_REQUIRED, 'score: who scored it (default: agent)')
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'score: until when it counts: a date YYYY-MM-DD (through that day, UTC) or an ISO-8601 time; default ' . self::DEFAULT_DAYS . ' days')
            ->addOption('json', null, InputOption::VALUE_NONE, 'One JSON envelope (the default when not interactive)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $action = $input->getArgument('action');
        $human = $input->isInteractive() && !(bool) $input->getOption('json');
        try {
            return match ($action) {
                'show' => $this->show($output, $now, $human, (bool) $input->getOption('with-framework') || filter_var(Environment::getEnvValue(self::WITH_FRAMEWORK_ENV, ''), FILTER_VALIDATE_BOOL)),
                'score' => $this->score($input, $output, $now),
                default => $this->fail($output, 'unknown action "' . (is_string($action) ? $action : '') . '" (expected show | score)'),
            };
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return $this->fail($output, $e->getMessage());
        }
    }

    private function show(OutputInterface $output, \DateTimeImmutable $now, bool $human, bool $withFramework): int
    {
        $progress = BacklogProgress::of($this->taskStore->list(), $withFramework);
        if (!$withFramework) {
            if ($human) {
                $output->writeln(OutputFormatter::escape($progress->line()));

                return self::SUCCESS;
            }

            return $this->emit($output, ['line' => $progress->line(), 'backlog' => $progress->toArray()]);
        }

        $report = StateReport::build(FrameworkRubric::rubric(), $this->scoreStore->all(), $now, FrameworkEvidence::forProject($this->getProjectRoot())->read(), FrameworkRubric::formulas());
        if (!$human) {
            return $this->emit($output, ['line' => $progress->line(), 'backlog' => $progress->toArray(), 'rubric_line' => StateReport::line($report)] + $report);
        }

        $output->writeln(OutputFormatter::escape($progress->line()));
        $output->writeln('');
        $output->writeln('Framework rubric: ' . StateReport::line($report));
        foreach ($report['phases'] as $phase => $data) {
            $output->writeln('');
            $output->writeln(sprintf('Make it %s: %s', $phase, $data['percent'] === null ? 'unknown' : $data['percent'] . '%'));
            foreach ($data['criteria'] as $c) {
                // Evidence is text someone typed: one line, no control bytes (an
                // ESC sequence would drive the terminal), and never console markup.
                $evidence = OutputFormatter::escape(self::oneLine((string) $c['evidence']));
                $output->writeln(sprintf(
                    '  %-34s %5s  %-8s %s',
                    $c['title'],
                    ($c['points'] ?? '?') . '/' . $c['weight'],
                    $c['status'],
                    match ($c['status']) {
                        'unscored' => '',
                        'computed' => 'by Semitexa, from: ' . $evidence,
                        default => sprintf('%dd ago, until %s UTC: %s', $c['age_days'], (new \DateTimeImmutable((string) $c['expires_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'), $evidence),
                    },
                ));
                if (is_string($c['reading']) && $c['status'] !== 'computed') {
                    $output->writeln(sprintf(
                        '  %34s now: %s%s',
                        '',
                        OutputFormatter::escape(self::oneLine($c['reading'])),
                        $c['reading_changed'] === true ? ' — changed since scored, the points need a look' : '',
                    ));
                }
            }
            foreach ($data['raise'] as $r) {
                $output->writeln(sprintf('  ↑ +%d: %s', $r['gap'], $r['title']));
            }
        }
        foreach ($report['orphans'] as $o) {
            $output->writeln(sprintf('⚠ %s: %d points not counted, %s (%s)', $o['criterion'], $o['points'], $o['why'], ScoreStore::PATH));
        }

        return self::SUCCESS;
    }

    private function score(InputInterface $input, OutputInterface $output, \DateTimeImmutable $now): int
    {
        $criterion = self::text($input, 'criterion');
        $points = self::text($input, 'points');
        $evidence = self::text($input, 'evidence');
        // Three digits: no weight is above 100, and a longer number would clamp to PHP_INT_MAX.
        if ($criterion === '' || preg_match('/^\d{1,3}$/', $points) !== 1 || $evidence === '') {
            return $this->fail($output, 'score needs --criterion, --points (a whole number, 0 to its weight) and --evidence (what sets them)');
        }
        if (isset(FrameworkRubric::formulas()[$criterion])) {
            return $this->fail($output, "{$criterion} is scored by Semitexa from its reading (" . FrameworkRubric::formulas()[$criterion]->text . '); change the evidence, not the points');
        }
        $expires = self::text($input, 'expires');
        $expiresAt = $expires === '' ? $now->modify('+' . self::DEFAULT_DAYS . ' days') : Expiry::parse($expires);
        if ($expiresAt === null) {
            return $this->fail($output, "--expires \"{$expires}\" is not a date");
        }
        if ($expiresAt <= $now) {
            return $this->fail($output, "--expires {$expires} is already past");
        }
        $by = self::text($input, 'by');

        // Stored on one line: a line break in evidence would print as a line of its own.
        $evidence = self::oneLine($evidence);
        if ($evidence === '') {
            return $this->fail($output, 'score needs --criterion, --points (a whole number, 0 to its weight) and --evidence (what sets them)');
        }
        // The evidence as it reads now goes with the points, so a later run can
        // say when it moved. A reading that failed is not kept: nothing to compare.
        $readings = FrameworkEvidence::forProject($this->getProjectRoot())->read();
        $reading = isset($readings[$criterion]) && $readings[$criterion]->failure === null ? $readings[$criterion]->values : null;
        $record = new ScoreRecord($criterion, (int) $points, $evidence, $by !== '' ? $by : 'agent', $now->format('c'), $expiresAt->format('c'), $reading);
        $this->scoreStore->record($record, FrameworkRubric::rubric());
        $report = StateReport::build(FrameworkRubric::rubric(), $this->scoreStore->all(), $now, $readings, FrameworkRubric::formulas());

        return $this->emit($output, ['status' => 'ok', 'recorded' => $record->toArray(), 'line' => StateReport::line($report)]);
    }

    /**
     * Whitespace collapsed to single spaces; control characters (ESC and the
     * rest of Cc) and the bidirectional overrides that can reorder a line on
     * screen dropped. Other format characters stay: a zero-width joiner is
     * part of an emoji or a script, not a terminal command.
     */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', (string) preg_replace('/\s+/u', ' ', $text)));
    }

    private static function text(InputInterface $input, string $option): string
    {
        $value = $input->getOption($option);

        return is_string($value) ? trim($value) : '';
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

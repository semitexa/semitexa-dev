<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Decision\Expiry;
use Semitexa\Dev\Application\Service\Ai\State\FrameworkRubric;
use Semitexa\Dev\Application\Service\Ai\State\ScoreRecord;
use Semitexa\Dev\Application\Service\Ai\State\ScoreStore;
use Semitexa\Dev\Application\Service\Ai\State\StateReport;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The project's state: make it work · right · fast (ep-system-state).
 *
 *   bin/semitexa ai:state                        the line, every criterion, what raises each phase most
 *   bin/semitexa ai:state score --criterion=right.test-quality --points=7 --evidence="ai:quality check: skip-calls 87"
 *
 * A score counts until its expiry (30 days unless --expires says otherwise);
 * after that it is stale, shown as unknown and adds nothing until scored again.
 */
#[AsCommand(name: 'ai:state', description: 'The project state, work · right · fast: every criterion with its points, evidence, age and expiry, and what would raise each phase most: show | score')]
final class AiStateCommand extends BaseCommand
{
    private const ARTIFACT = 'semitexa.ai-state/v1';
    private const DEFAULT_DAYS = 30;

    #[InjectAsReadonly]
    protected ScoreStore $scoreStore;

    public function __construct()
    {
        parent::__construct('ai:state');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'show | score', 'show')
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
                'show' => $this->show($output, $now, $human),
                'score' => $this->score($input, $output, $now),
                default => $this->fail($output, 'unknown action "' . (is_string($action) ? $action : '') . '" (expected show | score)'),
            };
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return $this->fail($output, $e->getMessage());
        }
    }

    private function show(OutputInterface $output, \DateTimeImmutable $now, bool $human): int
    {
        $report = StateReport::build(FrameworkRubric::rubric(), $this->scoreStore->all(), $now);
        if (!$human) {
            return $this->emit($output, ['line' => StateReport::line($report)] + $report);
        }

        $output->writeln(StateReport::line($report));
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
                    $c['status'] === 'unscored' ? '' : sprintf('%dd ago, until %s UTC: %s', $c['age_days'], (new \DateTimeImmutable((string) $c['expires_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'), $evidence),
                ));
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
        $record = new ScoreRecord($criterion, (int) $points, $evidence, $by !== '' ? $by : 'agent', $now->format('c'), $expiresAt->format('c'));
        $this->scoreStore->record($record, FrameworkRubric::rubric());
        $report = StateReport::build(FrameworkRubric::rubric(), $this->scoreStore->all(), $now);

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

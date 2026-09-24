<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Dev\Application\Service\Quality\QualityLedger;
use Semitexa\Dev\Application\Service\Quality\QualityMetricCatalog;
use Semitexa\Dev\Application\Service\Quality\Verdict;
use Semitexa\Dev\Attribute\AsQualityMetric;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The quality ledger: numbers about the codebase that may only go down.
 *
 *   ai:quality check                           compare every metric with its baseline
 *   ai:quality record                          lock in what improved (never raises)
 *   ai:quality accept --metric=ID --reason=…   raise one metric, deliberately
 *
 * `check` fails in both directions: a regression, and an improvement that has
 * not been recorded yet — unrecorded headroom is room the next change spends.
 */
#[AsCommand(name: 'ai:quality', description: 'Quality ledger: check metrics against their baseline, record improvements, accept a regression with a reason')]
final class AiQualityCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'check | record | accept', 'check')
            ->addOption('metric', null, InputOption::VALUE_REQUIRED, 'Metric id (accept)')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why the metric has to grow (accept)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Include release-tier metrics (slow)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit one JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ledger = new QualityLedger($this->getProjectRoot(), QualityMetricCatalog::discover($this->classDiscovery));
        $tier = $input->getOption('all') ? AsQualityMetric::TIER_RELEASE : AsQualityMetric::TIER_VERIFY;
        $action = (string) $input->getArgument('action');

        if (!$ledger->exists() && $action === 'check') {
            return $this->emit($output, $input, [
                'action' => 'check',
                'verdict' => 'skipped',
                'reason' => 'no quality ledger in this project (' . QualityLedger::BASELINE . ')',
            ], self::SUCCESS);
        }

        try {
            return match ($action) {
                'check' => $this->check($ledger, $tier, $input, $output),
                'record' => $this->record($ledger, $tier, $input, $output),
                'accept' => $this->accept($ledger, $input, $output),
                default => throw new \InvalidArgumentException("unknown action '{$action}' (expected check | record | accept)"),
            };
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->emit($output, $input, ['action' => $action, 'verdict' => 'error', 'error' => $e->getMessage()], self::FAILURE);
        }
    }

    private function check(QualityLedger $ledger, string $tier, InputInterface $input, OutputInterface $output): int
    {
        $verdicts = $ledger->check($tier);
        $failing = array_values(array_filter($verdicts, static fn (Verdict $v): bool => !$v->passes()));

        $next = [];
        $unrecorded = array_filter($failing, static fn (Verdict $v): bool => $v->status !== Verdict::WORSE);
        if ($unrecorded !== []) {
            $next[] = ['cmd' => 'ai:quality', 'args' => ['record', '--json'], 'why' => 'lock in what improved (and record new metrics) so it cannot be given back'];
        }
        foreach ($failing as $v) {
            if ($v->status === Verdict::WORSE) {
                $next[] = ['cmd' => 'ai:quality', 'args' => ['accept', '--metric=' . $v->metric, '--reason="<what grew and why it has to>"', '--json'], 'why' => "only if {$v->metric} {$v->from} -> {$v->to} cannot be fixed instead"];
            }
        }

        return $this->emit($output, $input, [
            'action' => 'check',
            'verdict' => $failing === [] ? 'pass' : 'fail',
            'metrics' => array_map(static fn (Verdict $v): array => (array) $v, $verdicts),
            'next_command' => $next,
        ], $failing === [] ? self::SUCCESS : self::FAILURE);
    }

    private function record(QualityLedger $ledger, string $tier, InputInterface $input, OutputInterface $output): int
    {
        $result = $ledger->record($tier);

        return $this->emit($output, $input, [
            'action' => 'record',
            'verdict' => $result['refused'] === [] ? 'pass' : 'fail',
            'recorded' => array_map(static fn (Verdict $v): array => (array) $v, $result['recorded']),
            'refused' => array_map(static fn (Verdict $v): array => (array) $v, $result['refused']),
            'note' => $result['refused'] === [] ? null : 'record never raises a metric; fix the regression or use accept --reason',
        ], $result['refused'] === [] ? self::SUCCESS : self::FAILURE);
    }

    private function accept(QualityLedger $ledger, InputInterface $input, OutputInterface $output): int
    {
        $verdict = $ledger->accept((string) $input->getOption('metric'), (string) $input->getOption('reason'));

        return $this->emit($output, $input, ['action' => 'accept', 'verdict' => 'pass', 'accepted' => (array) $verdict], self::SUCCESS);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function emit(OutputInterface $output, InputInterface $input, array $envelope, int $exit): int
    {
        $envelope = ['artifact' => 'semitexa-dev.ai-quality/v1'] + $envelope;
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $output->writeln(sprintf('ai:quality %s — %s', $envelope['action'], $envelope['verdict']));
        foreach (['metrics', 'recorded', 'refused'] as $list) {
            foreach ($envelope[$list] ?? [] as $v) {
                $output->writeln(sprintf('  %-7s %-28s %d -> %d%s', $v['status'], $v['metric'], $v['from'], $v['to'], $v['moved'] === [] ? '' : '  ' . implode(', ', array_map(
                    static fn (string $k, array $m): string => "{$k} {$m['from']}->{$m['to']}",
                    array_keys($v['moved']),
                    $v['moved'],
                ))));
            }
        }
        foreach (['error', 'reason', 'note'] as $key) {
            if (($envelope[$key] ?? null) !== null) {
                $output->writeln('  ' . $envelope[$key]);
            }
        }
        foreach ($envelope['next_command'] ?? [] as $n) {
            $output->writeln(sprintf('  next: %s %s — %s', $n['cmd'], implode(' ', $n['args']), $n['why']));
        }

        return $exit;
    }
}

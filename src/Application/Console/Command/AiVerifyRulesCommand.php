<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\RuleStats\RuleCatalog;
use Semitexa\Dev\Application\Service\Ai\Verify\RuleStats\RuleFireLedger;
use Semitexa\Dev\Application\Service\Ai\Verify\RuleStats\RuleFireReport;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Which ai:verify rules have fired, and which have had many chances and never
 * have. A rule set that only grows ends up with rules nobody can say are still
 * doing anything; this is the evidence for that conversation, read from every
 * run ai:verify recorded and every run a trace kept.
 */
#[AsCommand(name: 'ai:verify:rules', description: 'Show how often each ai:verify rule had a chance and fired; dormant rules first')]
final class AiVerifyRulesCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('ai:verify:rules');
    }

    protected function configure(): void
    {
        $this
            ->addOption('dormant', null, InputOption::VALUE_REQUIRED, 'Only rules that never fired, with at least this many chances (0: every never-fired rule)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit one JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->getProjectRoot();
        // Omitted: every rule. Given: a whole number, and only never-fired rules
        // with at least that many chances; anything else is refused, not ignored.
        $dormant = $input->getOption('dormant');
        $json = (bool) $input->getOption('json');
        if ($dormant !== null && (!is_string($dormant) || preg_match('/^\d+$/', $dormant) !== 1)) {
            return $this->error($output, $json, '--dormant takes a whole number of chances, e.g. --dormant=50', self::INVALID);
        }
        try {
            $report = RuleFireReport::build((new RuleCatalog($root))->rules(), (new RuleFireLedger($root))->runs());
        } catch (\RuntimeException $e) {
            // An audit missing a family would call live rules retired.
            return $this->error($output, $json, 'ai:verify:rules cannot list the rules: ' . $e->getMessage(), self::FAILURE);
        }
        $rules = $dormant === null ? $report['rules'] : array_values(array_filter(
            $report['rules'],
            static fn (array $r): bool => $r['fires'] === 0 && $r['chances'] >= (int) $dormant,
        ));

        if ($json) {
            $output->writeln((string) json_encode(['artifact' => 'semitexa-dev.verify-rules/v1', 'runs' => $report['runs'], 'since' => $report['since'], 'rules' => $rules, 'retired' => $report['retired']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $output->writeln(sprintf('%d ai:verify run(s) on record%s.', $report['runs'], $report['since'] !== null ? ' since ' . substr($report['since'], 0, 10) : ''));
        $output->writeln('');
        $output->writeln(sprintf('  %-48s %-16s %8s %6s  %s', 'rule', 'family', 'chances', 'fires', 'last fired'));
        foreach ($rules as $r) {
            $output->writeln(sprintf('  %-48s %-16s %8d %6d  %s', $r['rule'], $r['family'], $r['chances'], $r['fires'], $r['last_fired'] !== null ? substr($r['last_fired'], 0, 10) : '—'));
        }
        if ($report['retired'] !== []) {
            $output->writeln('');
            $output->writeln('Fired under a name no current rule has (renamed or removed): ' . implode(', ', array_map(static fn (string $rule, int $n): string => "{$rule} ×{$n}", array_keys($report['retired']), $report['retired'])));
        }
        $never = count(array_filter($report['rules'], static fn (array $r): bool => $r['fires'] === 0 && $r['chances'] > 0));
        $output->writeln('');
        $output->writeln(sprintf('%d of %d rules never fired in the runs that gave them a chance. Quiet is not proof of uselessness: a guard on rarely-changed code is quiet by design.', $never, count($report['rules'])));

        return self::SUCCESS;
    }

    /** In --json mode an error is JSON too: a caller parsing the output must not get prose. */
    private function error(OutputInterface $output, bool $json, string $message, int $exit): int
    {
        $output->writeln($json ? (string) json_encode(['artifact' => 'semitexa-dev.verify-rules/v1', 'error' => $message], JSON_UNESCAPED_SLASHES) : $message);

        return $exit;
    }
}

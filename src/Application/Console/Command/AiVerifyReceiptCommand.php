<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\VerifyReceipts;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Check a claim that the tests passed against the receipt of the run it came
 * from: the receipt is as written, the files it ran against are unchanged, and
 * its verdict was a pass. Exit 0 only when all three hold.
 *
 * An agent reporting "all tests pass" names the receipt ai:verify printed; a
 * reviewer runs this. A receipt for a tree that has moved on since is not a
 * pass for the tree in front of them.
 */
#[AsCommand(name: 'ai:verify:receipt', description: 'Check an ai:verify receipt: intact, tree unchanged since the run, verdict pass')]
final class AiVerifyReceiptCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('ai:verify:receipt');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'Receipt id (rcpt-...); default: the latest run')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit one JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');
        $check = (new VerifyReceipts($this->getProjectRoot()))->check(is_string($id) && $id !== '' ? $id : null);
        $holds = $check['found'] && $check['intact'] && $check['changed_since'] === [] && $check['verdict'] === 'pass';

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(['artifact' => 'semitexa-dev.verify-receipt-check/v1', 'holds' => $holds] + $check, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $holds ? self::SUCCESS : self::FAILURE;
        }

        if (!$check['found']) {
            $output->writeln(sprintf('No receipt %s under %s.', is_string($id) && $id !== '' ? $id : '(latest)', VerifyReceipts::DIR));

            return self::FAILURE;
        }
        $output->writeln(sprintf('%s — ai:verify %s at %s', $check['id'], $check['verdict'] ?? '?', $check['generated_at'] ?? '?'));
        $output->writeln('  receipt: ' . ($check['intact'] ? 'intact' : 'EDITED SINCE IT WAS WRITTEN (digest does not match)'));
        $output->writeln($check['changed_since'] === []
            ? '  tree:    every file the run checked is unchanged since'
            : sprintf('  tree:    %d file(s) changed since the run: %s', count($check['changed_since']), implode(', ', $check['changed_since'])));
        $output->writeln($holds
            ? 'The claim holds for the tree in front of you.'
            : 'The claim does not hold for the tree in front of you: re-run ai:verify.');

        return $holds ? self::SUCCESS : self::FAILURE;
    }
}

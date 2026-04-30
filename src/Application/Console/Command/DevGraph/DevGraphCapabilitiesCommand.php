<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command\DevGraph;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Capability\CapabilityRegistry;
use Semitexa\Dev\Generation\Data\CapabilityManifest;
use Semitexa\Dev\Generation\Support\CapabilityManifestFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'dev:graph:capabilities', description: 'List all available generator and introspection commands with inputs, outputs, and usage guidance')]
final class DevGraphCapabilitiesCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('dev:graph:capabilities');
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON manifest');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $capabilities = CapabilityRegistry::all();

        $manifest = new CapabilityManifest(
            artifact: 'semitexa.ai-capabilities/v1',
            generated_at: date('c'),
            commands: $capabilities,
        );

        if ($input->getOption('json')) {
            $formatter = new CapabilityManifestFormatter();
            $output->writeln($formatter->format($manifest));
            return self::SUCCESS;
        }

        $io->title('Semitexa Dev — Available Commands');
        foreach ($capabilities as $cap) {
            $io->section($cap->name);
            $io->text($cap->summary);
            $io->text("Kind: {$cap->kind}");
            $io->text("Use when: {$cap->use_when}");
            $io->text("Avoid when: {$cap->avoid_when}");

            if ($cap->required_inputs) {
                $io->text('Required inputs:');
                foreach ($cap->required_inputs as $name => $meta) {
                    $io->text("  --{$name} ({$meta['type']}): {$meta['description']}");
                }
            }

            if ($cap->optional_inputs) {
                $io->text('Optional inputs:');
                foreach ($cap->optional_inputs as $name => $meta) {
                    $io->text("  --{$name} ({$meta['type']}): {$meta['description']}");
                }
            }
        }

        return self::SUCCESS;
    }
}

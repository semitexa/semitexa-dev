<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command\DevGraph;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Capability\RuntimeCommandCatalog;
use Semitexa\Dev\Application\Service\Generation\Data\CapabilityManifest;
use Semitexa\Dev\Application\Service\Generation\Support\CapabilityManifestFormatter;
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
        $application = $this->getApplication();

        if ($application === null) {
            // Only reachable if someone runs this command outside a console
            // application. Saying so beats emitting an empty manifest that
            // reads as "this installation has no commands".
            $io->error('The command catalog is derived from the running console application, and there is none here.');

            return self::FAILURE;
        }

        $capabilities = (new RuntimeCommandCatalog())->all($application);

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

        // Grouped and compact. The list used to be eighteen hand-written
        // entries and printing each in full was reasonable; it is a hundred and
        // seventy-three derived ones now, and a wall of sections with empty
        // «Use when:» lines is less readable than no list at all. Inputs and
        // guidance are in --json, which is what an agent reads anyway.
        $byKind = [];
        foreach ($capabilities as $cap) {
            $byKind[$cap->kind][] = $cap;
        }
        ksort($byKind);

        $guided = 0;
        foreach ($byKind as $kind => $group) {
            $io->section($kind . ' (' . count($group) . ')');
            $rows = [];
            foreach ($group as $cap) {
                $guided += $cap->use_when !== '' ? 1 : 0;
                $rows[] = [$cap->name, self::clip($cap->summary, 86)];
            }
            $io->table([], $rows);
        }

        $io->text(sprintf(
            '%d command(s); %d carry written guidance. `--json` adds inputs, outputs and when NOT to use each.',
            count($capabilities),
            $guided,
        ));

        return self::SUCCESS;
    }

    /** A summary long enough to choose by, short enough to scan a hundred of. */
    private static function clip(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max - 1) . '…';
    }
}

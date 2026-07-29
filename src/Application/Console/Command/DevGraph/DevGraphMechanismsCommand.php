<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command\DevGraph;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Dev\Application\Service\Capability\FrameworkCapabilityCatalog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the framework can do for the thing you are building.
 *
 * Deliberately distinct from `dev:graph:capabilities`, which answers a different
 * question — what CLI commands exist to run. This one answers what mechanisms
 * the installed framework offers: deferred regions, components, live transport,
 * UI behaviors. Both are "what can I do here", and conflating them buries the
 * handful of mechanisms under a wall of command help.
 *
 * The catalog is DERIVED, never stored. It is assembled by reflecting over
 * `#[Capability]` declarations found through the composer classmap, which
 * includes `vendor/`. That is the whole point: a consumer project that never
 * declared any of this sees a capability added to the framework later from
 * `composer update` alone, with no edit to that project. A checked-in list
 * would freeze at scaffold time and teach a shrinking subset of the truth.
 */
#[AsCommand(
    name: 'dev:graph:mechanisms',
    description: 'List what the installed framework can do — deferred rendering, components, live transport, UI behaviors — with when to use and when not to.',
)]
final class DevGraphMechanismsCommand extends Command
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    public function __construct()
    {
        parent::__construct('dev:graph:mechanisms');
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Show one capability by id (e.g. ssr.deferred)')
            ->addOption('area', null, InputOption::VALUE_REQUIRED, 'Filter by area prefix (e.g. ssr, ui)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $capabilities = $this->discover();

        $area = $input->getOption('area');
        if (is_string($area) && $area !== '') {
            $capabilities = array_values(array_filter(
                $capabilities,
                static fn (array $c): bool => str_starts_with((string) $c['id'], rtrim($area, '.') . '.')
            ));
        }

        $id = $input->getOption('id');
        if (is_string($id) && $id !== '') {
            $capabilities = array_values(array_filter(
                $capabilities,
                static fn (array $c): bool => $c['id'] === $id
            ));

            if ($capabilities === []) {
                // Naming a missing id beats an empty list: the usual cause is a
                // package that is not installed here, not a typo.
                $output->writeln(sprintf(
                    '<comment>No capability with id "%s" among the installed packages.</comment>',
                    $id
                ));

                return Command::FAILURE;
            }
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode([
                'artifact' => 'semitexa.dev.mechanisms/v1',
                'count' => count($capabilities),
                'mechanisms' => $capabilities,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        if ($capabilities === []) {
            $output->writeln('<comment>No framework mechanisms declared by the installed packages.</comment>');

            return Command::SUCCESS;
        }

        $output->writeln('Framework Mechanisms');
        $output->writeln('====================');
        $output->writeln('');

        $currentArea = null;
        foreach ($capabilities as $c) {
            $capArea = (string) strstr((string) $c['id'], '.', true);
            if ($capArea !== $currentArea) {
                $currentArea = $capArea;
                $output->writeln(sprintf('<info>%s</info>', strtoupper($capArea)));
                $output->writeln('');
            }

            $output->writeln(sprintf('  <comment>%s</comment>  #[%s]', $c['id'], $c['attribute_short']));
            $output->writeln('    ' . $c['summary']);
            $output->writeln('    <info>Use when:</info>   ' . $c['use_when']);
            $output->writeln('    <info>Avoid when:</info> ' . $c['avoid_when']);
            foreach ((array) $c['replaces'] as $r) {
                $output->writeln('    <info>Instead of:</info> ' . $r);
            }
            if ($c['see_also'] !== '') {
                $output->writeln('    <info>See also:</info>   ' . $c['see_also']);
            }
            $output->writeln('    <info>Attribute:</info>  ' . $c['attribute']);
            $output->writeln('');
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<array{id: string, summary: string, use_when: string, avoid_when: string,
     *                    replaces: list<string>, see_also: string, attribute: string,
     *                    attribute_short: string, package: string}>
     */
    private function discover(): array
    {
        return (new FrameworkCapabilityCatalog($this->classDiscovery))->all();
    }

}

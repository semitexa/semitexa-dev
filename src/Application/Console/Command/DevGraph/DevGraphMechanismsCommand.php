<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command\DevGraph;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Capability\CapabilityIndex;
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
            ->addOption('state', null, InputOption::VALUE_REQUIRED, 'Filter: installed | available')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $everything = $this->discover();
        $capabilities = $everything;

        $state = $input->getOption('state');
        if (is_string($state) && $state !== '') {
            $capabilities = array_values(array_filter(
                $capabilities,
                static fn (array $c): bool => $c['state'] === $state
            ));
        }

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
                $output->writeln('<comment>' . self::missingIdMessage($everything, $id) . '</comment>');

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

            $this->renderCapability($output, $c);
            $output->writeln('');
        }

        return Command::SUCCESS;
    }

    /**
     * Say why an id came back empty, in the terms the reader needs next.
     *
     * The old wording — "among the installed packages" — understated the
     * search. Since the shipped index joined the catalog, a lookup covers both
     * what is installed here and what Semitexa offers but this project has
     * not required. Reporting only the installed half invites exactly the wrong
     * conclusion in the one case that matters: an agent told "not installed"
     * may assume the thing exists somewhere out of view and go build it by
     * hand, which is the outcome this whole catalog exists to prevent.
     *
     * The other half is the id that DOES exist and was excluded by `--state`.
     * "Not found" would be false there, and the useful answer is the state it
     * actually has.
     *
     * @param list<array<string, mixed>> $everything the merged catalog, before filters
     */
    private static function missingIdMessage(array $everything, string $id): string
    {
        foreach ($everything as $capability) {
            if ($capability['id'] === $id) {
                return sprintf(
                    'Capability "%s" exists but was filtered out: its state is "%s". Drop --state to see it.',
                    $id,
                    (string) $capability['state'],
                );
            }
        }

        return sprintf(
            'No capability with id "%s". Searched both the installed packages and the shipped index '
            . 'of what Semitexa offers but this project has not installed — it is in neither.',
            $id,
        );
    }

    /**
     * Render one capability.
     *
     * Extracted so the available branch is reachable from a test. Inline in the
     * loop it could only be exercised by a checkout missing a package — which
     * the monorepo never is, so the test asserting the wording rendered an
     * installed entry instead and held no matter what the not-installed line
     * said.
     *
     * @param array<string, mixed> $c
     */
    private function renderCapability(OutputInterface $output, array $c): void
    {
        // A mechanism is written into code as an attribute; a package
            // capability is not. Printing #[Capabilities] for the latter would
            // name something nobody can type.
            $marker = $c['state'] === 'installed' ? '' : '  <info>[available]</info>';
            $output->writeln(($c['kind'] === 'mechanism'
                ? sprintf('  <comment>%s</comment>  #[%s]', $c['id'], $c['declared_by_short'])
                : sprintf('  <comment>%s</comment>  %s', $c['id'], $c['package'])) . $marker);
            $output->writeln('    ' . $c['summary']);
            $output->writeln('    <info>Use when:</info>   ' . $c['use_when']);
            $output->writeln('    <info>Avoid when:</info> ' . $c['avoid_when']);
            foreach ((array) $c['replaces'] as $r) {
                $output->writeln('    <info>Instead of:</info> ' . $r);
            }
            if ($c['see_also'] !== '') {
                $output->writeln('    <info>See also:</info>   ' . $c['see_also']);
            }
            $output->writeln($c['kind'] === 'mechanism'
                ? '    <info>Attribute:</info>  ' . $c['declared_by']
                : '    <info>Package:</info>    ' . $c['package']);

            if ($c['state'] === 'available') {
                // Stated as a fact about the project, not as a step to take.
                // Adding a dependency to someone's application is their
                // decision; an agent that reads this must report it and stop,
                // not reach for composer.
                $output->writeln(sprintf(
                    '    <info>Not installed:</info> provided by %s, which this project does not require. '
                    . 'Whether to add it is the operator\'s call.',
                    $c['package'],
                ));
            }
    }

    /**
     * Everything Semitexa offers, marked by whether this project has it.
     *
     * The live catalog answers "what can I use today". On its own it cannot
     * answer the question that matters when a capability is missing — a project
     * without `semitexa/platform-ui` has no way to learn that a UI kit exists,
     * because nothing in its vendor directory mentions one. The shipped index
     * fills that gap: built in the monorepo where every package is present, and
     * carried inside `semitexa/dev`.
     *
     * Installed entries win on conflict. The live declaration is the truth for
     * anything present; the index is a snapshot and may be older.
     *
     * @return list<array<string, mixed>>
     */
    private function discover(): array
    {
        $live = (new FrameworkCapabilityCatalog($this->classDiscovery))->all();
        $index = CapabilityIndex::read(CapabilityIndex::path(ProjectRoot::get()));

        /** @var list<array<string, mixed>> $indexed */
        $indexed = array_values(array_filter(
            (array) ($index['capabilities'] ?? []),
            static fn (mixed $c): bool => is_array($c),
        ));

        return CapabilityIndex::merge($live, $indexed);
    }

}
<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Capability\CapabilityIndex;
use Semitexa\Dev\Application\Service\Capability\FrameworkCapabilityCatalog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes the capability index that ships to projects which cannot see it.
 *
 * The live catalog reflects over installed packages, which is exactly right for
 * a project asking what it can use today — and useless for the question this
 * index answers: what does Semitexa offer that this project has NOT installed?
 * `semitexa/platform-ui` is not in the default distribution, so a project
 * building an account area has no way to learn it exists.
 *
 * In the monorepo every package is on disk, so the same catalog sees all of
 * them. Running there once per release captures the whole picture into a file
 * that ships inside `semitexa/dev`, where a consumer already has it.
 *
 * Derived, never hand-written — a maintained list would drift, which is the
 * failure this entire line of work exists to avoid.
 */
#[AsCommand(
    name: 'dev:capability-index:build',
    description: 'Regenerate the shipped index of capabilities across every Semitexa package (run in the monorepo).',
)]
final class DevCapabilityIndexBuildCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    public function __construct()
    {
        parent::__construct('dev:capability-index:build');
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Verify the shipped index matches what would be generated; write nothing')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = ProjectRoot::get();
        $json = (bool) $input->getOption('json');
        $check = (bool) $input->getOption('check');

        $packagesOnDisk = CapabilityIndex::packagesOnDisk($root);
        if (count($packagesOnDisk) < CapabilityIndex::MIN_PACKAGES_FOR_A_FULL_VIEW) {
            $message = sprintf(
                'Refusing to build: found %d Semitexa packages on disk, which is not the full set. '
                . 'Building here would drop every capability this checkout has not installed. '
                . 'Run this in the monorepo.',
                count($packagesOnDisk),
            );

            $output->writeln($json
                ? (string) json_encode(['artifact' => CapabilityIndex::ARTIFACT, 'error' => $message], JSON_UNESCAPED_SLASHES)
                : '<error>[FAIL]</error> ' . $message);

            return Command::FAILURE;
        }

        // Everything on disk, not everything installed: this checkout requires
        // neither `theme-sky` nor `showcase-kit`, and an index built from the
        // classmap would omit the packages least likely to be installed
        // anywhere — which are the only ones it exists to advertise.
        $capabilities = (new FrameworkCapabilityCatalog($this->classDiscovery))->everythingOnDisk($root);
        $payload = CapabilityIndex::build($capabilities, $packagesOnDisk);
        $path = CapabilityIndex::path($root);

        if ($check) {
            $shipped = CapabilityIndex::read($path);

            $shippedCapabilities = is_array($shipped['capabilities'] ?? null) ? $shipped['capabilities'] : null;
            $matches = CapabilityIndex::isInSync($capabilities, $shipped);

            if ($json) {
                $output->writeln((string) json_encode([
                    'artifact' => CapabilityIndex::ARTIFACT,
                    'in_sync' => $matches,
                    'shipped_hash' => $shippedCapabilities === null
                        ? null
                        : CapabilityIndex::hash(array_values($shippedCapabilities)),
                    'claimed_hash' => $shipped['content_hash'] ?? null,
                    'expected_hash' => $payload['content_hash'],
                ], JSON_UNESCAPED_SLASHES));
            } else {
                $output->writeln($matches
                    ? '<info>[OK]</info> Capability index is current.'
                    : '<error>[FAIL]</error> Capability index is stale — run bin/semitexa dev:capability-index:build');
            }

            return $matches ? Command::SUCCESS : Command::FAILURE;
        }

        CapabilityIndex::write($path, $payload);

        if ($json) {
            $output->writeln((string) json_encode([
                'artifact' => CapabilityIndex::ARTIFACT,
                'written' => $path,
                'count' => $payload['count'],
                'packages' => count($payload['packages']),
            ], JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>[OK]</info> Wrote %d capabilities from %d packages to %s',
            $payload['count'],
            count($payload['packages']),
            $path,
        ));

        return Command::SUCCESS;
    }
}

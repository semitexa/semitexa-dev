<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Dev\Application\Service\Capability\FrameworkCapabilityCatalog;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Verify\Mechanism\HandRolledDeferredDetector;
use Semitexa\Dev\Application\Service\Ai\Verify\Mechanism\HandRolledLiveTransportDetector;
use Semitexa\Dev\Application\Service\Ai\Verify\Mechanism\InlineEventHandlerDetector;
use Semitexa\Dev\Application\Service\Ai\Verify\Mechanism\MechanismDetectorInterface;
use Semitexa\Dev\Application\Service\Ai\Verify\Mechanism\MechanismFinding;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports application code that hand-builds something the framework already
 * does, and names the mechanism it should have used.
 *
 * This is the half of the capability work that changes behaviour. A catalog
 * answers a question someone thought to ask; this speaks at the moment the
 * mistake is made, which is the only moment the advice is obviously relevant.
 *
 * The advice text is not written here. Each finding carries a capability id and
 * the wording is read from the `#[Capability]` declaration on the attribute
 * itself — so a mechanism is always described in its own current terms, and a
 * finding cannot go stale independently of the thing it recommends.
 *
 * Reporting discipline, which matters more than coverage: findings are
 * observations with `file:line` evidence, never guesses. The cost of a rule
 * that fires on plausible-looking code is not one wrong line — it is that the
 * whole channel gets ignored, including the findings that were right.
 */
#[AsCommand(
    name: 'lint:mechanisms',
    description: 'Report application code that hand-rolls a framework mechanism, naming the capability it should use.',
)]
final class LintMechanismsCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    /**
     * Where application UI code lives.
     *
     * Framework packages are excluded by design: they IMPLEMENT these
     * mechanisms, so the same pattern there is the implementation rather than a
     * duplicate of it.
     */
    private const APPLICATION_ROOTS = ['src/modules'];

    public function __construct()
    {
        parent::__construct('lint:mechanisms');
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Scan this directory instead of the application modules')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = ProjectRoot::get();

        $override = $input->getOption('path');
        $roots = is_string($override) && $override !== ''
            ? [$override]
            : self::APPLICATION_ROOTS;

        $findings = [];
        foreach ($roots as $relative) {
            $dir = str_starts_with($relative, '/') ? $relative : $root . '/' . $relative;
            foreach (self::detectors() as $detector) {
                foreach (self::filesWithExtensions($dir, $detector->extensions()) as $file) {
                    $contents = file_get_contents($file);
                    if ($contents === false) {
                        continue;
                    }
                    $lines = explode("\n", $contents);
                    foreach ($detector->detect(self::relativise($file, $root), $lines) as $finding) {
                        $findings[] = $finding;
                    }
                }
            }
        }

        usort(
            $findings,
            static fn (MechanismFinding $a, MechanismFinding $b): int
                => [$a->file, $a->line] <=> [$b->file, $b->line],
        );

        $catalog = $this->catalog();

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode([
                'artifact' => 'semitexa.dev.mechanism-lint/v1',
                'count' => count($findings),
                'findings' => array_map(
                    fn (MechanismFinding $f): array => self::describe($f, $catalog),
                    $findings,
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $findings === [] ? Command::SUCCESS : Command::FAILURE;
        }

        if ($findings === []) {
            $output->writeln('<info>[OK]</info> No hand-rolled framework mechanisms found.');

            return Command::SUCCESS;
        }

        foreach ($findings as $finding) {
            $described = self::describe($finding, $catalog);
            $output->writeln(sprintf('<comment>%s:%d</comment>', $finding->file, $finding->line));
            $output->writeln('  ' . $described['evidence']);
            $output->writeln(sprintf('  <info>Use instead:</info> %s  #[%s]', $finding->capabilityId, $described['declared_by_short']));
            if ($described['summary'] !== '') {
                $output->writeln('  ' . $described['summary']);
            }
            if ($described['avoid_when'] !== '') {
                $output->writeln('  <info>But not when:</info> ' . $described['avoid_when']);
            }
            $output->writeln(sprintf('  <info>Details:</info> bin/semitexa ai:ask mechanisms --id=%s', $finding->capabilityId));
            $output->writeln('');
        }

        $output->writeln(sprintf('<error>[FAIL]</error> %d hand-rolled mechanism(s).', count($findings)));

        return Command::FAILURE;
    }

    /**
     * @param array<string, array{summary: string, avoid_when: string, declared_by_short: string}> $catalog
     * @return array{file: string, line: int, capability: string, evidence: string, summary: string,
     *               avoid_when: string, declared_by_short: string, details_command: string}
     */
    private static function describe(MechanismFinding $finding, array $catalog): array
    {
        // A finding whose capability is not installed still reports: the
        // observation stands on its own, only the advice is unavailable.
        $entry = $catalog[$finding->capabilityId] ?? ['summary' => '', 'avoid_when' => '', 'declared_by_short' => ''];

        return [
            'file' => $finding->file,
            'line' => $finding->line,
            'capability' => $finding->capabilityId,
            'evidence' => $finding->evidence,
            'summary' => $entry['summary'],
            'avoid_when' => $entry['avoid_when'],
            'declared_by_short' => $entry['declared_by_short'],
            'details_command' => 'ai:ask mechanisms --id=' . $finding->capabilityId,
        ];
    }

    /** @return array<string, array{summary: string, avoid_when: string, declared_by_short: string}> */
    private function catalog(): array
    {
        // The advice half of a finding, not the finding itself. `describe()`
        // already reports what it saw when the catalog has nothing to add, so
        // a catalog that cannot be built must degrade to that rather than take
        // the whole lint down — the observations are the part somebody acts on.
        try {
            $entries = (new FrameworkCapabilityCatalog($this->classDiscovery))->all();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($entries as $entry) {
            $out[(string) $entry['id']] = [
                'summary' => (string) $entry['summary'],
                'avoid_when' => (string) $entry['avoid_when'],
                'declared_by_short' => (string) $entry['declared_by_short'],
            ];
        }

        return $out;
    }

    /**
     * The active detector set.
     *
     * @return list<MechanismDetectorInterface>
     */
    private static function detectors(): array
    {
        return [
            new HandRolledDeferredDetector(),
            new HandRolledLiveTransportDetector(),
            new InlineEventHandlerDetector(),
        ];
    }

    /**
     * @param non-empty-list<string> $extensions
     * @return list<string>
     */
    private static function filesWithExtensions(string $dir, array $extensions): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && in_array($entry->getExtension(), $extensions, true)) {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private static function relativise(string $file, string $root): string
    {
        return str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
    }
}

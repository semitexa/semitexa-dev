<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Console\InstallerManifestTools;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-shot propagation of the installer scaffold SSoT into every downstream
 * copy. Replaces the three-tool ritual (bin/sync-scaffold.sh +
 * scaffold:sync-docs + update:scaffold:rebuild) whose partial runs kept
 * shipping drift — a hand-copied scaffold file without a manifest rebuild
 * fails the release gate an hour later.
 *
 * Copies and their policies:
 *   1. installer scaffold → packages/semitexa-ultimate  (infra files, exact)
 *   2. installer scaffold → project root bin/semitexa and Dockerfile (exact).
 *      The root Dockerfile used to be report-only ("dev-workspace extras"), and
 *      by 2026-11-13 the workspace ran without the intl/bcmath, imagick pin and
 *      uid-1000 user the shipped image has, while its one extra, PCOV, had
 *      long been in the shipped image too (tk-dev-image-drifted-from-shipped).
 *   3. root *.md guidance → ultimate                     (via scaffold:sync-docs)
 *   4. installer scaffold → semitexa-update resources/scaffold + manifest
 *      (via update:scaffold:rebuild)
 *   5. semitexa/installer's own manifest, which update reads from vendor/ from
 *      step 2 of one source for bin/semitexa: checked by the installer's tools,
 *      and derived from update's while update still ships the runtime entries
 *      ({@see InstallerManifestTools})
 *
 * `--check` performs no writes and exits 1 on any drift — usable as a CI /
 * ai:verify gate. Only meaningful in the framework authoring workspace where
 * the installer package sits in packages/.
 */
#[AsCommand(name: 'scaffold:sync', description: 'Propagate the installer scaffold SSoT into every downstream copy and regenerate the shipped manifest')]
final class ScaffoldSyncCommand extends BaseCommand
{
    /** Scaffold-relative source → ultimate-relative target. */
    private const INFRA_FILES = [
        'bin/semitexa' => 'bin/semitexa',
        'Dockerfile' => 'Dockerfile',
        'docker-compose.yml' => 'docker-compose.yml',
        'docker-compose.ollama.yml' => 'docker-compose.ollama.yml',
        'docker-compose.worker.yml' => 'docker-compose.worker.yml',
        // NOTE: no '.gitignore' => 'gitignore' mapping — the non-dot copy in
        // ultimate was removed as orphan drift (no installer reads it; see
        // ultimate config/module-structure.php).
    ];

    /** Scaffold-relative files mirrored to the project root as exact copies. */
    private const ROOT_EXACT_FILES = ['bin/semitexa', 'Dockerfile'];

    protected function configure(): void
    {
        $this->addOption('check', null, InputOption::VALUE_NONE, 'Report drift without writing; exit 1 when any copy is stale.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $check = (bool) $input->getOption('check');
        $root = $this->getProjectRoot();
        $scaffold = $root . '/packages/semitexa-installer/scaffold';

        $io->title('Scaffold sync' . ($check ? ' (check)' : ''));

        if (!is_dir($scaffold)) {
            $io->error("Installer scaffold not found at {$scaffold} — this command runs in the framework authoring workspace only.");
            return Command::FAILURE;
        }

        $drift = 0;

        // 1. Infra files → ultimate, and exact root copies.
        $targets = [];
        foreach (self::INFRA_FILES as $src => $dst) {
            $targets[] = [$scaffold . '/' . $src, $root . '/packages/semitexa-ultimate/' . $dst, $src . ' → ultimate/' . $dst];
        }
        foreach (self::ROOT_EXACT_FILES as $src) {
            $targets[] = [$scaffold . '/' . $src, $root . '/' . $src, $src . ' → root/' . $src];
        }

        foreach ($targets as [$src, $dst, $label]) {
            if (!is_file($src)) {
                // A missing SSoT source is a broken scaffold, not a skippable
                // detail — downstream copies would silently stop updating.
                $drift++;
                if ($check) {
                    $io->writeln("  <comment>DRIFT</comment> {$label} (source missing from installer scaffold)");
                    continue;
                }
                $io->error("Installer scaffold is missing {$label} — fix the SSoT before syncing.");
                return Command::FAILURE;
            }
            // A match only between two hashes read: two unreadable files are not the same file,
            // and a gate that could not read one must count it (review, dev#249).
            $srcHash = hash_file('sha256', $src);
            if (is_string($srcHash) && is_file($dst) && $srcHash === hash_file('sha256', $dst)) {
                continue;
            }
            $drift++;
            if ($check) {
                $io->writeln("  <comment>DRIFT</comment> {$label}");
                continue;
            }
            @mkdir(dirname($dst), 0775, true);
            // Atomic replace: a plain copy() truncates the destination inode
            // in place — corrupting bin/semitexa for the very shell that is
            // running this command. rename() swaps the directory entry while
            // the running shell keeps its fd on the old inode.
            // The scaffold stores files non-executable (init applies the exec
            // bit), so preserve the destination's prior mode instead of
            // stamping the source's.
            $mode = is_file($dst) ? (fileperms($dst) & 0777) : (fileperms($src) & 0777);
            $tmp = $dst . '.scaffold-sync.' . getmypid() . '.' . uniqid() . '.tmp';
            if (!copy($src, $tmp) || !chmod($tmp, $mode) || !rename($tmp, $dst)) {
                @unlink($tmp);
                $io->error("Failed to copy {$label}.");
                return Command::FAILURE;
            }
            $io->writeln("  <info>synced</info> {$label}");
        }

        // 3 + 4. Delegate the docs mirror, and update's copy with its manifest.
        // In check mode update:scaffold:rebuild --check compares update's copy and
        // manifest with what a rebuild would write. That replaced a hash diff of
        // every installer file against update's copy: from step 2 of one source
        // for bin/semitexa update keeps only the agent docs, and the diff would
        // have called every runtime file missing.
        $manifestRegenerated = false;
        foreach ([
            ['scaffold:sync-docs', $check ? ['--check' => true] : []],
            ['update:scaffold:rebuild', $check ? ['--check' => true] : []],
        ] as [$name, $args]) {
            $exit = $this->runSibling($name, $args, $output, $io);
            if ($exit === null) {
                continue;
            }
            if ($exit !== Command::SUCCESS) {
                if (!$check) {
                    $io->error("Delegated step '{$name}' failed.");
                    return Command::FAILURE;
                }
                $drift++;
                continue;
            }
            if (!$check && $name === 'update:scaffold:rebuild') {
                $manifestRegenerated = true;
            }
        }

        // 5. semitexa/installer's own manifest, which update reads from vendor/.
        $installerManifest = new InstallerManifestTools($root);
        if ($check) {
            $drift += $this->checkInstallerManifest($installerManifest, $io);
        } elseif (!$this->writeInstallerManifest($installerManifest, $io)) {
            return Command::FAILURE;
        }

        if ($check) {
            if ($drift > 0) {
                $io->error(sprintf(
                    '%d scaffold %s out of sync. Run: bin/semitexa scaffold:sync',
                    $drift,
                    $drift === 1 ? 'copy is' : 'copies are',
                ));
                return Command::FAILURE;
            }
            $io->success('All scaffold copies match the installer SSoT.');
            return Command::SUCCESS;
        }

        $manifestNote = $manifestRegenerated
            ? ' and manifest regenerated'
            : ' (semitexa-update not installed — manifest NOT regenerated)';
        $io->success($drift > 0
            ? "Scaffold propagated ({$drift} file(s) refreshed){$manifestNote}."
            : "Everything already in sync{$manifestNote}.");
        return Command::SUCCESS;
    }

    /**
     * Runs the installer's manifest checks, the history against its last release tag.
     *
     * @return int the checks that did not pass
     */
    private function checkInstallerManifest(InstallerManifestTools $tools, SymfonyStyle $io): int
    {
        $previous = tempnam(sys_get_temp_dir(), 'installer-manifest-');
        $drift = 0;
        try {
            $tag = is_string($previous) ? $tools->writeManifestAtLastTag($previous) : null;
            foreach ($tools->commands(true, $tag !== null && is_string($previous) ? $previous : null) as $command) {
                [$exit, $text] = $tools->run($command['argv']);
                $against = $tag !== null && $command['label'] === 'check-manifest.php' ? " (history against {$tag})" : '';
                if ($exit === 0) {
                    $io->writeln("  <info>ok</info>     installer manifest: {$command['label']}{$against}");
                    continue;
                }
                $drift++;
                $io->writeln("  <comment>DRIFT</comment> installer manifest: {$command['label']}{$against} exited {$exit}");
                $io->writeln(preg_replace('/^/m', '         ', rtrim($text)) ?? rtrim($text));
            }
        } finally {
            if (is_string($previous)) {
                @unlink($previous);
            }
        }

        return $drift;
    }

    /** While update ships the runtime entries, copies them into the installer's manifest after update's rebuild. */
    private function writeInstallerManifest(InstallerManifestTools $tools, SymfonyStyle $io): bool
    {
        foreach ($tools->commands(false) as $command) {
            [$exit, $text] = $tools->run($command['argv']);
            if ($exit !== 0) {
                $io->error("installer manifest: {$command['label']} exited {$exit}:\n" . rtrim($text));
                return false;
            }
            $io->writeln("  <info>synced</info> installer manifest ({$command['label']})");
        }

        return true;
    }

    /**
     * @param array<string, mixed> $args
     * @return int|null null when the sibling command is unavailable (package not installed)
     */
    private function runSibling(string $name, array $args, OutputInterface $output, SymfonyStyle $io): ?int
    {
        $app = $this->getApplication();
        if ($app === null) {
            return null;
        }
        try {
            $command = $app->find($name);
        } catch (CommandNotFoundException) {
            $io->writeln("  <comment>skip</comment>  {$name} (not installed)");
            return null;
        }
        $io->section($name);

        return $command->run(new ArrayInput($args), $output);
    }
}

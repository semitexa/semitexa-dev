<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
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
 *   2. installer scaffold → project root bin/semitexa   (exact; the root
 *      Dockerfile intentionally diverges — dev-workspace extras — and is
 *      only reported, never overwritten)
 *   3. root *.md guidance → ultimate                     (via scaffold:sync-docs)
 *   4. installer scaffold → semitexa-update resources/scaffold + manifest
 *      (via update:scaffold:rebuild)
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
        '.gitignore' => 'gitignore',
    ];

    /** Scaffold-relative files mirrored to the project root as exact copies. */
    private const ROOT_EXACT_FILES = ['bin/semitexa'];

    /** Root files that legitimately diverge from the scaffold — report only. */
    private const ROOT_ADVISORY_FILES = ['Dockerfile'];

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
                $io->writeln("  <comment>skip</comment>  {$label} (source missing)");
                continue;
            }
            if (is_file($dst) && hash_file('sha256', $src) === hash_file('sha256', $dst)) {
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
            $tmp = $dst . '.scaffold-sync.tmp';
            if (!copy($src, $tmp) || !chmod($tmp, $mode) || !rename($tmp, $dst)) {
                @unlink($tmp);
                $io->error("Failed to copy {$label}.");
                return Command::FAILURE;
            }
            $io->writeln("  <info>synced</info> {$label}");
        }

        // 2. Root advisory diffs — divergent by design, never overwritten.
        foreach (self::ROOT_ADVISORY_FILES as $src) {
            $rootCopy = $root . '/' . $src;
            if (is_file($rootCopy) && is_file($scaffold . '/' . $src)
                && hash_file('sha256', $scaffold . '/' . $src) !== hash_file('sha256', $rootCopy)) {
                $io->writeln("  <comment>note</comment>  root/{$src} differs from the scaffold (expected: dev-workspace extras) — reconcile intentional changes by hand");
            }
        }

        // 3 + 4. Delegate docs mirror and update-package mirror + manifest.
        foreach ([
            ['scaffold:sync-docs', $check ? ['--check' => true] : []],
            ['update:scaffold:rebuild', $check ? null : []],
        ] as [$name, $args]) {
            if ($args === null) {
                // update:scaffold:rebuild has no check mode; drift for its copy
                // is covered by the file diff below.
                continue;
            }
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
            }
        }

        // In check mode, compare the update package's scaffold mirror by hash.
        if ($check) {
            $updateScaffold = $root . '/packages/semitexa-update/resources/scaffold';
            foreach ($this->listFiles($scaffold) as $rel) {
                $mirror = $updateScaffold . '/' . $rel;
                if (!is_file($mirror) || hash_file('sha256', $scaffold . '/' . $rel) !== hash_file('sha256', $mirror)) {
                    $io->writeln("  <comment>DRIFT</comment> {$rel} → semitexa-update mirror (run scaffold:sync)");
                    $drift++;
                }
            }
        }

        if ($check) {
            if ($drift > 0) {
                $io->error("{$drift} scaffold cop(ies) out of sync. Run: bin/semitexa scaffold:sync");
                return Command::FAILURE;
            }
            $io->success('All scaffold copies match the installer SSoT.');
            return Command::SUCCESS;
        }

        $io->success($drift > 0 ? "Scaffold propagated ({$drift} file(s) refreshed) and manifest regenerated." : 'Everything already in sync; manifest regenerated.');
        return Command::SUCCESS;
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

    /**
     * @return list<string> scaffold-relative file paths
     */
    private function listFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $files[] = substr($entry->getPathname(), strlen($dir) + 1);
            }
        }
        sort($files);

        return $files;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mirrors the project root's AI-guidance .md files into the scaffold package
 * (packages/semitexa-ultimate/). Run this before tagging a new release of
 * semitexa/ultimate so new projects created via `bin/semitexa init` get the
 * current guidance.
 *
 * Source of truth: project root.
 * Target: packages/semitexa-ultimate/.
 *
 * Ignored (divergent by design):
 *   - AI_NOTES.md        — per-project personal notes, never overwritten by the
 *                          framework (the scaffold holds a short placeholder).
 *
 * Companion to `bin/sync-scaffold.sh`, which handles infrastructure files
 * (Dockerfile, docker-compose*, bin/semitexa, .gitignore) from
 * packages/semitexa-installer/scaffold/. The two syncs have different sources
 * and stay separate commands.
 */
#[AsCommand(
    name: 'scaffold:sync-docs',
    description: 'Mirror root AI-guidance .md files into packages/semitexa-ultimate/ (run before releasing semitexa/ultimate).',
)]
final class ScaffoldSyncDocsCommand extends BaseCommand
{
    /**
     * AI-guidance files mirrored root → ultimate. Must match byte-for-byte.
     *
     * @var list<string>
     */
    private const MIRRORED_FILES = [
        'AGENTS.md',
        'AGENTS_DOCTRINE.md',
        'AI_ENTRY.md',
        'AI_REFERENCE.md',
        'AI_CONTEXT.md',
        'CLAUDE.md',
        'README.md',
    ];

    /**
     * Files that intentionally diverge or are monorepo-only. Listed here for
     * documentation / error reporting; they are never read from or written to.
     *
     * @var list<string>
     */
    private const IGNORED_FILES = [
        'AI_NOTES.md',
    ];

    private const SCAFFOLD_DIR = 'packages/semitexa-ultimate';

    /**
     * Substring that must appear near the top of every mirrored source file.
     * Uniquely identifies the scaffold-source note added by this command's
     * documented convention — the note is the signal to a reading agent that
     * the file is framework-owned and will be overwritten during `init`.
     *
     * Chosen to be specific enough that no plain body content would match it
     * (the only other place the string appears is this command's own source).
     */
    private const SCAFFOLD_NOTE_FINGERPRINT = 'bin/semitexa scaffold:sync-docs';

    /** How many leading lines of a source file we inspect for the note. */
    private const NOTE_SEARCH_LINE_LIMIT = 15;

    public function __construct()
    {
        parent::__construct('scaffold:sync-docs');
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Exit 1 if any mirrored file is out of sync. Does not write.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a JSON envelope instead of human-readable output.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $checkOnly = (bool) $input->getOption('check');
        $asJson    = (bool) $input->getOption('json');

        $root = $this->resolveRoot();
        if ($root === null) {
            return $this->fail($input, $output, 'Could not locate project root (must contain ' . self::SCAFFOLD_DIR . ').', $asJson);
        }

        $scaffold = $root . '/' . self::SCAFFOLD_DIR;

        $synced      = [];
        $drift       = [];
        $missing     = [];
        $missingNote = [];
        $inSync      = [];

        foreach (self::MIRRORED_FILES as $relative) {
            $src = $root . '/' . $relative;
            $dst = $scaffold . '/' . $relative;

            if (!is_file($src)) {
                $missing[] = $relative;
                continue;
            }

            // Hard-gate on the scaffold-source note. A source file missing the
            // note is a documentation defect: agents reading the mirrored copy
            // would not see that the file is framework-owned and will be
            // overwritten on init. We refuse to propagate the file until the
            // note is restored at source.
            if (!$this->hasScaffoldNote($src)) {
                $missingNote[] = $relative;
                continue;
            }

            if (is_file($dst) && $this->filesIdentical($src, $dst)) {
                $inSync[] = $relative;
                continue;
            }

            $drift[] = $relative;

            if (!$checkOnly) {
                if (!$this->copyFile($src, $dst)) {
                    return $this->fail($input, $output, 'Failed to write: ' . $relative, $asJson);
                }
                $synced[] = $relative;
            }
        }

        $envelope = [
            'artifact'     => 'semitexa.scaffold-sync-docs/v1',
            'mode'         => $checkOnly ? 'check' : 'sync',
            'root'         => $root,
            'scaffold'     => $scaffold,
            'in_sync'      => $inSync,
            'drift'        => $drift,
            'synced'       => $synced,
            'missing_at_root' => $missing,
            'missing_note' => $missingNote,
            'note_fingerprint' => self::SCAFFOLD_NOTE_FINGERPRINT,
            'ignored'      => self::IGNORED_FILES,
        ];

        $exitCode = self::SUCCESS;
        if ($missing !== [] || $missingNote !== []) {
            $exitCode = self::FAILURE;
        } elseif ($checkOnly && $drift !== []) {
            $exitCode = self::FAILURE;
        }

        if ($asJson || !$input->isInteractive()) {
            $output->writeln(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        } else {
            $this->renderHuman(new SymfonyStyle($input, $output), $envelope, $checkOnly);
        }

        return $exitCode;
    }

    private function resolveRoot(): ?string
    {
        $candidate = getcwd();
        if ($candidate === false) {
            return null;
        }

        // Walk up until we find a dir containing packages/semitexa-ultimate.
        $dir = $candidate;
        for ($i = 0; $i < 6; $i++) {
            if (is_dir($dir . '/' . self::SCAFFOLD_DIR)) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * True when the file contains the scaffold-source note fingerprint inside
     * the first NOTE_SEARCH_LINE_LIMIT lines. Reads only the head of the file.
     */
    private function hasScaffoldNote(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $found = false;
        try {
            for ($i = 0; $i < self::NOTE_SEARCH_LINE_LIMIT; $i++) {
                $line = fgets($handle);
                if ($line === false) {
                    break;
                }
                if (str_contains($line, self::SCAFFOLD_NOTE_FINGERPRINT)) {
                    $found = true;
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        return $found;
    }

    private function filesIdentical(string $a, string $b): bool
    {
        $sizeA = @filesize($a);
        $sizeB = @filesize($b);
        if ($sizeA === false || $sizeB === false || $sizeA !== $sizeB) {
            return false;
        }
        return @hash_file('sha256', $a) === @hash_file('sha256', $b);
    }

    private function copyFile(string $src, string $dst): bool
    {
        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }
        return @copy($src, $dst);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function renderHuman(SymfonyStyle $io, array $envelope, bool $checkOnly): void
    {
        $io->title($checkOnly ? 'Scaffold docs — drift check' : 'Scaffold docs — sync');
        $io->text('root:     ' . $envelope['root']);
        $io->text('scaffold: ' . $envelope['scaffold']);

        if ($envelope['missing_at_root'] !== []) {
            $io->error('Missing at root (cannot sync): ' . implode(', ', $envelope['missing_at_root']));
        }

        if ($envelope['missing_note'] !== []) {
            $io->error(
                'Source files missing scaffold-source note (add the `' . self::SCAFFOLD_NOTE_FINGERPRINT
                . '` note to the top, then re-run): ' . implode(', ', $envelope['missing_note'])
            );
        }

        if ($checkOnly) {
            if ($envelope['drift'] === []) {
                $io->success('All mirrored docs are in sync.');
            } else {
                $io->warning('Out of sync (' . count($envelope['drift']) . '):');
                foreach ($envelope['drift'] as $f) {
                    $io->text('  DRIFT: ' . $f);
                }
                $io->text('Run `bin/semitexa scaffold:sync-docs` to fix.');
            }
        } else {
            if ($envelope['synced'] === [] && $envelope['drift'] === []) {
                $io->success('All mirrored docs were already in sync.');
            } else {
                foreach ($envelope['synced'] as $f) {
                    $io->text('SYNCED: ' . $f);
                }
                $io->success(count($envelope['synced']) . ' file(s) synced from root to ' . self::SCAFFOLD_DIR . '.');
            }
        }

        if ($envelope['ignored'] !== []) {
            $io->note('Intentionally ignored: ' . implode(', ', $envelope['ignored']));
        }
    }

    private function fail(InputInterface $input, OutputInterface $output, string $message, bool $asJson): int
    {
        if ($asJson || !$input->isInteractive()) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa.scaffold-sync-docs/v1',
                'error'    => $message,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        } else {
            (new SymfonyStyle($input, $output))->error($message);
        }
        return self::FAILURE;
    }
}

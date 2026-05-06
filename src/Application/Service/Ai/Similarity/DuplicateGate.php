<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Similarity;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Thin adapter between scaffolders and {@see DuplicateDetector}.
 *
 * Semantics:
 *   - Returns `null` when the command should continue (no findings, or
 *     only warnings, or override supplied for block findings).
 *   - Returns an integer exit code (`1`) when the command should refuse.
 *
 * All rendering (plain console, --json, --llm-hints) is centralised here so
 * every make:* gets the same refusal envelope — crucial for agents parsing
 * the output.
 */
final class DuplicateGate
{
    public const REFUSAL_ARTIFACT = 'semitexa-dev.duplicate-refusal/v1';

    /**
     * @return int|null Exit code to return (command aborts), or null to continue.
     */
    public function run(
        DuplicateQuery $query,
        DuplicateDetector $detector,
        SymfonyStyle $io,
        OutputInterface $output,
        bool $override,
        bool $jsonOutput,
        bool $llmHintsOutput,
    ): ?int {
        $findings = $detector->check($query);
        if ($findings === []) {
            return null;
        }

        [$blocking, $warnings] = $this->partition($findings);

        if ($warnings !== []) {
            $this->emitWarnings($warnings, $io, $output, $jsonOutput, $llmHintsOutput);
        }

        if ($blocking === []) {
            return null;
        }

        if ($override) {
            if (!$jsonOutput && !$llmHintsOutput) {
                $io->warning('Override active — proceeding despite ' . count($blocking) . ' blocking finding(s).');
            }
            return null;
        }

        $this->emitRefusal($blocking, $query, $io, $output, $jsonOutput, $llmHintsOutput);
        return 1;
    }

    /**
     * @param list<SimilarityFinding> $findings
     * @return array{0: list<SimilarityFinding>, 1: list<SimilarityFinding>}
     */
    private function partition(array $findings): array
    {
        $block = [];
        $warn = [];
        foreach ($findings as $f) {
            if ($f->severity === SimilarityFinding::SEVERITY_BLOCK) {
                $block[] = $f;
            } else {
                $warn[] = $f;
            }
        }
        return [$block, $warn];
    }

    /**
     * @param list<SimilarityFinding> $warnings
     */
    private function emitWarnings(
        array $warnings,
        SymfonyStyle $io,
        OutputInterface $output,
        bool $jsonOutput,
        bool $llmHintsOutput,
    ): void {
        if ($jsonOutput || $llmHintsOutput) {
            return;
        }
        foreach ($warnings as $f) {
            $io->warning("[{$f->rule}] {$f->message} — prior art: {$f->priorArtPath}");
        }
    }

    /**
     * @param list<SimilarityFinding> $blocking
     */
    private function emitRefusal(
        array $blocking,
        DuplicateQuery $query,
        SymfonyStyle $io,
        OutputInterface $output,
        bool $jsonOutput,
        bool $llmHintsOutput,
    ): void {
        if ($jsonOutput || $llmHintsOutput) {
            $output->writeln($this->renderEnvelope($blocking, $query, $llmHintsOutput));
            return;
        }

        $io->error('Refusing to scaffold — ' . count($blocking) . ' blocking duplicate(s) found.');
        foreach ($blocking as $f) {
            $io->writeln("  • [{$f->rule}] {$f->message}");
            $io->writeln("    prior art: {$f->priorArtPath}");
        }
        $io->writeln('');
        $io->writeln('Pass --override-duplicate to proceed anyway (double-check before overriding).');
    }

    /**
     * @param list<SimilarityFinding> $blocking
     */
    private function renderEnvelope(array $blocking, DuplicateQuery $query, bool $asLlmHints): string
    {
        $body = [
            'artifact'     => self::REFUSAL_ARTIFACT,
            'generated_at' => date('c'),
            'status'       => 'refused',
            'reason'       => 'duplicate_detected',
            'kind'         => $query->kind,
            'module'       => $query->module,
            'proposed'     => [
                'className' => $query->className,
                'fqcn'      => $query->fqcn,
                'path'      => $query->relativePath,
            ],
            'findings'     => array_map(static fn(SimilarityFinding $f) => $f->toArray(), $blocking),
            'override_flag' => '--override-duplicate',
        ];
        if ($asLlmHints) {
            $body['hint_type'] = 'duplicate_refusal';
            $body['constraints'] = [
                'Do not re-run with --override-duplicate unless the user has confirmed the duplicate is intentional.',
                'Inspect each prior_art_path and decide whether to edit the existing artifact instead of creating a new one.',
            ];
        }
        return json_encode($body, JSON_UNESCAPED_SLASHES);
    }
}

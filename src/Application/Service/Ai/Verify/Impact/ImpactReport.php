<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Impact;

/**
 * Aggregate blast-radius reading for a whole diff.
 *
 * When `stale` is true the report is fail-closed: `maxBand()` is "unknown" and
 * every file carries the "unknown" band. A graph that was never generated, or
 * that predates a changed file, must never read as low-risk.
 */
final readonly class ImpactReport
{
    private const RANK = ['low' => 1, 'medium' => 2, 'high' => 3];

    /**
     * @param list<FileImpact> $files
     */
    private function __construct(
        public array $files,
        public bool $stale,
        public ?string $note,
    ) {
    }

    public static function empty(): self
    {
        return new self([], false, null);
    }

    /**
     * @param list<FileImpact> $files
     */
    public static function of(array $files): self
    {
        return new self($files, false, null);
    }

    /**
     * Graph predates a changed file — numbers are computed but untrustworthy.
     *
     * @param list<FileImpact> $files
     */
    public static function behind(array $files): self
    {
        return new self(
            $files,
            true,
            'graph predates a changed file — run ai:review-graph:generate for an accurate reading',
        );
    }

    /**
     * No usable graph at all — no per-file band can be computed.
     *
     * @param list<string> $paths
     */
    public static function stale(array $paths, string $note): self
    {
        $files = array_map(static fn (string $p): FileImpact => FileImpact::unknown($p), $paths);

        return new self(array_values($files), true, $note);
    }

    public function maxBand(): string
    {
        if ($this->stale) {
            return 'unknown';
        }

        $best = 'low';
        $rank = 0;
        foreach ($this->files as $file) {
            $r = self::RANK[$file->band] ?? 0;
            if ($r > $rank) {
                $rank = $r;
                $best = $file->band;
            }
        }

        return $best;
    }

    public function hottest(): ?FileImpact
    {
        $best = null;
        $rank = -1;
        foreach ($this->files as $file) {
            $r = self::RANK[$file->band] ?? 0;
            if ($r > $rank) {
                $rank = $r;
                $best = $file;
            }
        }

        return $best;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        $hottest = $this->hottest();

        return [
            'max'              => $this->maxBand(),
            'stale'            => $this->stale,
            'hottest'          => $hottest?->path,
            'dependents'       => $hottest?->dependents ?? 0,
            'modules_affected' => $hottest?->modulesAffected ?? 0,
            'note'             => $this->note,
        ];
    }
}

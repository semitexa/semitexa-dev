<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

/**
 * A contiguous run of source lines: one method, or one whole class.
 *
 * A typed record rather than an array shape, so a consumer that renders it
 * cannot drift from a consumer that serialises it — both read the same
 * properties, and a missing one is a fatal, not a silent empty string.
 */
final readonly class SourceSlice
{
    /**
     * @param string       $fqcn      the class the slice was asked for
     * @param string|null  $method    the method actually shown; null when the
     *                                slice is the whole class (asked for the
     *                                class, or the method could not be located)
     * @param string       $file      path relative to the project root
     * @param int          $startLine 1-based, inclusive
     * @param int          $endLine   1-based, inclusive, AFTER any truncation
     * @param list<string> $lines     the source, one entry per line, no trailing newline
     * @param bool         $truncated true when the run was cut at the line cap
     * @param string       $origin    where the bounds came from: `reflection`
     *                                (fresh, method-capable) or `graph`
     *                                (class-level, as old as the last
     *                                ai:review-graph:generate)
     */
    public function __construct(
        public string $fqcn,
        public ?string $method,
        public string $file,
        public int $startLine,
        public int $endLine,
        public array $lines,
        public bool $truncated,
        public string $origin,
    ) {
    }

    public function text(): string
    {
        return implode("\n", $this->lines);
    }

    /**
     * The shape a JSON consumer (ai:observe show) receives. Kept here so the
     * key names live in exactly one place.
     *
     * @return array{fqcn: string, method: string|null, file: string, startLine: int, endLine: int, truncated: bool, origin: string, lines: list<string>}
     */
    public function toArray(): array
    {
        return [
            'fqcn' => $this->fqcn,
            'method' => $this->method,
            'file' => $this->file,
            'startLine' => $this->startLine,
            'endLine' => $this->endLine,
            'truncated' => $this->truncated,
            'origin' => $this->origin,
            'lines' => $this->lines,
        ];
    }
}

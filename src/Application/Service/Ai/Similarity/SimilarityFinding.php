<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Similarity;

/**
 * One collision between a proposed artifact and something already in the
 * codebase. The detector returns a list of these; the command decides
 * whether the run can continue.
 *
 * Severity contract:
 *  - `block` — scaffolder MUST refuse unless `--override-duplicate` is passed.
 *              Reserved for exact-identity collisions the generator would
 *              overwrite or that would double-bind a route/event.
 *  - `warn`  — print the finding, continue by default. Reserved for close
 *              matches that are usually, but not always, a mistake.
 */
final readonly class SimilarityFinding
{
    public const SEVERITY_BLOCK = 'block';
    public const SEVERITY_WARN  = 'warn';

    /**
     * @param array<string, string> $details Rendered key/value pairs for the refusal/warning output.
     */
    public function __construct(
        public string $severity,
        public string $rule,
        public string $message,
        public string $priorArtPath,
        public string $priorArtFqcn,
        public array $details = [],
    ) {}

    public function toArray(): array
    {
        return [
            'severity'        => $this->severity,
            'rule'            => $this->rule,
            'message'         => $this->message,
            'prior_art_path'  => $this->priorArtPath,
            'prior_art_fqcn'  => $this->priorArtFqcn,
            'details'         => $this->details,
        ];
    }
}

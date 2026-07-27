<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Report;

/**
 * A framework defect an agent hit while building on Semitexa, together with the
 * evidence that it is real and the workaround that was applied instead.
 *
 * Two fields carry the weight and both are mandatory:
 *
 *   - `evidence` — a command and its output proving the defect. An agent that
 *     merely suspects a bug is usually looking at its own mistake; requiring
 *     the proof is what keeps this channel from filling with noise.
 *   - `workaround` — what was done instead. This is the most valuable part of
 *     the report: it shows the severity, gives other users a stopgap, and is
 *     the thing that silently becomes permanent when nobody writes it down.
 */
final class DefectReport
{
    /**
     * Obvious credential shapes. Reports travel to a PUBLIC issue tracker from
     * someone else's machine, so a cheap scan is worth more than a warning
     * nobody reads. Not exhaustive by design — the doctrine rule in AGENTS.md
     * is the real control; this catches the accident.
     */
    private const array SECRET_PATTERNS = [
        '/\bsk-[A-Za-z0-9_\-]{16,}/',            // API keys
        '/\bgh[pousr]_[A-Za-z0-9]{20,}/',        // GitHub tokens
        '/\bxox[baprs]-[A-Za-z0-9\-]{10,}/',     // Slack tokens
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',  // PEM material
        '/(?i)\b(password|passwd|secret|api[_-]?key|token)\s*[=:]\s*\S{6,}/',
        '/(?i)\bDB_PASSWORD\s*=/',
    ];

    /** @param array<string, string> $versions installed semitexa/* package → version */
    private function __construct(
        public readonly string $title,
        public readonly string $summary,
        public readonly string $evidence,
        public readonly string $workaround,
        public readonly ?string $package,
        public readonly array $versions,
    ) {}

    /**
     * @param array<string, string> $versions
     * @throws \InvalidArgumentException when a mandatory field is missing, too
     *         thin to act on, or carries something that looks like a secret
     */
    public static function create(
        string $title,
        string $summary,
        string $evidence,
        string $workaround,
        ?string $package = null,
        array $versions = [],
    ): self {
        $title      = trim($title);
        $summary    = trim($summary);
        $evidence   = trim($evidence);
        $workaround = trim($workaround);

        if ($title === '') {
            throw new \InvalidArgumentException('A report needs a title.');
        }
        if (mb_strlen($summary) < 20) {
            throw new \InvalidArgumentException(
                'Describe what broke in at least a sentence — a title alone is not actionable.'
            );
        }
        if (mb_strlen($evidence) < 20) {
            throw new \InvalidArgumentException(
                'Evidence is required: the command you ran and what it printed. '
                . 'Without it this is a suspicion, and a suspicion is usually the agent\'s own mistake.'
            );
        }
        if (mb_strlen($workaround) < 10) {
            throw new \InvalidArgumentException(
                'State the workaround you applied (or "none — blocked"). It is the part that '
                . 'silently becomes permanent when nobody records it.'
            );
        }

        // Every field that reaches toMarkdown() must be scanned, not just the
        // free-text ones. $package is published verbatim, so leaving it out
        // left an unscanned path straight to the public tracker.
        foreach ([$title, $summary, $evidence, $workaround, (string) $package] as $field) {
            self::assertNoSecrets($field);
        }

        return new self($title, $summary, $evidence, $workaround, $package, $versions);
    }

    private static function assertNoSecrets(string $text): void
    {
        foreach (self::SECRET_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                throw new \InvalidArgumentException(
                    'The report looks like it contains a credential. This is published to a public '
                    . 'issue tracker — redact it and try again.'
                );
            }
        }
    }

    /**
     * Stable fingerprint for de-duplication. Derived from the title only, so the
     * same defect reported from two projects with different repro text still
     * collides.
     */
    public function fingerprint(): string
    {
        $normalized = mb_strtolower($this->title);
        $normalized = (string) preg_replace('/[^a-z0-9]+/u', ' ', $normalized);

        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }

    /** Search terms for finding an existing issue about the same defect. */
    public function searchTerms(): string
    {
        $words = array_filter(
            explode(' ', $this->fingerprint()),
            static fn (string $w): bool => mb_strlen($w) > 3,
        );

        return implode(' ', array_slice(array_values($words), 0, 6));
    }

    /**
     * A fence long enough to survive the content it wraps.
     *
     * Tool output plausibly contains a run of three or more backticks; a fixed
     * ``` fence would close early and spill the rest of the field into the
     * issue as loose Markdown. CommonMark's rule is that the fence must be
     * longer than any run inside, so measure rather than assume.
     */
    public static function fenceFor(string $content): string
    {
        $longest = 0;
        if (preg_match_all('/`+/', $content, $matches) > 0) {
            foreach ($matches[0] as $run) {
                $longest = max($longest, strlen($run));
            }
        }

        return str_repeat('`', max(3, $longest + 1));
    }

    public function toMarkdown(): string
    {
        $fence = self::fenceFor($this->evidence);

        $lines = [
            '## What broke',
            '',
            $this->summary,
            '',
            '## Evidence',
            '',
            $fence,
            $this->evidence,
            $fence,
            '',
            '## Workaround applied',
            '',
            $this->workaround,
            '',
        ];

        if ($this->package !== null && $this->package !== '') {
            $lines[] = '## Affected package';
            $lines[] = '';
            $lines[] = '`' . $this->package . '`';
            $lines[] = '';
        }

        if ($this->versions !== []) {
            $lines[] = '## Installed versions';
            $lines[] = '';
            foreach ($this->versions as $name => $version) {
                $lines[] = sprintf('- `%s` %s', $name, $version);
            }
            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = 'Reported by an agent via `bin/semitexa ai:report` while building on Semitexa.';

        return implode("\n", $lines);
    }
}

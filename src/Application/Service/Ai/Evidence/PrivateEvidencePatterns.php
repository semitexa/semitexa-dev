<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Evidence;

/**
 * What must not leave the machine in a piece of text: the same list
 * review-prep's check-public-text.py reads on the host before a PR description
 * is published (resources/skills/review-prep/private-evidence-patterns.json).
 * One file, two readers; the regexes stay in the subset PCRE and Python share.
 */
final class PrivateEvidencePatterns
{
    public const FILE = 'resources/skills/review-prep/private-evidence-patterns.json';

    /** @param array<string, array{why: string, regex: string}> $patterns id => pattern */
    private function __construct(private readonly array $patterns)
    {
    }

    /** @throws \RuntimeException when the list cannot be read: a check that cannot run must not pass */
    public static function load(?string $file = null): self
    {
        $file ??= dirname(__DIR__, 5) . '/' . self::FILE;
        $raw = @file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_array($data['patterns'] ?? null)) {
            throw new \RuntimeException('Cannot read the private-evidence patterns at ' . $file);
        }
        $patterns = [];
        foreach ($data['patterns'] as $p) {
            $regex = '~' . (string) ($p['regex'] ?? '') . '~';
            if (!is_string($p['id'] ?? null) || @preg_match($regex, '') === false) {
                throw new \RuntimeException('A private-evidence pattern is malformed in ' . $file);
            }
            $patterns[$p['id']] = ['why' => (string) ($p['why'] ?? ''), 'regex' => $regex];
        }

        return new self($patterns);
    }

    /** @return list<string> pattern ids */
    public function ids(): array
    {
        return array_keys($this->patterns);
    }

    /**
     * The whole text, as the host script reads it: Markdown renders an image
     * split over two lines (`![a` / `b](x.png)`), which neither line matched
     * alone. `m` keeps `^` meaning the start of a line. A finding names the
     * line its match starts on.
     *
     * @return list<array{line: int, id: string, why: string}>
     */
    public function scan(string $text): array
    {
        $found = [];
        foreach ($this->patterns as $id => $pattern) {
            preg_match_all($pattern['regex'] . 'm', $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [, $offset]) {
                $found[] = ['line' => substr_count($text, "\n", 0, $offset) + 1, 'id' => $id, 'why' => $pattern['why']];
            }
        }
        // By line, then in the list's order (usort is stable).
        usort($found, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return $found;
    }
}

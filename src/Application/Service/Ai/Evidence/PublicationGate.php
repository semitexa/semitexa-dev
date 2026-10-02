<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Evidence;

/**
 * Whether one piece of evidence may leave the machine. Answers with the
 * reasons it may not; an empty list is the only yes. The human confirmation
 * is the command's job — this decides what is not even worth asking about.
 *
 *  - a screenshot or recording of real data never leaves: no pattern can read
 *    pixels, so the only safe one is retaken on synthetic data;
 *  - other real data (a trace, a log, the graph of this codebase) leaves only
 *    with an explicit allowance;
 *  - text is read against the private-evidence patterns, and a hit refuses
 *    whatever the allowance: redact it and record the redacted file;
 *  - a file that cannot be read as text or pixels cannot be checked, so it is
 *    refused — a check that cannot run must not pass;
 *  - expired, or changed since it was recorded: refused.
 */
final class PublicationGate
{
    private const TEXT_EXTENSIONS = ['txt', 'md', 'json', 'ndjson', 'log', 'html', 'htm', 'csv', 'xml', 'yaml', 'yml', 'svg', 'dot'];
    private const VISUAL_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'bmp', 'mp4', 'webm', 'mov', 'mkv'];

    public function __construct(private readonly PrivateEvidencePatterns $patterns)
    {
    }

    /** @return list<string> */
    public function refusals(EvidenceRecord $record, string $file, bool $allowRealData, \DateTimeImmutable $now): array
    {
        if ($record->isExpired($now)) {
            return ['it expired on ' . $record->expiresAt->format('Y-m-d') . '; record it again if it is still needed'];
        }
        if (!is_file($file) || hash_file('sha256', $file) !== $record->sha256) {
            return ['the file changed after it was recorded: record the version you mean to publish'];
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $visual = $record->kind->isVisual() || in_array($extension, self::VISUAL_EXTENSIONS, true);
        if ($visual) {
            return $record->data === EvidenceData::Real
                ? ['it is a picture of real data, and nothing can check pixels: retake it on synthetic data']
                : [];
        }

        $refusals = [];
        if ($record->data === EvidenceData::Real && !$allowRealData) {
            $refusals[] = 'it holds real data: publishing it is the operator\'s explicit call (--allow-real-data)';
        }
        $text = self::readText($file, $extension);
        if ($text === null) {
            $refusals[] = 'it is neither text nor an image, so nothing can check it';

            return $refusals;
        }
        foreach ($this->patterns->scan($text) as $hit) {
            $refusals[] = sprintf('line %d: %s — %s', $hit['line'], $hit['id'], $hit['why']);
        }

        return $refusals;
    }

    private static function readText(string $file, string $extension): ?string
    {
        $text = @file_get_contents($file);
        if (!is_string($text)) {
            return null;
        }
        // A known text extension is not enough: a NUL byte is a binary file
        // whatever it is called.
        if (!in_array($extension, self::TEXT_EXTENSIONS, true) || str_contains(substr($text, 0, 8192), "\0")) {
            return null;
        }

        return $text;
    }
}

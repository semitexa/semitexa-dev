<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Recipe;

use Semitexa\Dev\Application\Service\Capability\CapabilityIndex;

/**
 * What the framework already has for a task no recipe matched
 * (tk-task-misses-everyday-tasks).
 *
 * "send a welcome mail" or "a webhook from Stripe" came back unknown_task,
 * with advice to open an epic, while mail.outbound and webhooks.inbound were
 * one search away. The words of the description are looked up in the
 * capability index, the catalog ai:ask mechanisms reads, and the
 * capabilities that mention most of them come back first.
 */
final class CapabilitySearch
{
    private const STOP = ['the', 'and', 'for', 'with', 'from', 'into', 'that', 'this', 'when', 'what', 'have', 'make', 'add', 'new', 'page', 'some', 'every', 'each', 'user', 'users', 'let', 'can', 'all', 'via', 'use', 'get', 'set', 'our', 'any'];

    /**
     * @param list<array<string, mixed>> $capabilities as the index lists them
     * @return list<array{id: string, summary: string, words: list<string>}>
     */
    public static function hits(string $description, array $capabilities, int $limit = 5): array
    {
        $words = self::words($description);
        if ($words === []) {
            return [];
        }
        $scored = [];
        foreach ($capabilities as $capability) {
            $text = strtolower(implode(' ', [
                (string) ($capability['id'] ?? ''),
                (string) ($capability['summary'] ?? ''),
                (string) ($capability['use_when'] ?? ''),
                // The fields ai:ask mechanisms --search reads, but what to avoid it for.
                (string) ($capability['declared_by'] ?? ''),
                (string) ($capability['package'] ?? ''),
                implode(' ', array_map('strval', (array) ($capability['replaces'] ?? []))),
            ]));
            $matched = array_values(array_filter($words, static fn (string $w): bool => str_contains($text, $w)));
            if ($matched !== []) {
                $id = (string) ($capability['id'] ?? '');
                // A word in the id names the capability; in its prose it only mentions it.
                $named = count(array_filter($matched, static fn (string $w): bool => str_contains($id, $w)));
                $scored[] = ['rank' => count($matched) + $named, 'id' => $id, 'summary' => (string) ($capability['summary'] ?? ''), 'words' => $matched];
            }
        }
        usort($scored, static fn (array $a, array $b): int => [$b['rank'], $a['id']] <=> [$a['rank'], $b['id']]);

        return array_map(
            static fn (array $hit): array => ['id' => $hit['id'], 'summary' => $hit['summary'], 'words' => $hit['words']],
            array_slice($scored, 0, $limit),
        );
    }

    /** @return list<array<string, mixed>> the shipped capability index of the project, or none */
    public static function catalog(string $projectRoot): array
    {
        $index = CapabilityIndex::read(CapabilityIndex::path($projectRoot));

        return is_array($index['capabilities'] ?? null) ? array_values($index['capabilities']) : [];
    }

    /** @return list<string> the description's words worth looking up, singular */
    private static function words(string $description): array
    {
        preg_match_all('/[a-z][a-z-]{2,}/', strtolower($description), $m);
        $words = [];
        foreach ($m[0] as $word) {
            $word = preg_replace('/(?<=[a-z]{3})s$/', '', $word) ?? $word;
            if (strlen($word) >= 3 && !in_array($word, self::STOP, true)) {
                $words[] = $word;
            }
        }

        return array_values(array_unique($words));
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Context;

use Semitexa\Dev\Application\Service\Ai\Recipe\Recipe;

/**
 * Walks src/modules/ and ranks files whose paths match a recipe's
 * `context_signals` so the agent gets prior art instead of "the whole graph."
 *
 * Phase 2 implementation: filesystem-only, no graph dependency. The graph
 * substrate is the right long-term home (it has node types, conventions,
 * recency) but the audit explicitly says "start minimal, iterate later" —
 * a path-substring scorer ships today and proves the shape.
 */
final class ContextPacker
{
    private const MAX_RESULTS = 10;
    private const MAX_RANK_FILES = 2000;

    public function __construct(
        private readonly string $projectRoot,
    ) {}

    /**
     * @return list<PriorArtItem>
     */
    public function pack(Recipe $recipe, ?string $module = null): array
    {
        if ($recipe->context_signals === []) {
            return [];
        }

        $candidates = $this->collectCandidates();
        $items = [];
        foreach ($candidates as $relPath) {
            $score = $this->scoreFile($relPath, $recipe, $module);
            if ($score <= 0) {
                continue;
            }
            $items[] = new PriorArtItem(
                path: $relPath,
                module: $this->extractModule($relPath),
                type: $this->extractType($relPath),
                score: $score,
                why: $this->describeWhy($relPath, $recipe, $module, $score),
            );
        }

        usort($items, static fn(PriorArtItem $a, PriorArtItem $b): int => $b->score <=> $a->score);

        return array_slice($items, 0, self::MAX_RESULTS);
    }

    /**
     * @return list<string>
     */
    private function collectCandidates(): array
    {
        $modulesDir = $this->projectRoot . '/src/modules';
        if (!is_dir($modulesDir)) {
            return [];
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modulesDir, \FilesystemIterator::SKIP_DOTS),
        );

        $rels = [];
        foreach ($iter as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            $ext = strtolower((string) $entry->getExtension());
            if (!in_array($ext, ['php', 'twig'], true)) {
                continue;
            }
            $rels[] = ltrim(str_replace($this->projectRoot, '', (string) $entry->getRealPath()), '/');
            if (count($rels) >= self::MAX_RANK_FILES) {
                break;
            }
        }
        return $rels;
    }

    private function scoreFile(string $relPath, Recipe $recipe, ?string $moduleHint): int
    {
        $score = 0;
        $haystack = strtolower($relPath);

        foreach ($recipe->context_signals as $signal) {
            if ($signal === '') {
                continue;
            }
            if (str_contains($haystack, strtolower($signal))) {
                $score += 3;
            }
        }

        if ($score === 0) {
            return 0;
        }

        if ($moduleHint !== null && str_contains($haystack, strtolower("/modules/{$moduleHint}/"))) {
            $score += 4;
        }

        if (str_ends_with($haystack, 'test.php')) {
            $score -= 1;
        }
        return $score;
    }

    private function extractModule(string $relPath): string
    {
        if (preg_match('#^src/modules/([^/]+)/#', $relPath, $m) === 1) {
            return $m[1];
        }
        return '';
    }

    private function extractType(string $relPath): string
    {
        if (str_ends_with($relPath, '.twig')) {
            return 'template';
        }
        if (str_contains($relPath, '/Handler/PayloadHandler/')) {
            return 'payload_handler';
        }
        if (str_contains($relPath, '/Handler/DomainListener/')) {
            return 'domain_listener';
        }
        if (str_contains($relPath, '/Payload/')) {
            return 'payload';
        }
        if (str_contains($relPath, '/Resource/')) {
            return 'resource';
        }
        if (str_contains($relPath, '/Domain/Service/')) {
            return 'service';
        }
        if (str_contains($relPath, '/Domain/Contract/')) {
            return 'contract';
        }
        if (str_contains($relPath, '/Application/Console/')) {
            return 'cli_command';
        }
        return 'php';
    }

    private function describeWhy(string $relPath, Recipe $recipe, ?string $moduleHint, int $score): string
    {
        $matched = [];
        $haystack = strtolower($relPath);
        foreach ($recipe->context_signals as $signal) {
            if ($signal !== '' && str_contains($haystack, strtolower($signal))) {
                $matched[] = $signal;
            }
        }
        $why = 'matches signal(s): ' . implode(',', $matched);
        if ($moduleHint !== null && str_contains($haystack, strtolower("/modules/{$moduleHint}/"))) {
            $why .= "; in module {$moduleHint}";
        }
        return $why;
    }
}

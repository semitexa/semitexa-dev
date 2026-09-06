<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Context;

use Semitexa\Dev\Application\Service\Ai\Recipe\Recipe;

/**
 * Walks the repository's source roots and ranks files whose paths match a
 * recipe's `context_signals`, so the agent gets prior art instead of "the whole
 * graph."
 *
 * Both roots, not just one. This walked `src/modules/` alone, which is correct
 * for a consumer project and useless in the framework workspace where every
 * package lives under `packages/*` — there it reported greenfield for almost
 * every recipe while the prior art sat one directory over.
 *
 * MEASURED 2026-09-06 in this workspace: 608 candidate files under src/modules,
 * 2980 under packages/*\/src. The old single cap of 2000 across one collected
 * list would therefore have DROPPED most of the repository the moment packages
 * were added, and dropped it by directory iteration order — invisibly. The cap
 * is now per root, so one root cannot starve the other, and hitting it is
 * reported through {@see truncatedRoots()} instead of quietly shortening the
 * answer.
 *
 * Phase 2 implementation: filesystem-only, no graph dependency. The graph
 * substrate is the right long-term home (it has node types, conventions,
 * recency) but the audit explicitly says "start minimal, iterate later" —
 * a path-substring scorer ships today and proves the shape.
 */
final class ContextPacker
{
    private const MAX_RESULTS = 10;

    /**
     * Per ROOT, not per run. Sized with headroom over the measurement above so
     * neither root truncates in this repository today; a project large enough
     * to hit it is told, rather than served a silently shortened answer.
     */
    private const MAX_RANK_FILES_PER_ROOT = 4000;

    /** @var list<string> roots whose walk hit the cap during the last pack() */
    private array $truncatedRoots = [];

    public function __construct(
        private readonly string $projectRoot,
    ) {}

    /**
     * Roots whose walk hit the file cap during the last {@see pack()}. A
     * non-empty list means the prior art below is drawn from part of that root
     * only — the difference between "there is nothing" and "we stopped looking",
     * which the caller should say out loud.
     *
     * @return list<string>
     */
    public function truncatedRoots(): array
    {
        return $this->truncatedRoots;
    }

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
        $this->truncatedRoots = [];
        $rels = [];

        // Application modules first, then framework packages. A consumer
        // project has only the first; the framework workspace has both, and
        // its own packages are where nearly all the prior art lives.
        foreach (['src/modules', 'packages'] as $root) {
            foreach ($this->walkRoot($root) as $rel) {
                $rels[] = $rel;
            }
        }

        return $rels;
    }

    /**
     * @return list<string>
     */
    private function walkRoot(string $root): array
    {
        $dir = $this->projectRoot . '/' . $root;
        if (!is_dir($dir)) {
            return [];
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
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

            $rel = ltrim(str_replace($this->projectRoot, '', (string) $entry->getRealPath()), '/');

            // A package's PRODUCTION source only. Its tests, fixtures and
            // vendored assets are not prior art for writing new code, and
            // including them would let a package's own test suite outrank the
            // implementation it is testing.
            if ($root === 'packages' && preg_match('#^packages/[^/]+/src/#', $rel) !== 1) {
                continue;
            }

            $rels[] = $rel;
            if (count($rels) >= self::MAX_RANK_FILES_PER_ROOT) {
                $this->truncatedRoots[] = $root;
                break;
            }
        }

        return $rels;
    }

    private function scoreFile(string $relPath, Recipe $recipe, ?string $moduleHint): int
    {
        $score = 0;
        $haystack = self::normalize($relPath);

        foreach ($recipe->context_signals as $signal) {
            if ($signal === '') {
                continue;
            }
            if (str_contains($haystack, self::normalize($signal))) {
                $score += 3;
            }
        }

        if ($score === 0) {
            return 0;
        }

        if ($moduleHint !== null && $this->belongsTo($relPath, $moduleHint)) {
            $score += 4;
        }

        if (str_ends_with($haystack, 'test.php')) {
            $score -= 1;
        }
        return $score;
    }

    /**
     * Signals are written in NAMESPACE form (`Domain\Service`,
     * `Application\Console`) and matched against PATHS, where the separator is
     * a forward slash. Without this, every multi-segment signal in the registry
     * silently matched nothing: `add_cli_command` scored one file in the whole
     * repository — the AsCommand attribute, the only signal with no separator
     * in it — while dozens of actual commands went unranked. That is the same
     * "looks like greenfield" symptom as the missing packages root, from a
     * different cause.
     */
    private static function normalize(string $value): string
    {
        return str_replace('\\', '/', strtolower($value));
    }

    /**
     * Whether a file belongs to the hinted module or package. The hint is
     * matched against both shapes because in this workspace the thing an agent
     * names ("media", "semitexa-media", "Playground") may be either.
     */
    private function belongsTo(string $relPath, string $hint): bool
    {
        $owner = strtolower($this->extractModule($relPath));
        if ($owner === '') {
            return false;
        }
        $hint = strtolower($hint);

        return $owner === $hint
            || $owner === 'semitexa-' . $hint
            || 'semitexa-' . $owner === $hint;
    }

    private function extractModule(string $relPath): string
    {
        if (preg_match('#^src/modules/([^/]+)/#', $relPath, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#^packages/([^/]+)/src/#', $relPath, $m) === 1) {
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
        $haystack = self::normalize($relPath);
        foreach ($recipe->context_signals as $signal) {
            if ($signal !== '' && str_contains($haystack, self::normalize($signal))) {
                $matched[] = $signal;
            }
        }
        $why = 'matches signal(s): ' . implode(',', $matched);
        if ($moduleHint !== null && $this->belongsTo($relPath, $moduleHint)) {
            $why .= "; in module {$moduleHint}";
        }
        return $why;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A ratchet on the biggest classes in the monorepo.
 *
 * The god-class work has been declared done more than once while the class was
 * still the largest thing in the repository. MEASURED 2026-09-06, unchanged
 * since the 2026-09-02 re-measurement: SseServer is 102 methods and 2082 lines,
 * against 59 and 1126 for the next class by methods — 1.7x and 1.85x. A record
 * saying otherwise was not lying on purpose; nothing was counting.
 *
 * So this counts, and it moves in one direction. A class that grows past its
 * recorded size fails. A class that SHRINKS past it also fails, and says to
 * lower the number — otherwise a real refactor leaves behind a budget with room
 * to regrow into, which is how the last one came back.
 *
 * No complexity tool is installed and this is deliberately not one. Method and
 * line counts are crude, but they are reproducible by anyone with grep, which
 * is what a number in a backlog entry has to be.
 */
final class StructuralOutlierBudgetTest extends TestCase
{
    /** Classes at or above 30 methods or 700 lines: path => [methods, lines]. */
    private const BUDGETS = [
        'semitexa-ssr/src/Application/Service/Async/SseServer.php' => [102, 2082],
        'semitexa-orm/src/Query/ResourceModelQuery.php' => [59, 1126],
        'semitexa-orm/src/OrmManager.php' => [41, 917],
        'semitexa-webhooks/src/Domain/Model/OutboundDelivery.php' => [35, 155],
        'semitexa-os/src/Application/Service/SkillLoopRunner.php' => [34, 1246],
        'semitexa-core/src/Discovery/AttributeDiscovery.php' => [33, 932],
        'semitexa-weave/src/Application/Service/GraphStore.php' => [32, 685],
        'semitexa-webhooks/src/Domain/Model/InboundDelivery.php' => [32, 122],
        'semitexa-platform-settings/src/Application/Service/SettingsStore.php' => [31, 473],
        'semitexa-ssr/src/Application/Service/Async/AsyncResourceSseServer.php' => [31, 206],
        'semitexa-orm/src/Adapter/ConnectionPool.php' => [27, 842],
        'semitexa-ssr/src/Application/Handler/PayloadHandler/AbstractSseFeedHandler.php' => [26, 759],
        'semitexa-ssr/src/Application/Service/Http/Response/HtmlResponse.php' => [25, 765],
        'semitexa-dev/src/Application/Service/Trace/TraceHtmlRenderer.php' => [24, 771],
        'semitexa-dev/src/Application/Service/Ai/Verify/Structure/ModuleStructureValidator.php' => [22, 1092],
        'semitexa-orm/src/Application/Service/Sync/SyncEngine.php' => [21, 865],
        'semitexa-dev/src/Application/Service/Ai/Verify/VerificationExecutor.php' => [21, 718],
        'semitexa-api/src/OpenApi/Route/ResourceRouteSchemaGenerator.php' => [20, 912],
        'semitexa-update/src/Application/Service/Composer/ComposerUpdateRunner.php' => [20, 751],
        'semitexa-core/src/Discovery/ClassDiscovery.php' => [20, 742],
        'semitexa-orm/src/Application/Service/Schema/SchemaCollector.php' => [20, 709],
        'semitexa-dev/src/Application/Service/Ai/Verify/VerificationPlanner.php' => [19, 827],
        'semitexa-core/src/Pipeline/RouteExecutor.php' => [18, 720],
        'semitexa-demo/src/Application/Service/DemoCatalogService.php' => [17, 825],
        'semitexa-core/src/Resource/ResourceExpansionPipeline.php' => [12, 707],
        'semitexa-platform-ui/src/Application/Service/Twig/PlatformUiTwigExtension.php' => [1, 818],
    ];

    /** Slack before a shrink is treated as a real reduction rather than an edit. */
    private const SHRINK_TOLERANCE_LINES = 40;
    private const SHRINK_TOLERANCE_METHODS = 3;

    #[Test]
    public function no_recorded_outlier_has_grown(): void
    {
        $grown = [];

        foreach (self::BUDGETS as $path => [$methods, $lines]) {
            $actual = $this->measure($path);
            if ($actual === null) {
                continue; // a deleted class is covered by the test below
            }

            if ($actual[0] > $methods || $actual[1] > $lines) {
                $grown[] = sprintf(
                    '%s: %d methods / %d lines, budget %d / %d',
                    $path,
                    $actual[0],
                    $actual[1],
                    $methods,
                    $lines,
                );
            }
        }

        self::assertSame(
            [],
            $grown,
            "These classes grew past their recorded size. Split them, or record the new number "
            . "deliberately:\n  - " . implode("\n  - ", $grown),
        );
    }

    /**
     * The half that keeps "done" honest: once a class is genuinely smaller, the
     * budget must come down with it.
     */
    #[Test]
    public function a_shrunken_outlier_lowers_its_budget(): void
    {
        $stale = [];

        foreach (self::BUDGETS as $path => [$methods, $lines]) {
            $actual = $this->measure($path);
            if ($actual === null) {
                $stale[] = $path . ': gone — remove it from the budget list';
                continue;
            }

            if ($actual[0] + self::SHRINK_TOLERANCE_METHODS < $methods
                || $actual[1] + self::SHRINK_TOLERANCE_LINES < $lines
            ) {
                $stale[] = sprintf(
                    '%s: now %d methods / %d lines, budget still %d / %d',
                    $path,
                    $actual[0],
                    $actual[1],
                    $methods,
                    $lines,
                );
            }
        }

        self::assertSame(
            [],
            $stale,
            "These budgets have room the class no longer needs — lower them, or the next "
            . "regrowth is invisible:\n  - " . implode("\n  - ", $stale),
        );
    }

    /**
     * A new class larger than everything recorded here would otherwise arrive
     * unnoticed: the two tests above only look at what is already listed.
     */
    #[Test]
    public function nothing_new_has_climbed_into_the_outliers(): void
    {
        $unlisted = [];

        foreach ($this->classFiles() as $path => [$methods, $lines]) {
            if (($methods >= 30 || $lines >= 700) && !array_key_exists($path, self::BUDGETS)) {
                $unlisted[] = sprintf('%s: %d methods / %d lines', $path, $methods, $lines);
            }
        }

        sort($unlisted);

        self::assertSame(
            [],
            $unlisted,
            "These crossed the outlier threshold without being recorded:\n  - "
            . implode("\n  - ", $unlisted),
        );
    }

    /** @return array{0: int, 1: int}|null */
    private function measure(string $path): ?array
    {
        $full = $this->packagesDir() . '/' . $path;

        return is_file($full) ? $this->measureFile($full) : null;
    }

    /** @return array<string, array{0: int, 1: int}> */
    private function classFiles(): array
    {
        $out = [];
        $dir = $this->packagesDir();

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace($dir . '/', '', $file->getPathname());
            // Source only: tests and resources carry their own shapes.
            if (!preg_match('#^[a-z0-9-]+/src/#', $path)) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/^\s*(final |abstract )?class /m', $source) !== 1) {
                continue;
            }
            $out[$path] = $this->measureFile($file->getPathname());
        }

        return $out;
    }

    /** @return array{0: int, 1: int} */
    private function measureFile(string $file): array
    {
        $source = (string) file_get_contents($file);

        return [
            preg_match_all('/^\s*(?:public|protected|private)\s+(?:static\s+)?function /m', $source),
            substr_count($source, "\n") + 1,
        ];
    }

    private function packagesDir(): string
    {
        return dirname(__DIR__, 4);
    }
}

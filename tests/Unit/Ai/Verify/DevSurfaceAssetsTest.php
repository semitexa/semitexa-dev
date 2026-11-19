<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Console\Command\AiVerifyCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\DevSurfaceAssets;
use Semitexa\Dev\Application\Service\Ai\Verify\PageCheckTargets;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationExecutor;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationResult;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;
use Semitexa\Dev\Application\Service\Explorer\ExplorerHtmlRenderer;
use Semitexa\Dev\Application\Service\Trace\ObservatoryHtmlRenderer;
use Semitexa\ProjectGraph\Application\Service\Query\GraphViewerAssets;
use Symfony\Component\Console\Application;

/**
 * A stylesheet only a dev surface shows picks no public page.
 *
 * REGRESSION (2026-11-19). The graph viewer's graph-view.css changed. It is
 * served on /__observatory alone, which the page check cannot open; the plan
 * opened eight project pages for it, each answered "does not load
 * graph-view.css: it cannot show that change", and the verdict stayed
 * incomplete for a file no public page can show.
 */
final class DevSurfaceAssetsTest extends TestCase
{
    private const ROUTES = [
        ['path' => '/', 'module' => 'Guestbook'],
        ['path' => '/guestbook', 'module' => 'Guestbook'],
        ['path' => '/__observatory', 'module' => 'semitexa-dev'],
    ];

    private const VIEWER_CSS = 'packages/semitexa-project-graph/resources/viewer/graph-view.css';
    private const THEME_CSS = 'packages/semitexa-theme/src/Application/Static/css/layouts.css';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/dev-surface-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/packages/semitexa-dev/tests/E2E', 0777, true);
        file_put_contents($this->root . '/packages/semitexa-dev/tests/E2E/observatory-graph.spec.ts', "await page.goto('/__observatory#graph');\n");
        // Opens another surface, and only mentions this one.
        file_put_contents($this->root . '/packages/semitexa-dev/tests/E2E/api-explorer.spec.ts', "await page.goto('/__explorer');\nawait expect(link).toHaveAttribute('href', '/__observatory');\nawait page.goto('/__observatory/graph?view=summary');\n");
    }

    #[Test]
    public function a_package_linked_into_vendor_names_its_spec_once(): void
    {
        mkdir($this->root . '/vendor/semitexa', 0777, true);
        symlink($this->root . '/packages/semitexa-dev', $this->root . '/vendor/semitexa/dev');

        $result = DevSurfaceAssets::check(DevSurfaceAssets::targets(['/__observatory' => [self::VIEWER_CSS]])[0], $this->root);

        self::assertSame(1, substr_count($result->signal, 'observatory-graph.spec.ts'));
        self::assertStringContainsString('packages/semitexa-dev/tests/E2E/observatory-graph.spec.ts', $result->signal);
    }

    /** A spec that is there and cannot be read may be the one that opens the surface. */
    #[Test]
    public function a_spec_that_cannot_be_read_leaves_the_run_incomplete(): void
    {
        // A link to nowhere: nobody can read it, root included, which a file without permissions could be.
        symlink($this->root . '/no-such-directory/gone.spec.ts', $this->root . '/packages/semitexa-dev/tests/E2E/unreadable.spec.ts');

        $result = DevSurfaceAssets::check(DevSurfaceAssets::targets(['/__observatory' => [self::VIEWER_CSS]])[0], $this->root);

        self::assertSame([VerificationResult::STATUS_INCOMPLETE, 1, true], [$result->status, $result->exitCode, $result->required]);
        self::assertStringContainsString('packages/semitexa-dev/tests/E2E/unreadable.spec.ts could not be read', $result->signal);
        $passed = new VerificationResult(new VerificationTarget('lint', 'lint:lint:css-tokens', 'r', [self::VIEWER_CSS]), VerificationResult::STATUS_PASS, 0, 'clean');
        self::assertSame('incomplete', (new \ReflectionMethod(AiVerifyCommand::class, 'verdict'))->invoke(new AiVerifyCommand(), [$passed, $result]));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function the_viewers_stylesheet_opens_no_public_page_and_names_its_surface(): void
    {
        $targets = PageCheckTargets::of([new ChangedFile(self::VIEWER_CSS, ChangedFile::KIND_STYLESHEET)], VerificationPlan::SCOPE_STANDARD, self::ROUTES);

        self::assertSame(
            [['dev_surface', 'dev_surface:/__observatory', [self::VIEWER_CSS], '/__observatory']],
            array_map(static fn (VerificationTarget $t): array => [$t->type, $t->id, $t->triggeredBy, $t->commandInput['path'] ?? null], $targets),
        );
    }

    #[Test]
    public function a_stylesheet_public_pages_load_still_opens_them_beside_it(): void
    {
        $targets = PageCheckTargets::of(
            [new ChangedFile(self::VIEWER_CSS, ChangedFile::KIND_STYLESHEET), new ChangedFile(self::THEME_CSS, ChangedFile::KIND_STYLESHEET)],
            VerificationPlan::SCOPE_STANDARD,
            self::ROUTES,
        );

        // The pages are opened for the theme alone: the viewer's file is not among what they must load.
        self::assertSame(
            [['page:/', [self::THEME_CSS]], ['page:/guestbook', [self::THEME_CSS]], ['dev_surface:/__observatory', [self::VIEWER_CSS]]],
            array_map(static fn (VerificationTarget $t): array => [$t->id, $t->triggeredBy], $targets),
        );
    }

    #[Test]
    public function its_result_names_the_specs_that_open_the_surface_and_leaves_the_verdict_alone(): void
    {
        $targets = PageCheckTargets::of([new ChangedFile(self::VIEWER_CSS, ChangedFile::KIND_STYLESHEET)], VerificationPlan::SCOPE_STANDARD, self::ROUTES);
        $plan = new VerificationPlan(VerificationPlan::SCOPE_STANDARD, VerificationPlan::SCOPE_STANDARD, [new ChangedFile(self::VIEWER_CSS, ChangedFile::KIND_STYLESHEET)], $targets);

        $results = (new VerificationExecutor(new Application(), $this->root))->execute($plan);

        self::assertCount(1, $results);
        self::assertSame([VerificationResult::STATUS_SKIPPED, 0, false], [$results[0]->status, $results[0]->exitCode, $results[0]->required]);
        self::assertStringContainsString('graph-view.css: shown only on /__observatory', $results[0]->signal);
        // The Observatory's spec, and not the Explorer's.
        self::assertStringContainsString('opens it: packages/semitexa-dev/tests/E2E/observatory-graph.spec.ts (', $results[0]->signal);

        $passed = new VerificationResult(new VerificationTarget('lint', 'lint:lint:css-tokens', 'r', [self::VIEWER_CSS]), VerificationResult::STATUS_PASS, 0, 'clean');
        $verdict = new \ReflectionMethod(AiVerifyCommand::class, 'verdict');
        self::assertSame('pass', $verdict->invoke(new AiVerifyCommand(), [$passed, $results[0]]));
    }

    #[Test]
    public function a_surface_no_spec_opens_says_so(): void
    {
        unlink($this->root . '/packages/semitexa-dev/tests/E2E/observatory-graph.spec.ts');
        $target = DevSurfaceAssets::targets(['/__observatory' => [self::VIEWER_CSS]])[0];

        $result = DevSurfaceAssets::check($target, $this->root);

        self::assertSame([VerificationResult::STATUS_SKIPPED, false], [$result->status, $result->required]);
        self::assertStringContainsString('No E2E spec in this project opens that surface', $result->signal);
    }

    #[Test]
    public function the_directories_are_the_ones_the_asset_handlers_serve_from(): void
    {
        $served = [
            GraphViewerAssets::dir() => '/__observatory',
            ObservatoryHtmlRenderer::assetDir() => '/__observatory',
            ExplorerHtmlRenderer::assetDir() => '/__explorer',
        ];
        $found = [];
        foreach ($served as $directory => $surface) {
            // As a changed file of the workspace names it, and as an installed package does.
            $inPackage = (string) preg_replace('#^.*/(?:semitexa-|semitexa/)(?=(?:dev|project-graph)/resources/)#', '', $directory);
            self::assertSame($surface, DevSurfaceAssets::surfaceOf('packages/semitexa-' . $inPackage . '/any.css'), $directory);
            self::assertSame($surface, DevSurfaceAssets::surfaceOf('vendor/semitexa/' . $inPackage . '/any.css'), $directory);
            $found[$inPackage . '/'] = $surface;
        }
        self::assertSame(DevSurfaceAssets::SERVED, $found);

        // The toolbar is drawn on the project's own pages, and a theme's stylesheet on all of them.
        self::assertNull(DevSurfaceAssets::surfaceOf('packages/semitexa-dev/resources/toolbar/toolbar.css'));
        self::assertNull(DevSurfaceAssets::surfaceOf(self::THEME_CSS));
        self::assertNull(DevSurfaceAssets::surfaceOf('src/modules/Shop/resources/viewer/graph-view.css'));
    }
}

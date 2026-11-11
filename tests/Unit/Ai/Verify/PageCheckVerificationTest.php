<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\PageCheckResult;
use Semitexa\Dev\Application\Service\Ai\Verify\PageCheckTargets;
use Semitexa\Dev\Application\Service\Ai\Verify\PageRoutes;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationResult;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;

/**
 * A changed template or stylesheet names the pages it shows on, and each page
 * is judged by what the host's browser saw in light AND dark.
 *
 * REGRESSION (2026-11-07). A base layout linked no skin, and an invented token
 * put light text on white in dark mode; every test passed and no check opened
 * the page. The light run of that page passed: dark is what failed.
 */
final class PageCheckVerificationTest extends TestCase
{
    private const ROUTES = [
        ['path' => '/', 'module' => 'Guestbook'],
        ['path' => '/guestbook', 'module' => 'Guestbook'],
        ['path' => '/guestbook/{id}', 'module' => 'Guestbook'],
        ['path' => '/about', 'module' => 'Site'],
        ['path' => '/demo', 'module' => 'semitexa-demo'],
        ['path' => '/__observatory', 'module' => 'semitexa-dev'],
        ['path' => '/api/entries', 'module' => 'Guestbook'],
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/page-check-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/run/verify-e2e/run-1/pages', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_module_template_opens_that_modules_pages_only(): void
    {
        self::assertSame(['/', '/guestbook'], $this->paths('src/modules/Guestbook/src/Application/View/templates/pages/home.html.twig', ChangedFile::KIND_TEMPLATE));
    }

    #[Test]
    public function the_theme_opens_the_projects_pages_and_not_another_packages(): void
    {
        self::assertSame(['/', '/about', '/guestbook'], $this->paths('packages/semitexa-theme/src/Application/Static/css/layouts.css', ChangedFile::KIND_STYLESHEET));
    }

    /** REGRESSION (review dev#216): six other modules' pages passed for a demo template none of them renders. */
    #[Test]
    public function a_package_with_pages_of_its_own_opens_only_those(): void
    {
        self::assertSame(['/demo'], $this->paths('vendor/semitexa/demo/src/Application/View/templates/home.html.twig', ChangedFile::KIND_TEMPLATE));
        self::assertSame(['/demo'], $this->paths('packages/semitexa-demo/src/Application/Static/css/demo.css', ChangedFile::KIND_STYLESHEET));
    }

    /** REGRESSION (review dev#216): JSON endpoints took the cap, and a GET that signs in timed out on every run. */
    #[Test]
    public function only_a_route_that_renders_a_page_and_does_nothing_is_opened(): void
    {
        $page = ['path' => '/shop', 'methods' => ['GET'], 'accessType' => 'public'];
        self::assertTrue(PageRoutes::isPage($page));
        self::assertTrue(PageRoutes::isPage($page + ['produces' => ['text/html', 'application/json']]));
        self::assertFalse(PageRoutes::isPage(['produces' => ['application/json']] + $page), 'an API');
        foreach (['/auth/google/start', '/logout', '/reactive-ai/submit', '/newsletter/unsubscribe'] as $action) {
            self::assertFalse(PageRoutes::isPage(['path' => $action] + $page), "{$action} acts when opened");
        }
        self::assertFalse(PageRoutes::isPage(['accessType' => 'protected'] + $page));
        self::assertFalse(PageRoutes::isPage(['methods' => ['POST']] + $page));
    }

    #[Test]
    public function a_non_html_error_fails_rather_than_being_skipped(): void
    {
        file_put_contents($this->root . '/var/run/verify-e2e/run-1/pages/' . PageCheckResult::fileName('/broken'), json_encode(['path' => '/broken', 'modes' => ['light' => ['status' => 500, 'html' => false, 'findings' => [], 'stylesheets' => []], 'dark' => ['status' => 500, 'html' => false, 'findings' => [], 'stylesheets' => []]]]));

        self::assertSame(VerificationResult::STATUS_FAIL, $this->check('/broken', 'run-1')->status);
    }

    /** REGRESSION (review dev#216): a finding in light was dropped because dark could not be reached. */
    #[Test]
    public function a_finding_in_one_mode_fails_even_when_the_other_was_not_reached(): void
    {
        $this->seen('/half', ['light' => [500, ['color-contrast x2']], 'dark' => [0, []]]);

        $result = $this->check('/half', 'run-1');
        self::assertSame(VerificationResult::STATUS_FAIL, $result->status);
        self::assertStringContainsString('light: HTTP 500', (string) $result->signal);
        self::assertStringContainsString('could not be reached in dark', (string) $result->signal);
    }

    /** REGRESSION (review dev#216): a bare status passed with no evidence of any check. */
    #[Test]
    public function a_result_that_does_not_say_what_was_checked_is_no_pass(): void
    {
        file_put_contents($this->root . '/var/run/verify-e2e/run-1/pages/' . PageCheckResult::fileName('/bare'), json_encode(['path' => '/bare', 'modes' => ['light' => ['status' => 200], 'dark' => ['status' => 200]]]));

        $result = $this->check('/bare', 'run-1');
        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $result->status);
        self::assertStringContainsString('malformed', (string) $result->signal);
    }

    /** REGRESSION (review dev#216): a page sent to a clean sign-in page passed for a template it never showed. */
    #[Test]
    public function a_page_that_redirected_elsewhere_is_no_pass(): void
    {
        $view = ['status' => 200, 'html' => true, 'findings' => [], 'stylesheets' => [], 'landed' => '/login'];
        file_put_contents($this->root . '/var/run/verify-e2e/run-1/pages/' . PageCheckResult::fileName('/account'), json_encode(['path' => '/account', 'modes' => ['light' => $view, 'dark' => $view]]));

        $result = $this->check('/account', 'run-1');
        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $result->status);
        self::assertStringContainsString('redirected to /login', (string) $result->signal);
    }

    /** REGRESSION (review dev#216): a finding beside a redirect in the other mode must keep both */
    #[Test]
    public function a_finding_and_a_redirect_are_both_reported(): void
    {
        $light = ['status' => 200, 'html' => true, 'findings' => ['color-contrast x3'], 'stylesheets' => [], 'landed' => '/shop'];
        $dark = ['status' => 200, 'html' => true, 'findings' => [], 'stylesheets' => [], 'landed' => '/shop?view=other'];
        file_put_contents($this->root . '/var/run/verify-e2e/run-1/pages/' . PageCheckResult::fileName('/shop'), json_encode(['path' => '/shop', 'modes' => ['light' => $light, 'dark' => $dark]]));

        $result = $this->check('/shop', 'run-1');
        self::assertSame(VerificationResult::STATUS_FAIL, $result->status);
        self::assertStringContainsString('light: color-contrast x3', (string) $result->signal);
        self::assertStringContainsString('redirected to /shop?view=other in dark', (string) $result->signal, 'the same path with another query is another page');
    }

    /** REGRESSION (independent review): an error page loads no stylesheet, and that read as "does not load". */
    #[Test]
    public function an_error_page_fails_even_when_only_a_stylesheet_changed(): void
    {
        $this->seen('/err', ['light' => [200, []], 'dark' => [500, []]], []);

        $result = $this->check('/err', 'run-1', ['packages/semitexa-platform-ui/src/Application/Static/css/components.css']);
        self::assertSame(VerificationResult::STATUS_FAIL, $result->status);
        self::assertStringContainsString('dark: HTTP 500', (string) $result->signal);
    }

    #[Test]
    public function a_status_that_is_not_a_number_is_a_malformed_result(): void
    {
        $view = ['status' => '200', 'html' => true, 'findings' => [], 'stylesheets' => []];
        file_put_contents($this->root . '/var/run/verify-e2e/run-1/pages/' . PageCheckResult::fileName('/s'), json_encode(['path' => '/s', 'modes' => ['light' => $view, 'dark' => $view]]));

        self::assertStringContainsString('malformed', (string) $this->check('/s', 'run-1')->signal);
    }

    #[Test]
    public function a_spec_that_died_points_at_its_log(): void
    {
        file_put_contents($this->root . '/var/run/verify-e2e/run-1/pages.log', "Running 3 tests\nTest timeout of 60000ms exceeded.\n");

        self::assertStringContainsString('Test timeout of 60000ms exceeded.', (string) $this->check('/gone', 'run-1')->signal);
    }

    #[Test]
    public function only_a_page_a_browser_can_open_as_it_is(): void
    {
        $all = $this->paths('src/Application/View/templates/layout.html.twig', ChangedFile::KIND_TEMPLATE);
        self::assertSame(['/', '/about', '/guestbook'], $all, 'the project\'s pages, and only those a browser can open');

        self::assertNotContains('/guestbook/{id}', $all, 'a parameter it cannot fill');
        self::assertNotContains('/__observatory', $all, 'a dev surface');
        self::assertNotContains('/api/entries', $all, 'an API');
    }

    #[Test]
    public function php_a_deleted_file_and_minimal_scope_open_nothing(): void
    {
        self::assertSame([], $this->paths('src/modules/Guestbook/src/Application/Handler/Home.php', ChangedFile::KIND_HANDLER));
        self::assertSame([], PageCheckTargets::of([new ChangedFile('src/modules/Guestbook/x.css', ChangedFile::KIND_STYLESHEET, ChangedFile::STATUS_DELETED)], VerificationPlan::SCOPE_STANDARD, self::ROUTES));
        self::assertSame([], PageCheckTargets::of([new ChangedFile('src/modules/Guestbook/x.css', ChangedFile::KIND_STYLESHEET)], VerificationPlan::SCOPE_MINIMAL, self::ROUTES));
    }

    #[Test]
    public function the_pages_are_capped(): void
    {
        $routes = array_map(static fn (int $i): array => ['path' => "/p{$i}", 'module' => 'Site'], range(1, 20));

        self::assertCount(PageCheckTargets::LIMIT, PageCheckTargets::of([new ChangedFile('src/x.css', ChangedFile::KIND_STYLESHEET)], VerificationPlan::SCOPE_STANDARD, $routes));
    }

    #[Test]
    public function dark_alone_failing_fails_the_page_and_names_the_mode(): void
    {
        $this->seen('/guestbook', ['light' => [200, []], 'dark' => [200, ['color-contrast x65']]]);

        $result = $this->check('/guestbook', 'run-1');

        self::assertSame(VerificationResult::STATUS_FAIL, $result->status);
        self::assertStringContainsString('dark: color-contrast x65', (string) $result->signal);
        self::assertStringNotContainsString('light:', (string) $result->signal);
    }

    #[Test]
    public function a_clean_page_in_both_modes_passes(): void
    {
        $this->seen('/guestbook', ['light' => [200, []], 'dark' => [200, []]]);

        self::assertSame(VerificationResult::STATUS_PASS, $this->check('/guestbook', 'run-1')->status);
    }

    #[Test]
    public function a_server_error_fails_and_an_unreachable_page_is_incomplete(): void
    {
        $this->seen('/a', ['light' => [500, []], 'dark' => [500, []]]);
        $this->seen('/b', ['light' => [0, []], 'dark' => [0, []]]);

        self::assertSame(VerificationResult::STATUS_FAIL, $this->check('/a', 'run-1')->status);
        $unreachable = $this->check('/b', 'run-1');
        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $unreachable->status);
        self::assertStringContainsString('could not be reached', (string) $unreachable->signal);
    }

    /**
     * The page is a route the change declares: the running server answering 404
     * has not loaded it (tk-page-check-stale-route). A Guard run read the bare
     * "HTTP 404" as a failure and restarted around every verify after it.
     */
    #[Test]
    public function a_404_for_a_declared_route_is_a_server_that_has_not_loaded_it(): void
    {
        $this->seen('/guard', ['light' => [404, []], 'dark' => [404, []]]);
        // Another error, or 404 in one mode only, is the page's own answer.
        $this->seen('/half', ['light' => [404, []], 'dark' => [200, []]]);

        $stale = $this->check('/guard', 'run-1');
        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $stale->status);
        self::assertStringContainsString('the server has not loaded it', (string) $stale->signal);
        self::assertStringContainsString('bin/semitexa server:restart app', (string) $stale->signal);
        self::assertSame(VerificationResult::STATUS_FAIL, $this->check('/half', 'run-1')->status);
    }

    #[Test]
    public function the_restart_note_does_not_say_a_restart_could_change_nothing_when_pages_were_opened(): void
    {
        $report = new \Semitexa\Dev\Application\Service\Ai\Verify\VerifyReportSerializer();

        $withPages = $report->restartAdvice([], true)['note'];
        self::assertStringNotContainsString('none would have changed this result', $withPages);
        self::assertStringContainsString('the page checks: they open the running server', $withPages);
        self::assertStringContainsString('none would have changed this result', $report->restartAdvice([])['note']);
    }

    #[Test]
    public function a_page_seen_in_one_mode_only_is_not_a_pass(): void
    {
        file_put_contents($this->root . '/var/run/verify-e2e/run-1/pages/' . PageCheckResult::fileName('/x'), json_encode(['path' => '/x', 'modes' => ['light' => ['status' => 200, 'html' => true, 'findings' => [], 'stylesheets' => []]]]));

        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $this->check('/x', 'run-1')->status);
    }

    #[Test]
    public function without_a_host_run_or_a_result_it_is_incomplete_and_says_why(): void
    {
        self::assertStringContainsString('not started by bin/semitexa ai:verify', (string) $this->check('/x', null)->signal);

        file_put_contents($this->root . '/var/run/verify-e2e/run-1/pages.txt', "the page check filed nothing\n");
        $missing = $this->check('/x', 'run-1');
        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $missing->status);
        self::assertStringContainsString('the page check filed nothing', (string) $missing->signal);
    }

    #[Test]
    public function a_response_that_is_not_html_is_skipped(): void
    {
        file_put_contents($this->root . '/var/run/verify-e2e/run-1/pages/' . PageCheckResult::fileName('/feed'), json_encode(['path' => '/feed', 'modes' => ['light' => ['status' => 200, 'html' => false, 'findings' => [], 'stylesheets' => []], 'dark' => ['status' => 200, 'html' => false, 'findings' => [], 'stylesheets' => []]]]));

        self::assertSame(VerificationResult::STATUS_SKIPPED, $this->check('/feed', 'run-1')->status);
    }

    /**
     * REGRESSION (first live run, 2026-11-07). The 07.11 bug put back into
     * theme-demo.css passed on /theme-demo: that page never loads theme-demo.css,
     * so its clean view said nothing about the change.
     */
    #[Test]
    public function a_page_that_does_not_load_the_changed_stylesheet_is_no_pass(): void
    {
        $css = 'src/modules/ThemeDemo/src/Application/Static/css/theme-demo.css';
        $this->seen('/theme-demo', ['light' => [200, []], 'dark' => [200, []]], ['http://localhost/assets/skins/default/tokens.css']);

        $result = $this->check('/theme-demo', 'run-1', [$css]);
        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $result->status);
        self::assertStringContainsString('does not load theme-demo.css', (string) $result->signal);

        $this->seen('/theme-demo', ['light' => [200, []], 'dark' => [200, ['color-contrast x9']]], ['http://localhost/assets/theme-demo/css/theme-demo.css?v=1']);
        self::assertSame(VerificationResult::STATUS_FAIL, $this->check('/theme-demo', 'run-1', [$css])->status, 'loaded: judged');
        self::assertSame(VerificationResult::STATUS_FAIL, $this->check('/theme-demo', 'run-1', [$css, 'src/modules/ThemeDemo/x.html.twig'])->status, 'a template changed too: the page can show it');

        // REGRESSION (review dev#216): loaded in light only is not loaded; another module's file of the same name is not this one.
        $platform = 'packages/semitexa-platform-ui/src/Application/Static/css/components.css';
        $this->seen('/both', ['light' => [200, []], 'dark' => [200, []]]);
        $both = json_decode((string) file_get_contents($this->root . '/var/run/verify-e2e/run-1/pages/' . PageCheckResult::fileName('/both')), true);
        $both['modes']['light']['stylesheets'] = ['http://localhost/assets/platform-ui/css/components.css?v=1'];
        file_put_contents($this->root . '/var/run/verify-e2e/run-1/pages/' . PageCheckResult::fileName('/both'), json_encode($both));
        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $this->check('/both', 'run-1', [$platform])->status, 'dark did not load it');
        $this->seen('/other', ['light' => [200, []], 'dark' => [200, []]], ['http://localhost/assets/shop/components.css']);
        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $this->check('/other', 'run-1', [$platform])->status, 'a components.css of another directory');
        $this->seen('/skin', ['light' => [200, []], 'dark' => [200, []]], ['http://localhost/assets/skins/default/tokens.css']);
        self::assertSame(VerificationResult::STATUS_PASS, $this->check('/skin', 'run-1', ['packages/semitexa-theme/src/Application/Static/css/skins/default/tokens.css'])->status, 'a skin served from /assets/skins/');
    }

    /**
     * The spec writes what PageCheckResult reads; neither runs the other. A name changed on
     * one side alone would read as "the host filed nothing" on every page. The launcher's
     * side (bin/semitexa) is pinned in semitexa-ultimate, beside the copy it ships, so this
     * suite needs no installer checkout (review dev#216).
     */
    #[Test]
    public function the_spec_and_the_reader_agree(): void
    {
        // verify:accept-test-change the bin/semitexa assertions moved to semitexa-ultimate PageCheckLauncherTest, beside the copy it ships
        $package = dirname(__DIR__, 4);
        $spec = (string) file_get_contents($package . '/resources/page-check/page-check.spec.ts');
        $config = (string) file_get_contents($package . '/resources/page-check/playwright.config.ts');

        foreach (['PAGE_CHECK_PAGES', 'PAGE_CHECK_OUT'] as $env) {
            self::assertStringContainsString("process.env.{$env}", $spec);
        }
        self::assertStringContainsString("createHash('sha256').update(path).digest('hex')", $spec, 'the file name PageCheckResult::fileName() reads');
        self::assertStringContainsString('`${file}.json`', $spec);
        self::assertStringContainsString("['light', 'dark']", $spec, 'both modes, as PageCheckResult::MODES');
        self::assertSame(['light', 'dark'], PageCheckResult::MODES);
        foreach (['status', 'html', 'findings', 'stylesheets', 'landed'] as $key) {
            self::assertMatchesRegularExpression('/\b' . $key . '\b/', $spec, "the spec writes {$key}");
        }
        self::assertStringContainsString("testMatch: ['page-check.spec.ts']", $config, 'the config runs this spec and none of the project');
    }

    /** @return list<string> */
    private function paths(string $file, string $kind): array
    {
        return array_map(
            static fn (VerificationTarget $t): string => (string) $t->commandInput['path'],
            PageCheckTargets::of([new ChangedFile($file, $kind)], VerificationPlan::SCOPE_STANDARD, self::ROUTES),
        );
    }

    /**
     * @param array<string, array{int, list<string>}> $modes
     * @param list<string>|null $stylesheets what the page loaded
     */
    private function seen(string $path, array $modes, ?array $stylesheets = null): void
    {
        $out = [];
        foreach ($modes as $mode => [$status, $findings]) {
            $out[$mode] = ['status' => $status, 'html' => true, 'findings' => $findings, 'stylesheets' => $stylesheets ?? []] + ($status === 0 ? ['error' => 'net::ERR_CONNECTION_REFUSED'] : []);
        }
        file_put_contents($this->root . '/var/run/verify-e2e/run-1/pages/' . PageCheckResult::fileName($path), json_encode(['path' => $path, 'modes' => $out]));
    }

    /** @param list<string> $triggers the changed files that chose the page */
    private function check(string $path, ?string $run, array $triggers = []): VerificationResult
    {
        $target = new VerificationTarget(VerificationTarget::TYPE_PAGE, "page:{$path}", 'test', $triggers, commandInput: ['path' => $path]);

        return (new PageCheckResult($this->root, $run))->check($target);
    }
}

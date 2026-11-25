<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\PageCheck;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\E2eSpecResultCheck;
use Semitexa\Dev\Application\Service\Ai\Verify\PageCheckResult;
use Semitexa\Dev\Application\Service\Ai\Verify\PageCheckTargets;
use Semitexa\Dev\Application\Service\Ai\Verify\PageRoutes;
use Semitexa\Dev\Application\Service\Ai\Verify\PageUsers;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationExecutor;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationResult;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;
use Semitexa\Dev\Application\Service\Ai\Verify\VerifyReportSerializer;
use Semitexa\Dev\Tests\Unit\Ai\Verify\Fixture\FixedProcessRunner;
use Semitexa\Dev\Tests\Unit\Ai\Verify\PageCheck\Fixture\DeferredPageResource;
use Semitexa\Dev\Tests\Unit\Ai\Verify\PageCheck\Fixture\PlainResource;
use Semitexa\Dev\Tests\Unit\Ai\Verify\PageCheck\Fixture\QueuePageResource;
use Semitexa\Dev\Tests\Unit\Ai\Verify\PageCheck\Fixture\SearchFilterSlot;
use Semitexa\Ssr\Application\Service\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Attribute\AsSlotResource;
use Symfony\Component\Console\Application;

/**
 * The page check opens pages that can show the change. The static chain chooses
 * pages; only a stylesheet seen loading excuses a page sent to another site
 * (tk-verify-page-check-off-site-redirect-incomplete).
 *
 * REGRESSION (2026-11-24, measured on the dev server). A semitexa-demo template
 * opened "/", "/blog" and six "/blog/*" (the package's shortest paths), never
 * the page that renders it, and the blog answers 301 to another site: every one
 * came back incomplete. A semitexa-showcase-kit stylesheet opened the project's
 * own pages, none of which loads it.
 */
final class PagesThatShowTheChangeTest extends TestCase
{
    private const DEMO = '@project-layouts-semitexa-demo/';

    private const ROUTES = [
        ['path' => '/', 'module' => 'semitexa-demo', 'template' => self::DEMO . 'pages/home.html.twig'],
        ['path' => '/blog', 'module' => 'semitexa-demo', 'template' => ''],
        ['path' => '/blog/a', 'module' => 'semitexa-demo', 'template' => ''],
        ['path' => '/demo/platform/queue', 'module' => 'semitexa-demo', 'template' => self::DEMO . 'platform/queue.html.twig'],
        ['path' => '/demo/platform/config', 'module' => 'semitexa-demo', 'template' => self::DEMO . 'platform/config.html.twig'],
        ['path' => '/demo/rendering/deferred', 'module' => 'semitexa-demo', 'template' => self::DEMO . 'pages/deferred.html.twig'],
        ['path' => '/playground', 'module' => 'Playground', 'template' => '@project-layouts-Playground/pages/playground.html.twig'],
    ];

    private const NAV = 'packages/semitexa-showcase-kit/src/Application/Static/css/nav.css';

    private string $root;

    /** @var array<mixed>|null the slot registry as it was before a test registered into it */
    private ?array $slotsBefore = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/page-users-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/run/verify-e2e/run-1/pages', 0777, true);
        $demo = 'packages/semitexa-demo/src/Application/View/templates/';
        $this->write($demo . 'pages/home.html.twig', "{% extends '@project-layouts-ShowcaseKit/layouts/showcase.html.twig' %}");
        $this->write($demo . 'platform/queue.html.twig', "{% extends '" . self::DEMO . "platform/_feature.html.twig' %}");
        $this->write($demo . 'platform/config.html.twig', "{% extends '" . self::DEMO . "platform/_feature.html.twig' %}");
        $this->write($demo . 'platform/_feature.html.twig', "{% extends '@project-layouts-ShowcaseKit/layouts/feature.html.twig' %}");
        $this->write($demo . 'pages/deferred.html.twig', "<html>{% include '@project-layouts-theme-base/partials/head/theme-switcher.html.twig' %}</html>");
        $this->write('src/modules/Playground/src/Application/View/templates/pages/playground.html.twig', '<html></html>');
        $this->write('packages/semitexa-showcase-kit/composer.json', (string) json_encode(['extra' => ['semitexa-module' => ['name' => 'ShowcaseKit', 'template_alias' => 'project-layouts-ShowcaseKit']]]));
        $this->write('packages/semitexa-showcase-kit/src/Application/Static/assets.json', (string) json_encode(['module' => 'showcase-kit']));
        $this->write('packages/semitexa-theme/composer.json', (string) json_encode(['extra' => ['semitexa-module' => ['name' => 'theme-base', 'template_alias' => 'theme-base']]]));
    }

    protected function tearDown(): void
    {
        // Back to what the registry held, whatever the test asserted: later tests inherit nothing.
        if ($this->slotsBefore !== null) {
            (new \ReflectionProperty(LayoutSlotRegistry::class, 'slots'))->setValue(null, $this->slotsBefore);
            $this->slotsBefore = null;
        }
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_template_opens_the_page_that_renders_it_and_not_the_packages_shortest_paths(): void
    {
        self::assertSame(['/demo/platform/queue'], $this->paths('packages/semitexa-demo/src/Application/View/templates/platform/queue.html.twig', ChangedFile::KIND_TEMPLATE));
    }

    #[Test]
    public function a_partial_opens_the_pages_that_extend_it(): void
    {
        self::assertSame(['/demo/platform/queue', '/demo/platform/config'], $this->paths('packages/semitexa-demo/src/Application/View/templates/platform/_feature.html.twig', ChangedFile::KIND_TEMPLATE));
    }

    #[Test]
    public function a_template_no_route_is_known_to_render_keeps_its_packages_pages(): void
    {
        $this->write('packages/semitexa-demo/src/Application/View/templates/partials/orphan.html.twig', '');

        self::assertSame(['/', '/blog', '/blog/a', '/demo/platform/queue', '/demo/platform/config', '/demo/rendering/deferred'], $this->paths('packages/semitexa-demo/src/Application/View/templates/partials/orphan.html.twig', ChangedFile::KIND_TEMPLATE));
    }

    #[Test]
    public function a_stylesheet_of_a_package_with_no_route_opens_the_pages_built_on_its_layouts(): void
    {
        self::assertSame(['/', '/demo/platform/queue', '/demo/platform/config'], $this->paths(self::NAV, ChangedFile::KIND_STYLESHEET));
        self::assertSame(['/', '/demo/platform/queue', '/demo/platform/config'], $this->paths('packages/semitexa-showcase-kit/src/Application/View/templates/layouts/feature.html.twig', ChangedFile::KIND_TEMPLATE));
    }

    #[Test]
    public function a_page_that_links_the_packages_asset_is_one_of_them(): void
    {
        $this->write('src/modules/Playground/src/Application/View/templates/pages/playground.html.twig', "<link href=\"{{ asset('css/nav.css', 'showcase-kit') }}\">");

        self::assertContains('/playground', $this->paths(self::NAV, ChangedFile::KIND_STYLESHEET));
    }

    /** Including the theme's switcher links none of its stylesheets: the project's pages, as before. */
    #[Test]
    public function including_a_partial_is_not_being_built_on_the_package(): void
    {
        self::assertSame(['/playground'], $this->paths('packages/semitexa-theme/src/Application/Static/css/layouts.css', ChangedFile::KIND_STYLESHEET));
    }

    #[Test]
    public function a_stylesheet_of_a_package_with_routes_keeps_its_own_pages(): void
    {
        self::assertSame([], (new PageUsers($this->root, self::ROUTES))->builtOn('packages/semitexa-demo/src/Application/Static/css/header.css'));
    }

    #[Test]
    public function a_route_names_the_template_its_resource_renders(): void
    {
        self::assertSame('@project-layouts-semitexa-demo/platform/queue.html.twig', PageRoutes::templateOf(QueuePageResource::class));
        self::assertSame('', PageRoutes::templateOf(PlainResource::class));
        self::assertSame('', PageRoutes::templateOf(null));
    }

    /**
     * REGRESSION (2026-11-24, rcpt-20261124-134431-f66e95). The template of a deferred slot is
     * named by its #[AsSlotResource], not by the page's template: it matched no page, the plan
     * fell back to the package's shortest paths, its blog, and every one came back incomplete.
     */
    #[Test]
    public function a_slot_template_opens_the_page_that_renders_the_slot(): void
    {
        $this->registerSlots(SearchFilterSlot::class);
        $slots = PageRoutes::slotTemplatesOf(DeferredPageResource::class);
        self::assertSame([self::DEMO . 'deferred/search-filter.html.twig', self::DEMO . 'deferred/search-filter.skeleton.html.twig'], $slots);
        self::assertSame([], PageRoutes::slotTemplatesOf(QueuePageResource::class), 'a slot is on the page whose handle it names');
        self::assertSame([], PageRoutes::slotTemplatesOf(PlainResource::class));
        self::assertSame([], PageRoutes::slotTemplatesOf(null));

        $demo = 'packages/semitexa-demo/src/Application/View/templates/';
        $this->write($demo . 'deferred/search-filter.html.twig', "{% include '" . self::DEMO . "deferred/_option.html.twig' %}");
        $this->write($demo . 'deferred/search-filter.skeleton.html.twig', '');
        $this->write($demo . 'deferred/_option.html.twig', '');
        $routes = self::ROUTES;
        $routes[5]['slots'] = $slots;
        self::assertSame('/demo/rendering/deferred', $routes[5]['path']);
        $users = new PageUsers($this->root, $routes);

        foreach (['search-filter.html.twig', 'search-filter.skeleton.html.twig', '_option.html.twig'] as $template) {
            self::assertSame(['/demo/rendering/deferred'], $users->rendering($demo . 'deferred/' . $template), $template);
            $targets = PageCheckTargets::of([new ChangedFile($demo . 'deferred/' . $template, ChangedFile::KIND_TEMPLATE)], VerificationPlan::SCOPE_STANDARD, $routes, $users);
            self::assertSame(['/demo/rendering/deferred'], array_map(static fn (VerificationTarget $t): string => (string) $t->commandInput['path'], $targets), "{$template}: no fallback to /blog");
        }
        // The page's own template is still its page's.
        self::assertSame(['/demo/rendering/deferred'], $users->rendering($demo . 'pages/deferred.html.twig'));

        // A choice only: the page passing excuses no page sent to another site for the slot's template.
        $slot = $demo . 'deferred/search-filter.html.twig';
        $this->seen('/demo/rendering/deferred', $this->clean());
        $this->seen('/blog', $this->offSite());
        $settled = $this->checker()->settle([$this->check('/blog', [$slot]), $this->check('/demo/rendering/deferred', [$slot])]);
        self::assertTrue($settled[0]->required);
        self::assertStringContainsString('no other opened page showed search-filter.html.twig', $settled[0]->signal);

        // A slot template that cannot be read fails with its path.
        $path = $this->root . '/' . $slot;
        try {
            (new PageUsers($this->root, $routes, static fn (string $read): string|false => $read === $path ? false : file_get_contents($read)))->rendering($demo . 'deferred/_option.html.twig');
            self::fail('an unreadable slot template was read as an empty one');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($path, $e->getMessage());
        }
    }

    /** tearDown puts the slot registry back: the registration above is gone, and nothing else is. */
    #[Test]
    public function a_test_that_registers_a_slot_leaves_the_registry_as_it_found_it(): void
    {
        $property = new \ReflectionProperty(LayoutSlotRegistry::class, 'slots');
        // As the test found it, before its own registrations: what the cleanup puts back.
        $original = $property->getValue();
        try {
            LayoutSlotRegistry::register(handle: 'page_check_fixture_kept', slot: 'kept', template: '@x/kept.html.twig');
            $before = $property->getValue();
            $this->registerSlots(SearchFilterSlot::class);
            self::assertNotSame([], LayoutSlotRegistry::getSlotsForHandle('page_check_fixture_deferred'));
            $this->tearDown();
            self::assertSame($before, $property->getValue());
            self::assertSame([], LayoutSlotRegistry::getSlotsForHandle('page_check_fixture_deferred'));
            self::assertNotSame([], LayoutSlotRegistry::getSlotsForHandle('page_check_fixture_kept'), 'what was there before stays');
        } finally {
            // Whichever assertion failed: the registry as found, and nothing left for tearDown to restore
            // (its snapshot would hold the fixture registered above; review, semitexa-dev#313).
            $property->setValue(null, $original);
            $this->slotsBefore = null;
            $this->setUp();
        }
    }

    /**
     * Review, dev#313: a slot template in another module's namespace is no file under this module's
     * template root. Read there, it matched demo's unrelated partials/orphan.html.twig and replaced
     * the package's fallback pages with one false match.
     */
    #[Test]
    public function a_slot_template_of_another_modules_namespace_keeps_the_fallback(): void
    {
        $demo = 'packages/semitexa-demo/src/Application/View/templates/';
        $this->write($demo . 'partials/orphan.html.twig', '');
        $this->write($demo . 'partials/own.html.twig', '');
        $routes = self::ROUTES;
        self::assertSame(['/blog', ''], [$routes[1]['path'], $routes[1]['template']]);
        $routes[1]['slots'] = ['@project-layouts-Playground/partials/orphan.html.twig'];
        $users = new PageUsers($this->root, $routes);

        self::assertSame([], $users->rendering($demo . 'partials/orphan.html.twig'));
        $targets = PageCheckTargets::of([new ChangedFile($demo . 'partials/orphan.html.twig', ChangedFile::KIND_TEMPLATE)], VerificationPlan::SCOPE_STANDARD, $routes, $users);
        self::assertSame(['/', '/blog', '/blog/a', '/demo/platform/queue', '/demo/platform/config', '/demo/rendering/deferred'], array_map(static fn (VerificationTarget $t): string => (string) $t->commandInput['path'], $targets), 'the package fallback, not one false match');

        // The same with a page template beside it: the foreign slot is still no demo file.
        $routes[5]['slots'] = ['@project-layouts-Playground/partials/orphan.html.twig'];
        self::assertSame([], (new PageUsers($this->root, $routes))->rendering($demo . 'partials/orphan.html.twig'));

        // The module's own slot template stays eligible when the page names no template.
        $routes[1]['slots'] = ['@project-layouts-Playground/partials/orphan.html.twig', self::DEMO . 'partials/own.html.twig'];
        self::assertSame(['/blog'], (new PageUsers($this->root, $routes))->rendering($demo . 'partials/own.html.twig'));
    }

    /**
     * Independent review, dev#313: the template loader takes the bare alias, `@<directory>` and
     * `@<name>`, as it takes `@project-layouts-…`. A page written that way keeps its match.
     */
    #[Test]
    public function a_page_template_under_the_bare_alias_still_matches(): void
    {
        $hello = 'src/modules/Hello/src/Application/View/templates/';
        $this->write($hello . 'pages/hello.html.twig', "{% include '@Hello/partials/guide.html.twig' %}");
        $this->write($hello . 'partials/guide.html.twig', '');
        $kit = 'packages/semitexa-showcase-kit/src/Application/View/templates/';
        $this->write($kit . 'pages/kit.html.twig', "{% include '@semitexa-showcase-kit/partials/card.html.twig' %}");
        $this->write($kit . 'partials/card.html.twig', '');
        $routes = [
            ['path' => '/hello', 'module' => 'Hello', 'template' => '@Hello/pages/hello.html.twig'],
            ['path' => '/kit', 'module' => 'semitexa-showcase-kit', 'template' => '@ShowcaseKit/pages/kit.html.twig'],
        ];
        $users = new PageUsers($this->root, $routes);

        self::assertSame(['/hello'], $users->rendering($hello . 'pages/hello.html.twig'));
        self::assertSame(['/hello'], $users->rendering($hello . 'partials/guide.html.twig'));
        self::assertSame(['/kit'], $users->rendering($kit . 'pages/kit.html.twig'), 'by the name in composer.json');
        self::assertSame(['/kit'], $users->rendering($kit . 'partials/card.html.twig'), 'by the directory');
    }

    /** Independent review's cap rule holds for a slot's page: it comes before another file's fallback pages. */
    #[Test]
    public function the_page_of_a_slot_template_comes_before_any_fallback_page(): void
    {
        $demo = 'packages/semitexa-demo/src/Application/View/templates/';
        $this->write($demo . 'deferred/search-filter.html.twig', '');
        $routes = self::ROUTES;
        $routes[5]['slots'] = [self::DEMO . 'deferred/search-filter.html.twig'];
        for ($i = 1; $i <= PageCheckTargets::LIMIT + 2; $i++) {
            $routes[] = ['path' => "/p{$i}", 'module' => 'Site', 'template' => ''];
        }
        $targets = PageCheckTargets::of(
            [new ChangedFile('src/x.css', ChangedFile::KIND_STYLESHEET), new ChangedFile($demo . 'deferred/search-filter.html.twig', ChangedFile::KIND_TEMPLATE)],
            VerificationPlan::SCOPE_STANDARD,
            $routes,
            new PageUsers($this->root, $routes),
        );

        self::assertCount(PageCheckTargets::LIMIT, $targets);
        self::assertSame('/demo/rendering/deferred', $targets[0]->commandInput['path']);
    }

    /** Only a stylesheet seen loading excuses an off-site page: the browser files what each page loaded. */
    #[Test]
    public function a_page_sent_to_another_site_is_excused_when_another_page_was_seen_loading_the_stylesheet(): void
    {
        $this->seen('/blog', $this->offSite());
        $this->seen('/', $this->clean(['http://localhost/assets/showcase-kit/css/nav.css?v=93e30410b6eb']));
        $blog = $this->check('/blog', [self::NAV]);

        // On its own it is no pass and no skip: nothing showed the change.
        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $blog->status);
        self::assertTrue($blog->required);
        self::assertSame('/blog answers 301 to https://semitexa.com/blog in light and dark: another site, which cannot show the change', $blog->signal);

        $settled = $this->checker()->settle([$blog, $this->check('/', [self::NAV])]);
        self::assertSame(VerificationResult::STATUS_SKIPPED, $settled[0]->status, 'never a pass');
        self::assertFalse($settled[0]->required);
        self::assertStringContainsString('another opened page showed it', $settled[0]->signal);
        self::assertSame(VerificationResult::STATUS_PASS, $settled[1]->status);
        self::assertTrue((new VerifyReportSerializer())->completed($settled));
    }

    #[Test]
    public function a_plan_whose_every_page_went_to_another_site_is_incomplete(): void
    {
        $template = 'packages/semitexa-demo/src/Application/View/templates/partials/orphan.html.twig';
        $this->seen('/blog', $this->offSite());
        $this->seen('/blog/a', $this->offSite('https://semitexa.com/blog/a'));

        foreach ($this->checker()->settle([$this->check('/blog', [$template]), $this->check('/blog/a', [$template])]) as $result) {
            self::assertSame(VerificationResult::STATUS_INCOMPLETE, $result->status);
            self::assertTrue($result->required);
            self::assertStringContainsString('no other opened page showed orphan.html.twig', $result->signal);
        }
    }

    /**
     * The static chain chooses pages; it is never proof. A page sent to another site for a
     * changed template stays incomplete, even when another page passed and its chain names
     * that template. Review, dev#312: `{% include ['present', 'queue'] %}` renders the first
     * that exists, so home, which the chain holds queue for, never rendered it.
     */
    #[Test]
    public function a_template_never_excuses_a_page_sent_to_another_site(): void
    {
        $demo = 'packages/semitexa-demo/src/Application/View/templates/';
        $this->write($demo . 'partials/present.html.twig', '');
        $this->write($demo . 'pages/home.html.twig', "{% extends '@project-layouts-ShowcaseKit/layouts/showcase.html.twig' %}\n"
            . "{% include ['" . self::DEMO . "partials/present.html.twig', '" . self::DEMO . "platform/queue.html.twig'] %}");
        $queue = $demo . 'platform/queue.html.twig';

        // The plan opens both pages whose chain names it: that is a choice of what to open.
        $targets = PageCheckTargets::of([new ChangedFile($queue, ChangedFile::KIND_TEMPLATE)], VerificationPlan::SCOPE_STANDARD, self::ROUTES, new PageUsers($this->root, self::ROUTES));
        self::assertSame(['/', '/demo/platform/queue'], array_map(static fn (VerificationTarget $t): string => (string) $t->commandInput['path'], $targets));
        self::assertSame(['path', 'declared_in'], array_keys($targets[0]->commandInput), 'the plan hands the check no claim about what a page renders');

        $this->seen('/', $this->clean());
        $this->seen('/demo/platform/queue', $this->clean());
        $this->seen('/blog', $this->offSite());
        foreach (['the list include', 'the page whose resource names it'] as $n => $case) {
            // The targets as the plan made them, with whatever it says of them.
            $settled = $this->checker()->settle([$this->check('/blog', [$queue]), $this->checker()->check($targets[$n])]);
            self::assertSame(VerificationResult::STATUS_PASS, $settled[1]->status, $case);
            self::assertSame(VerificationResult::STATUS_INCOMPLETE, $settled[0]->status, $case);
            self::assertTrue($settled[0]->required, $case);
            self::assertStringContainsString('no other opened page showed queue.html.twig', $settled[0]->signal, $case);
            self::assertFalse((new VerifyReportSerializer())->completed($settled), $case);
        }

        // A stylesheet beside it that was seen loading does not carry the template through.
        $this->seen('/', $this->clean(['http://localhost/assets/showcase-kit/css/nav.css?v=93e30410b6eb']));
        $settled = $this->checker()->settle([$this->check('/blog', [self::NAV, $queue]), $this->check('/', [self::NAV, $queue])]);
        self::assertTrue($settled[0]->required);
        self::assertStringEndsWith('no other opened page showed queue.html.twig', $settled[0]->signal, 'the stylesheet was shown; the template is what holds it');
    }

    /**
     * REGRESSION (2026-11-25, measured: `ai:verify --list-pages` for semitexa-demo's
     * components/previews/execution-arena.html.twig printed "/ /blog /demo/videos /blog/…" and
     * not /demo/events/arena). No template includes a preview: the page's handler names it,
     * `->withResultPreviewTemplate('@project-layouts-semitexa-demo/components/previews/…')`.
     */
    #[Test]
    public function a_template_the_routes_handler_names_opens_that_page_first(): void
    {
        $demo = 'packages/semitexa-demo/src/Application/View/templates/';
        $this->write($demo . 'components/previews/execution-arena.html.twig', "{% include '" . self::DEMO . "components/previews/_lane.html.twig' %}");
        $this->write($demo . 'components/previews/_lane.html.twig', '');
        $this->write($demo . 'components/previews/bare.html.twig', '');
        $this->write($demo . 'components/previews/commented.html.twig', '');
        $this->write($demo . 'components/previews/inside.html.twig', '');
        $this->write($demo . 'partials/orphan.html.twig', '');
        $handler = 'packages/semitexa-demo/src/Application/Handler/PayloadHandler/Async/ExecutionArenaHandler.php';
        $this->write($handler, <<<'PHP'
            <?php
            // '@project-layouts-semitexa-demo/components/previews/commented.html.twig'
            return $resource
                ->withTitle('see @project-layouts-semitexa-demo/components/previews/inside.html.twig for it')
                ->withResultPreviewTemplate('@project-layouts-semitexa-demo/components/previews/execution-arena.html.twig', [])
                ->withAside("components/previews/bare.html.twig")
                ->withOther('@project-layouts-Playground/components/previews/execution-arena.html.twig');
            PHP);
        $routes = [...self::ROUTES, ['path' => '/demo/events/arena', 'module' => 'semitexa-demo', 'template' => '', 'sources' => [$this->root . '/' . $handler]]];
        $users = new PageUsers($this->root, $routes);
        $pathsOf = static fn (array $files): array => array_map(
            static fn (VerificationTarget $t): string => (string) $t->commandInput['path'],
            PageCheckTargets::of(array_map(static fn (string $file): ChangedFile => new ChangedFile($file, ChangedFile::KIND_TEMPLATE), $files), VerificationPlan::SCOPE_STANDARD, $routes, $users),
        );

        foreach (['execution-arena.html.twig' => 'named by the handler', '_lane.html.twig' => 'included by the one it names', 'bare.html.twig' => 'named with no namespace'] as $template => $case) {
            self::assertSame(['/demo/events/arena'], $pathsOf([$demo . 'components/previews/' . $template]), "{$case}: that page, and no fallback to /blog");
        }
        // Chosen, so before another file's fallback pages.
        self::assertSame('/demo/events/arena', $pathsOf([$demo . 'partials/orphan.html.twig', $demo . 'components/previews/execution-arena.html.twig'])[0]);
        // Only a whole string literal is a name: a comment and a sentence are none.
        self::assertSame([], $users->rendering($demo . 'components/previews/commented.html.twig'));
        self::assertSame([], $users->rendering($demo . 'components/previews/inside.html.twig'));
        // Another module's name is no file of this one: the Playground template of the same path is not the page's.
        $this->write('src/modules/Playground/src/Application/View/templates/components/previews/execution-arena.html.twig', '');
        self::assertSame([], $users->rendering('src/modules/Playground/src/Application/View/templates/components/previews/execution-arena.html.twig'));

        // A handler that cannot be read fails with its path, as a template does.
        $path = $this->root . '/' . $handler;
        try {
            (new PageUsers($this->root, $routes, static fn (string $read): string|false => $read === $path ? false : file_get_contents($read)))->rendering($demo . 'partials/orphan.html.twig');
            self::fail('an unreadable handler was read as an empty one');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($path, $e->getMessage());
        }
    }

    #[Test]
    public function a_route_carries_the_files_of_its_handlers_and_its_resource(): void
    {
        $files = PageRoutes::sourcesOf(['responseClass' => QueuePageResource::class, 'handlers' => [['class' => PlainResource::class], ['class' => 'No\\Such\\Handler'], ['class' => QueuePageResource::class]]]);

        self::assertSame([(string) (new \ReflectionClass(QueuePageResource::class))->getFileName(), (string) (new \ReflectionClass(PlainResource::class))->getFileName()], $files);
        self::assertSame([], PageRoutes::sourcesOf(['path' => '/']));

        // Discovery's plain routes carry no handlers: the plan has to ask for the enriched ones.
        $discovery = $this->createMock(\Semitexa\Core\Discovery\AttributeDiscovery::class);
        $discovery->method('getRoutes')->willReturn([['path' => '/demo/events/arena', 'methods' => ['GET'], 'accessType' => 'public', 'class' => PlainResource::class, 'responseWith' => QueuePageResource::class]]);
        $discovery->method('getEnrichedRoutes')->willReturn([['path' => '/demo/events/arena', 'methods' => ['GET'], 'accessType' => 'public', 'class' => PlainResource::class, 'responseClass' => QueuePageResource::class, 'handlers' => [['class' => PlainResource::class]]]]);
        self::assertSame($files, PageRoutes::of($discovery, null)[0]['sources']);
    }

    /** Independent review: the cap cut in changed-file order, so one file's fallback pages pushed out another's exact page. */
    #[Test]
    public function the_pages_the_templates_chose_come_before_any_fallback_page(): void
    {
        $routes = self::ROUTES;
        for ($i = 1; $i <= PageCheckTargets::LIMIT + 2; $i++) {
            $routes[] = ['path' => "/p{$i}", 'module' => 'Site', 'template' => ''];
        }
        $template = 'packages/semitexa-demo/src/Application/View/templates/platform/queue.html.twig';
        $targets = PageCheckTargets::of(
            [new ChangedFile('src/x.css', ChangedFile::KIND_STYLESHEET), new ChangedFile($template, ChangedFile::KIND_TEMPLATE)],
            VerificationPlan::SCOPE_STANDARD,
            $routes,
            new PageUsers($this->root, $routes),
        );

        self::assertCount(PageCheckTargets::LIMIT, $targets);
        self::assertSame('/demo/platform/queue', $targets[0]->commandInput['path']);
        self::assertSame('/p1', $targets[1]->commandInput['path']);
    }

    /** The executor settles the run: without it the excused page stays incomplete in every real run. */
    #[Test]
    public function the_executor_settles_the_pages_of_a_run(): void
    {
        $this->seen('/blog', $this->offSite());
        $this->seen('/', $this->clean(['http://localhost/assets/showcase-kit/css/nav.css?v=93e30410b6eb']));
        $plan = new VerificationPlan(VerificationPlan::SCOPE_STANDARD, VerificationPlan::SCOPE_STANDARD, [new ChangedFile(self::NAV, ChangedFile::KIND_STYLESHEET)], [
            $this->target('/blog', [self::NAV]),
            $this->target('/', [self::NAV]),
        ]);

        $before = getenv(E2eSpecResultCheck::RUN_ENV);
        putenv(E2eSpecResultCheck::RUN_ENV . '=run-1');
        try {
            $results = (new VerificationExecutor(new Application(), $this->root, new FixedProcessRunner(['exit' => 0, 'output' => ''])))->execute($plan);
        } finally {
            putenv(E2eSpecResultCheck::RUN_ENV . ($before === false ? '' : '=' . $before));
        }

        self::assertSame(VerificationResult::STATUS_SKIPPED, $results[0]->status);
        self::assertFalse($results[0]->required);
        self::assertSame(VerificationResult::STATUS_PASS, $results[1]->status);
    }

    /**
     * Review, dev#312: the changed stylesheet is its asset module and its path. Another module's
     * file of the same name is not it, so it neither passes the page nor excuses another one.
     */
    #[Test]
    public function another_modules_stylesheet_of_the_same_name_is_not_the_changed_one(): void
    {
        $this->seen('/blog', $this->offSite());
        $this->seen('/', $this->clean(['http://localhost/assets/other-kit/css/nav.css?v=1']));
        $this->seen('/about', $this->clean(['http://localhost/assets/other-kit/css/nav.css?v=1', 'http://localhost/assets/Site/css/site.css']));
        $site = 'src/modules/Site/src/Application/Static/css/site.css';
        $this->write('src/modules/Site/src/Application/Static/assets.json', (string) json_encode(['module' => 'Site']));

        $other = $this->check('/', [self::NAV]);
        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $other->status);
        self::assertStringContainsString('does not load nav.css', $other->signal);

        // /about passes for site.css, which it loads; that says nothing of nav.css.
        $settled = $this->checker()->settle([$this->check('/blog', [self::NAV, $site]), $this->check('/about', [self::NAV, $site])]);
        self::assertSame(VerificationResult::STATUS_PASS, $settled[1]->status);
        self::assertTrue($settled[0]->required);
        self::assertStringContainsString('no other opened page showed nav.css', $settled[0]->signal);

        // The file itself, whatever its version query: shown.
        $this->seen('/', $this->clean(['http://localhost/assets/showcase-kit/css/nav.css?v=93e30410b6eb']));
        self::assertSame(VerificationResult::STATUS_PASS, $this->check('/', [self::NAV])->status);
        self::assertFalse($this->checker()->settle([$this->check('/blog', [self::NAV]), $this->check('/', [self::NAV])])[0]->required);

        // A manifest that names no module identifies nothing: no href counts as the file.
        $this->write('packages/semitexa-showcase-kit/src/Application/Static/assets.json', '{}');
        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $this->check('/', [self::NAV])->status);
    }

    /** Review, dev#312: home is built on showcase.html.twig; a change to feature.html.twig opens it, and its pass excuses nothing. */
    #[Test]
    public function a_page_on_a_sibling_layout_is_opened_and_excuses_nothing(): void
    {
        $layout = 'packages/semitexa-showcase-kit/src/Application/View/templates/layouts/feature.html.twig';
        self::assertSame(['/', '/demo/platform/queue', '/demo/platform/config'], $this->paths($layout, ChangedFile::KIND_TEMPLATE));

        $this->seen('/', $this->clean());
        $this->seen('/blog', $this->offSite());
        self::assertTrue($this->checker()->settle([$this->check('/blog', [$layout]), $this->check('/', [$layout])])[0]->required);
    }

    /** Review, dev#312: a gate that cannot read a file fails; it does not go on as if the file were empty. */
    #[Test]
    public function a_template_or_metadata_that_cannot_be_read_fails_with_its_path(): void
    {
        $queue = $this->root . '/packages/semitexa-demo/src/Application/View/templates/platform/queue.html.twig';
        $unreadable = new PageUsers($this->root, self::ROUTES, static fn (string $path): string|false => $path === $queue ? false : file_get_contents($path));
        try {
            $unreadable->rendering('packages/semitexa-demo/src/Application/View/templates/platform/_feature.html.twig');
            self::fail('an unreadable template was read as an empty one');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($queue, $e->getMessage());
        }

        // An empty template is a template: read, and it names nothing.
        $this->write('packages/semitexa-demo/src/Application/View/templates/platform/queue.html.twig', '');
        self::assertSame(['/demo/platform/config'], (new PageUsers($this->root, self::ROUTES))->rendering('packages/semitexa-demo/src/Application/View/templates/platform/_feature.html.twig'));

        foreach (['packages/semitexa-showcase-kit/composer.json', 'packages/semitexa-showcase-kit/src/Application/Static/assets.json'] as $metadata) {
            // There and not readable (review, dev#312, round 2): the same failure as a template's.
            $path = $this->root . '/' . $metadata;
            try {
                (new PageUsers($this->root, self::ROUTES, static fn (string $read): string|false => $read === $path ? false : file_get_contents($read)))->builtOn(self::NAV);
                self::fail("{$metadata} could not be read and the plan went on");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString($path, $e->getMessage());
            }

            $before = (string) file_get_contents($this->root . '/' . $metadata);
            $this->write($metadata, '{not json');
            try {
                (new PageUsers($this->root, self::ROUTES))->builtOn(self::NAV);
                self::fail("{$metadata} was unreadable and the plan went on");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString($metadata, $e->getMessage());
            }
            $this->write($metadata, $before);
        }
    }

    /** Review, dev#312: a path in a comment or a plain string is no dependency; a real operation is. */
    #[Test]
    public function only_a_real_operation_makes_a_template_part_of_a_page(): void
    {
        $demo = 'packages/semitexa-demo/src/Application/View/templates/';
        $this->write($demo . 'pages/home.html.twig', "{% extends '@project-layouts-ShowcaseKit/layouts/showcase.html.twig' %}\n"
            . "{# {% include '" . self::DEMO . "platform/queue.html.twig' %} #}\n"
            . "{% set example = '" . self::DEMO . "platform/queue.html.twig' %}\n"
            . "<code>{% verbatim %}{% include '" . self::DEMO . "platform/queue.html.twig' %}{% endverbatim %}</code>\n"
            . "{{ \"include('" . self::DEMO . "platform/queue.html.twig')\" }}\n"
            . "{{ \"{% include '" . self::DEMO . "platform/queue.html.twig' %}\" }}\n"
            . "{{ 'say \\'hi\\' then include(\"" . self::DEMO . "platform/queue.html.twig\")' }}\n"
            . "{{ o.include('" . self::DEMO . "platform/queue.html.twig') }}\n"
            . "{{ include(['" . self::DEMO . "partials/e.html.twig', '" . self::DEMO . "partials/f.html.twig'], {'see': '" . self::DEMO . "platform/queue.html.twig'}) }}\n"
            . "{% include '" . self::DEMO . "partials/a.html.twig' with {'see': '" . self::DEMO . "platform/queue.html.twig'} only %}\n"
            . "{{ include('" . self::DEMO . "partials/b.html.twig') }}\n"
            . "{%- embed '" . self::DEMO . "partials/c.html.twig' -%}{% endembed %}\n"
            . "{% from '" . self::DEMO . "partials/d.html.twig' import field %}");
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $partial) {
            $this->write($demo . "partials/{$partial}.html.twig", '');
        }
        $this->write('src/modules/Playground/src/Application/View/templates/pages/playground.html.twig', "{# {% extends '@project-layouts-ShowcaseKit/layouts/showcase.html.twig' %} #}"
            . "<html>{{ \"{% extends '@project-layouts-ShowcaseKit/layouts/showcase.html.twig' %}\" }}{{ \"asset('css/nav.css', 'showcase-kit')\" }}</html>");
        $users = new PageUsers($this->root, self::ROUTES);

        self::assertSame(['/demo/platform/queue'], $users->rendering($demo . 'platform/queue.html.twig'));
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $partial) {
            self::assertSame(['/'], $users->rendering($demo . "partials/{$partial}.html.twig"), $partial);
        }
        $builtOn = $users->builtOn(self::NAV);
        self::assertContains('/', $builtOn, 'the real extends is still found');
        self::assertNotContains('/playground', $builtOn);
    }

    /**
     * Review, dev#312: two include() calls in one expression are two dependencies. /demo/platform/queue
     * renders queue.html.twig itself, so no package fallback can stand in for the second call: home
     * is in the plan only when the scanner read past the first.
     */
    #[Test]
    public function every_include_call_of_one_expression_is_a_dependency(): void
    {
        $demo = 'packages/semitexa-demo/src/Application/View/templates/';
        $this->write($demo . 'partials/a.html.twig', '');
        $this->write($demo . 'pages/home.html.twig', "{% extends '@project-layouts-ShowcaseKit/layouts/showcase.html.twig' %}\n"
            . "{{ include('" . self::DEMO . "partials/a.html.twig') ~ include('" . self::DEMO . "platform/queue.html.twig') }}");

        self::assertSame(['/', '/demo/platform/queue'], $this->paths($demo . 'platform/queue.html.twig', ChangedFile::KIND_TEMPLATE));
        self::assertSame(['/'], $this->paths($demo . 'partials/a.html.twig', ChangedFile::KIND_TEMPLATE));
    }

    /**
     * Review, dev#312: an asset manifest that is there and cannot be read or parsed fails the check
     * with the manifest's path. Read as empty it said "does not load nav.css", which was not so.
     */
    #[Test]
    public function an_asset_manifest_that_cannot_be_read_fails_and_names_it(): void
    {
        $manifest = $this->root . '/packages/semitexa-showcase-kit/src/Application/Static/assets.json';
        $this->seen('/', $this->clean(['http://localhost/assets/showcase-kit/css/nav.css']));
        $this->seen('/blog', $this->offSite());
        $plan = new VerificationPlan(VerificationPlan::SCOPE_STANDARD, VerificationPlan::SCOPE_STANDARD, [new ChangedFile(self::NAV, ChangedFile::KIND_STYLESHEET)], [$this->target('/', [self::NAV])]);
        $unreadable = new PageCheckResult($this->root, 'run-1', static fn (string $path): string|false => $path === $manifest ? false : file_get_contents($path));

        // Not readable: the check itself throws, and settling a page sent elsewhere reports it the same way.
        try {
            $unreadable->check($this->target('/', [self::NAV]));
            self::fail('an unreadable manifest was read as an empty one');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($manifest, $e->getMessage());
        }
        $passed = new VerificationResult($this->target('/', [self::NAV]), VerificationResult::STATUS_PASS, 0, 'opened');
        $settled = $unreadable->settle([$unreadable->check($this->target('/blog', [self::NAV])), $passed]);
        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $settled[0]->status);
        self::assertTrue($settled[0]->required);
        self::assertStringContainsString($manifest, $settled[0]->signal);

        // Not JSON: through the executor, which reports a check that threw with its reason.
        $this->write('packages/semitexa-showcase-kit/src/Application/Static/assets.json', '{not json');
        $before = getenv(E2eSpecResultCheck::RUN_ENV);
        putenv(E2eSpecResultCheck::RUN_ENV . '=run-1');
        try {
            $results = (new VerificationExecutor(new Application(), $this->root, new FixedProcessRunner(['exit' => 0, 'output' => ''])))->execute($plan);
        } finally {
            putenv(E2eSpecResultCheck::RUN_ENV . ($before === false ? '' : '=' . $before));
        }
        // The executor's report of a check that threw: required, with the check's own reason.
        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $results[0]->status);
        self::assertTrue($results[0]->required);
        self::assertStringContainsString('could not read the asset manifest', $results[0]->signal);
        self::assertStringContainsString('assets.json', $results[0]->signal);
        self::assertStringNotContainsString('does not load', $results[0]->signal);

        // Absent: the path under Static/ is compared, as before.
        unlink($manifest);
        self::assertSame(VerificationResult::STATUS_PASS, $this->check('/', [self::NAV])->status);
    }

    /** Each thing that would excuse it wrongly, one at a time: it stays required. */
    #[Test]
    public function what_does_not_show_the_change_excuses_nothing(): void
    {
        $this->seen('/blog', $this->offSite());

        // Another page passed, for another file.
        $this->seen('/', $this->clean());
        self::assertTrue($this->checker()->settle([$this->check('/blog', [self::NAV]), $this->check('/', ['src/other.html.twig'])])[0]->required);

        // Another page of the same file failed.
        $failing = ['findings' => ['color-contrast x2']] + $this->clean(['http://localhost/assets/showcase-kit/css/nav.css'])['light'];
        $this->seen('/', ['light' => $failing, 'dark' => $failing]);
        self::assertTrue($this->checker()->settle([$this->check('/blog', [self::NAV]), $this->check('/', [self::NAV])])[0]->required);

        // Two stylesheets changed; the page that passed loads one, and the other was shown nowhere.
        $tokens = 'packages/semitexa-showcase-kit/src/Application/Static/css/tokens.css';
        $this->seen('/', $this->clean(['http://localhost/assets/showcase-kit/css/nav.css']));
        $settled = $this->checker()->settle([$this->check('/blog', [self::NAV, $tokens]), $this->check('/', [self::NAV, $tokens])]);
        self::assertSame(VerificationResult::STATUS_PASS, $settled[1]->status);
        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $settled[0]->status);
        self::assertStringContainsString('no other opened page showed tokens.css', $settled[0]->signal);

        // The same page loading both: excused.
        $this->seen('/', $this->clean(['http://localhost/assets/showcase-kit/css/nav.css', 'http://localhost/assets/showcase-kit/css/tokens.css']));
        self::assertFalse($this->checker()->settle([$this->check('/blog', [self::NAV, $tokens]), $this->check('/', [self::NAV, $tokens])])[0]->required);
    }

    /** Only the server sending both modes to the same address of another site is that. */
    #[Test]
    public function a_redirect_that_is_not_the_servers_own_to_one_other_site_is_not_excused(): void
    {
        $good = $this->offSite()['light'];
        $cases = [
            'a bounce by script (first answer 200)' => [['firstStatus' => 200] + $good, $good],
            'no first answer recorded' => [array_diff_key($good, ['firstStatus' => true]), $good],
            'another landing in dark' => [$good, ['landed' => 'https://other.example/'] + $good],
            'a landing on this site, with a finding there' => [['landed' => '/login', 'findings' => ['label x1']] + $good, ['landed' => '/login'] + $good],
            'a mode not reached' => [$good, ['status' => 0, 'error' => 'net::ERR_NAME_NOT_RESOLVED'] + $good],
        ];
        // A page that was seen loading the stylesheet stands beside each: only the redirect is at fault.
        $this->seen('/', $this->clean(['http://localhost/assets/showcase-kit/css/nav.css?v=93e30410b6eb']));
        foreach ($cases as $name => [$light, $dark]) {
            $this->seen('/blog', ['light' => $light, 'dark' => $dark]);
            $settled = $this->checker()->settle([$this->check('/blog', [self::NAV]), $this->check('/', [self::NAV])]);
            self::assertTrue($settled[0]->required, $name);
            self::assertContains($settled[0]->status, [VerificationResult::STATUS_INCOMPLETE, VerificationResult::STATUS_FAIL], $name);
        }
    }

    /**
     * Registers a slot class's declarations as discovery does, from its #[AsSlotResource].
     *
     * @param class-string $slot
     */
    private function registerSlots(string $slot): void
    {
        /** @var array<mixed> $held */
        $held = (new \ReflectionProperty(LayoutSlotRegistry::class, 'slots'))->getValue();
        $this->slotsBefore ??= $held;
        foreach ((new \ReflectionClass($slot))->getAttributes(AsSlotResource::class) as $attribute) {
            $declared = $attribute->newInstance();
            LayoutSlotRegistry::register(
                handle: $declared->handle,
                slot: $declared->slot,
                template: $declared->template,
                deferred: $declared->deferred,
                skeletonTemplate: $declared->skeletonTemplate,
                resourceClass: $slot,
            );
        }
    }

    /** @return list<string> */
    private function paths(string $file, string $kind): array
    {
        return array_map(
            static fn (VerificationTarget $t): string => (string) $t->commandInput['path'],
            PageCheckTargets::of([new ChangedFile($file, $kind)], VerificationPlan::SCOPE_STANDARD, self::ROUTES, new PageUsers($this->root, self::ROUTES)),
        );
    }

    private function write(string $file, string $content): void
    {
        if (!is_dir(dirname($this->root . '/' . $file))) {
            mkdir(dirname($this->root . '/' . $file), 0777, true);
        }
        file_put_contents($this->root . '/' . $file, $content);
    }

    /** @return array<string, array<string, mixed>> */
    private function offSite(string $to = 'https://semitexa.com/blog'): array
    {
        $view = ['status' => 200, 'firstStatus' => 301, 'html' => true, 'findings' => [], 'stylesheets' => [], 'landed' => $to];

        return ['light' => $view, 'dark' => $view];
    }

    /**
     * @param list<string> $stylesheets
     * @return array<string, array<string, mixed>>
     */
    private function clean(array $stylesheets = []): array
    {
        $view = ['status' => 200, 'firstStatus' => 200, 'html' => true, 'findings' => [], 'stylesheets' => $stylesheets];

        return ['light' => $view, 'dark' => $view];
    }

    /** @param array<string, array<string, mixed>> $modes */
    private function seen(string $path, array $modes): void
    {
        file_put_contents($this->root . '/var/run/verify-e2e/run-1/pages/' . PageCheckResult::fileName($path), json_encode(['path' => $path, 'modes' => $modes]));
    }

    private function checker(): PageCheckResult
    {
        return new PageCheckResult($this->root, 'run-1');
    }

    /** @param list<string> $triggers */
    private function check(string $path, array $triggers): VerificationResult
    {
        return $this->checker()->check($this->target($path, $triggers));
    }

    /** @param list<string> $triggers */
    private function target(string $path, array $triggers): VerificationTarget
    {
        return new VerificationTarget(VerificationTarget::TYPE_PAGE, "page:{$path}", 'test', $triggers, commandInput: ['path' => $path, 'declared_in' => '']);
    }
}

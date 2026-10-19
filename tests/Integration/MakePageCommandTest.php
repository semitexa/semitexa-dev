<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\MakePageCommand;
use Semitexa\Dev\Application\Service\Generation\Builder\PagePlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Support\NameInflector;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateResolver;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class MakePageCommandTest extends TestCase
{
    public function test_generates_full_page_scaffold(): void
    {
        $builder = new PagePlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Website',
            'name' => 'Pricing',
            'path' => '/pricing',
            'method' => 'GET',
            'access' => 'public',
            'withAssets' => false,
            'dryRun' => false,
        ]);

        // The all-in-one ships tested by default:
        // Payload + Handler + Resource + Template + PayloadContractTest + HandlerTest = 6 files
        $this->assertCount(6, $plan->files);

        $paths = array_map(fn($f) => $f->path, $plan->files);
        $this->assertContains('src/modules/Website/src/Application/Payload/Request/PricingPayload.php', $paths);
        $this->assertContains('src/modules/Website/src/Application/Handler/PayloadHandler/PricingHandler.php', $paths);
        $this->assertContains('src/modules/Website/src/Application/Resource/Response/PricingResponse.php', $paths);
        $this->assertContains('src/modules/Website/src/Application/View/templates/pages/pricing.html.twig', $paths);
        $this->assertContains('src/modules/Website/tests/Integration/PricingPayloadContractTest.php', $paths);
        $this->assertContains('src/modules/Website/tests/Unit/PricingHandlerTest.php', $paths);

        // Validate PHP syntax for all PHP files
        foreach ($plan->files as $file) {
            if (str_ends_with($file->path, '.php')) {
                $this->assertPhpSyntaxValid($file->content);
            }
        }
    }

    public function test_no_test_flag_omits_the_test_scaffolds(): void
    {
        $builder = new PagePlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Website',
            'name' => 'Pricing',
            'path' => '/pricing',
            'method' => 'GET',
            'access' => 'public',
            'withAssets' => false,
            'withTest' => false,
            'dryRun' => false,
        ]);

        $this->assertCount(4, $plan->files);
        $paths = array_map(fn($f) => $f->path, $plan->files);
        $this->assertNotContains('src/modules/Website/tests/Integration/PricingPayloadContractTest.php', $paths);
        $this->assertNotContains('src/modules/Website/tests/Unit/PricingHandlerTest.php', $paths);
    }

    public function test_an_exported_ui_tree_is_indented_into_the_page_body(): void
    {
        $twig = $this->pageTemplate("<div data-ui-tree>{{ component('platform.card', { title: 'A' }, {\n    body: primitive('platform.badge', { text: 'B' }),\n}) }}</div>\n");

        $this->assertStringContainsString(
            "        <h1>{{ title | default('Pricing') }}</h1>\n"
            . "        <div data-ui-tree>{{ component('platform.card', { title: 'A' }, {\n"
            . "            body: primitive('platform.badge', { text: 'B' }),\n"
            . "        }) }}</div>\n"
            . "    </div>",
            $twig,
        );
    }

    public function test_without_a_ui_tree_the_template_is_unchanged(): void
    {
        $this->assertStringContainsString("        <h1>{{ title | default('Pricing') }}</h1>\n    </div>", $this->pageTemplate(''));
        $this->assertSame($this->pageTemplate(''), $this->pageTemplate(null));
    }

    public function test_an_unreadable_ui_tree_is_refused_in_the_generation_result_envelope(): void
    {
        $command = new MakePageCommand();
        $command->setName('make:page');
        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($app->find('make:page'));

        $exit = $tester->execute([
            '--module' => 'UiPlayground',
            '--name' => 'TreeRefusalProbe',
            '--path' => '/tree-refusal-probe',
            '--method' => 'GET',
            '--ui-tree' => 'var/no-such-tree.json',
            '--grant' => ['orders.view', 'orders.edit'],
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        /** @var array<string, mixed> $envelope */
        $envelope = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('semitexa-dev.generation-result/v1', $envelope['artifact']);
        $this->assertSame('rejected', $envelope['result']['status']);
        $this->assertSame('tree.unreadable', $envelope['result']['errors'][0]['reason']);
        // Every grant replays, or the suggested re-run cannot export the tree.
        $this->assertContains('--grant=orders.view', $envelope['result']['replay_args']);
        $this->assertContains('--grant=orders.edit', $envelope['result']['replay_args']);
    }

    public function test_a_readable_ui_tree_without_a_runtime_is_refused_not_thrown(): void
    {
        // A directly constructed command has no container: the tree is readable,
        // so the refusal has to come from the runtime check, not the file check.
        $relative = 'var/tmp/make-page-tree-' . bin2hex(random_bytes(4)) . '.json';
        // The root the command resolves, wherever this package is checked out.
        $absolute = ProjectRoot::get() . '/' . $relative;
        @mkdir(dirname($absolute), 0o755, true);
        file_put_contents($absolute, '{"component":"platform.card","props":{"title":"A"}}');

        try {
            $command = new MakePageCommand();
            $command->setName('make:page');
            $app = new Application();
            $app->add($command);
            $tester = new CommandTester($app->find('make:page'));

            $exit = $tester->execute([
                '--module' => 'UiPlayground',
                '--name' => 'TreeRuntimeProbe',
                '--path' => '/tree-runtime-probe',
                '--method' => 'GET',
                '--ui-tree' => $relative,
                '--json' => true,
            ]);

            $this->assertSame(1, $exit);
            /** @var array<string, mixed> $envelope */
            $envelope = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('rejected', $envelope['result']['status']);
            $this->assertSame('tree.no_runtime', $envelope['result']['errors'][0]['reason']);
        } finally {
            @unlink($absolute);
        }
    }

    private function pageTemplate(?string $uiTree): string
    {
        $params = [
            'module' => 'Website',
            'name' => 'Pricing',
            'path' => '/pricing',
            'method' => 'GET',
            'access' => 'public',
            'withAssets' => false,
            'withTest' => false,
            'dryRun' => false,
        ];
        if ($uiTree !== null) {
            $params['uiTree'] = $uiTree;
        }
        $plan = (new PagePlanBuilder(new NameInflector(), new TemplateResolver(), new TemplateRenderer()))->build($params);
        foreach ($plan->files as $file) {
            if (str_ends_with($file->path, '/pages/pricing.html.twig')) {
                return $file->content;
            }
        }
        $this->fail('No page template planned');
    }

    public function test_generates_page_with_assets(): void
    {
        $builder = new PagePlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Website',
            'name' => 'Pricing',
            'path' => '/pricing',
            'method' => 'GET',
            'access' => 'protected',
            'withAssets' => true,
            'withTest' => false,
            'dryRun' => false,
        ]);

        // Payload + Handler + Resource + Template + assets.json + CSS + JS = 7
        $this->assertCount(7, $plan->files);

        $paths = array_map(fn($f) => $f->path, $plan->files);
        $this->assertContains('src/modules/Website/src/Application/View/assets/pages/pricing.json', $paths);
        $this->assertContains('src/modules/Website/src/Application/View/assets/pages/pricing.css', $paths);
        $this->assertContains('src/modules/Website/src/Application/View/assets/pages/pricing.js', $paths);
    }

    /**
     * The generated page script has to survive markup that arrives later.
     *
     * The obvious thing to write is a `<script>` in the page template, and it
     * works — until the page becomes swappable, at which point it is inert
     * until re-created, then runs once per arrival, and has already missed
     * DOMContentLoaded. The scaffold is where that lesson is cheapest to
     * teach, so it ships the shape instead of describing it.
     */
    public function test_generated_page_script_binds_idempotently_and_not_on_domcontentloaded(): void
    {
        $builder = new PagePlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Website',
            'name' => 'Pricing',
            'path' => '/pricing',
            'method' => 'GET',
            'access' => 'public',
            'withAssets' => true,
            'withTest' => false,
            'dryRun' => false,
        ]);

        $js = '';
        $twig = '';
        foreach ($plan->files as $file) {
            if (str_ends_with($file->path, 'pages/pricing.js')) {
                $js = $file->content;
            }
            if (str_ends_with($file->path, 'pages/pricing.html.twig')) {
                $twig = $file->content;
            }
        }

        self::assertNotSame('', $js, 'the page script must be generated');
        // The docblock NAMES that event — explaining why it is the wrong hook
        // is half the point of the scaffold. What must not appear is code
        // waiting on it.
        self::assertStringNotContainsString("addEventListener('DOMContentLoaded'", $js);
        self::assertStringNotContainsString('addEventListener("DOMContentLoaded"', $js);
        self::assertStringContainsString('MutationObserver', $js, 'markup that arrives later has to be connected too');
        self::assertStringContainsString('data-pricing-bound', $js, 'binding twice is the failure this guards');
        self::assertStringContainsString('AsUiBehavior', $js, 'the scaffold names the mechanism that solves this outright');

        // Asserted non-empty FIRST: the negative below is satisfied by a
        // template that was never generated, so a rename of the page file
        // would turn this into a test that passes on nothing.
        self::assertNotSame('', $twig, 'the page template must be generated');
        self::assertStringNotContainsString('<script', $twig, 'the template must not teach the inline shape');
    }

    private function assertPhpSyntaxValid(string $code): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'php_lint_');
        file_put_contents($tmp, $code);
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
        unlink($tmp);
        $this->assertSame(0, $code, 'PHP syntax error: ' . implode("\n", $output));
    }
}

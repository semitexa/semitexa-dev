<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Generation\Builder\CommandPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\ContractPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\EventListenerPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\HandlerPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\ModulePlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\PagePlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\PayloadPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\ResourcePlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\ServicePlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Support\NameInflector;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateResolver;

/**
 * Cross-cutting regression guard for every generator template + every plan
 * builder output. The framework's hardening epic retired several
 * architectural shapes (`#[AsPayload]`, `#[PublicEndpoint]`, the
 * seen()+markSeen() webhook race window). Until this test existed the
 * forbidden-pattern coverage was per-generator (only `make:payload` had
 * negative assertions) — a future template tweak could silently
 * reintroduce a stale attribute and only the per-generator test of
 * THAT generator would catch it.
 *
 * The contract:
 *   1. Every template file under `packages/semitexa-dev/resources/templates/`
 *      is scanned for forbidden literal patterns.
 *   2. Every plan builder's generated PlannedFile contents are scanned for
 *      the same patterns. Plan builders take template inputs; this guards
 *      the OUTPUT side so a builder that accidentally hand-stitches a
 *      stale string (instead of using the template) is still caught.
 *
 * Forbidden patterns rationale:
 *   - `#[AsPayload(`        — old base payload attribute, replaced by the
 *                             three explicit access attributes
 *                             (`AsPublicPayload` / `AsProtectedPayload` /
 *                             `AsServicePayload`).
 *   - `PublicEndpoint`      — old explicit-public marker, replaced by
 *                             `AsPublicPayload`.
 *   - `vendor/bin/phpunit`  — generated tests must use
 *                             `bin/semitexa test:run` so the test runs
 *                             through the framework's test orchestration.
 *   - `Cycle ` / `cycle-`   — implementation-cycle markers must not leak
 *                             into production code or templates. Cycle
 *                             reports live in `var/docs/` only.
 *
 * The webhook race-window pattern (`seen() + markSeen()`) is checked only
 * for templates / outputs that touch `WebhookReplayStore` — a non-webhook
 * file that legitimately uses an unrelated `markSeen()` method elsewhere
 * is not a regression.
 */
final class GeneratorForbiddenPatternRegressionTest extends TestCase
{
    private const TEMPLATES_DIR = __DIR__ . '/../../resources/templates';

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function templateFiles(): iterable
    {
        $dir = realpath(self::TEMPLATES_DIR);
        self::assertNotFalse($dir, 'templates dir resolvable');

        $files = glob($dir . '/*.tpl');
        self::assertNotFalse($files);
        sort($files);

        foreach ($files as $file) {
            $name = basename($file);
            yield $name => [$name, $file];
        }
    }

    #[Test]
    #[DataProvider('templateFiles')]
    public function template_does_not_contain_forbidden_patterns(string $name, string $path): void
    {
        $content = (string) file_get_contents($path);

        // Stale attribute names — old architecture, must never be re-emitted.
        // The `(` / non-word boundary check excludes still-valid names like
        // `AsPayloadHandler` and `AsPayloadPart`.
        self::assertDoesNotMatchRegularExpression(
            '/#\[\s*AsPayload\s*\(/',
            $content,
            "Template {$name} emits #[AsPayload( — replace with #[AsPublicPayload / AsProtectedPayload / AsServicePayload]",
        );
        self::assertStringNotContainsString(
            'PublicEndpoint',
            $content,
            "Template {$name} emits the retired #[PublicEndpoint] attribute",
        );

        // Generated tests must use bin/semitexa test:run.
        self::assertStringNotContainsString(
            'vendor/bin/phpunit',
            $content,
            "Template {$name} hardcodes vendor/bin/phpunit instead of bin/semitexa test:run",
        );

        // Cycle markers belong in var/docs reports, never in code or templates.
        self::assertDoesNotMatchRegularExpression(
            '/(?:^|\W)[Cc]ycle[ -]\d+/',
            $content,
            "Template {$name} contains a cycle-N reference — implementation history must not leak into templates",
        );

        // Webhook race-window antipattern: only relevant for webhook templates.
        if (str_contains($content, 'WebhookReplayStore') || str_contains($name, 'webhook')) {
            self::assertStringNotContainsString(
                '->markSeen(',
                $content,
                "Template {$name} uses the racy seen()+markSeen() flow; use markIfFirstSeen() (atomic).",
            );
        }
    }

    /** @return iterable<string, array{0: string, 1: list<string>}> */
    public static function planBuilderOutputs(): iterable
    {
        $inflector = new NameInflector();
        $resolver = new TemplateResolver();
        $renderer = new TemplateRenderer();

        $payloadAccess = [
            'public'    => PayloadPlanBuilder::ACCESS_PUBLIC,
            'protected' => PayloadPlanBuilder::ACCESS_PROTECTED,
            'service'   => PayloadPlanBuilder::ACCESS_SERVICE,
        ];

        foreach ($payloadAccess as $label => $access) {
            $plan = (new PayloadPlanBuilder($inflector, $resolver, $renderer))->build([
                'module' => 'AuditModule',
                'name' => 'AuditCheck',
                'path' => '/audit/check',
                'method' => 'POST',
                'response' => 'AuditCheck',
                'access' => $access,
                'dryRun' => true,
            ]);
            yield "make:payload --access={$label}" => [
                "make:payload --access={$label}",
                array_map(static fn ($f): string => $f->content, $plan->files),
            ];
        }

        $handlerPlan = (new HandlerPlanBuilder($inflector, $resolver, $renderer))->build([
            'module' => 'AuditModule',
            'name' => 'AuditCheck',
            'payload' => 'AuditCheck',
            'resource' => 'AuditCheck',
            'dryRun' => true,
        ]);
        yield 'make:handler' => ['make:handler', array_map(static fn ($f): string => $f->content, $handlerPlan->files)];

        $resourcePlan = (new ResourcePlanBuilder($inflector, $resolver, $renderer))->build([
            'module' => 'AuditModule',
            'name' => 'AuditCheck',
            'handle' => 'audit-check',
            'withTemplate' => false,
            'withAssets' => false,
            'dryRun' => true,
        ]);
        yield 'make:resource' => ['make:resource', array_map(static fn ($f): string => $f->content, $resourcePlan->files)];

        $servicePlan = (new ServicePlanBuilder($inflector, $resolver, $renderer))->build([
            'module' => 'AuditModule',
            'name' => 'AuditChecker',
            'dryRun' => true,
        ]);
        yield 'make:service' => ['make:service', array_map(static fn ($f): string => $f->content, $servicePlan->files)];

        $contractPlan = (new ContractPlanBuilder($inflector, $resolver, $renderer))->build([
            'module' => 'AuditModule',
            'name' => 'AuditPolicy',
            'implementation' => 'AuditPolicyService',
            'dryRun' => true,
        ]);
        yield 'make:contract' => ['make:contract', array_map(static fn ($f): string => $f->content, $contractPlan->files)];

        $commandPlan = (new CommandPlanBuilder($inflector, $resolver, $renderer))->build([
            'module' => 'AuditModule',
            'name' => 'RunAudit',
            'commandName' => 'audit:run',
            'description' => 'Run the audit pipeline',
            'dryRun' => true,
        ]);
        yield 'make:command' => ['make:command', array_map(static fn ($f): string => $f->content, $commandPlan->files)];

        $listenerPlan = (new EventListenerPlanBuilder($inflector, $resolver, $renderer))->build([
            'module' => 'AuditModule',
            'name' => 'OnAuditCompleted',
            'event' => 'AuditCompleted',
            'execution' => 'sync',
            'dryRun' => true,
        ]);
        yield 'make:event-listener' => ['make:event-listener', array_map(static fn ($f): string => $f->content, $listenerPlan->files)];

        $modulePlan = (new ModulePlanBuilder($inflector))->build([
            'name' => 'AuditModule',
            'target' => ModulePlanBuilder::TARGET_CUSTOM,
            'dryRun' => true,
        ]);
        yield 'make:module --target=custom' => [
            'make:module --target=custom',
            array_map(static fn ($f): string => $f->content, $modulePlan->files),
        ];

        $modulePackagePlan = (new ModulePlanBuilder($inflector))->build([
            'name' => 'AuditModule',
            'target' => ModulePlanBuilder::TARGET_PACKAGE,
            'dryRun' => true,
        ]);
        yield 'make:module --target=package' => [
            'make:module --target=package',
            array_map(static fn ($f): string => $f->content, $modulePackagePlan->files),
        ];

        $pagePlan = (new PagePlanBuilder($inflector, $resolver, $renderer))->build([
            'module' => 'AuditModule',
            'name' => 'AuditDashboard',
            'path' => '/audit/dashboard',
            'method' => 'GET',
            'access' => PayloadPlanBuilder::ACCESS_PROTECTED,
            'withAssets' => false,
            'dryRun' => true,
        ]);
        yield 'make:page' => ['make:page', array_map(static fn ($f): string => $f->content, $pagePlan->files)];
    }

    #[Test]
    #[DataProvider('planBuilderOutputs')]
    public function plan_builder_output_does_not_contain_forbidden_patterns(string $command, array $contents): void
    {
        foreach ($contents as $idx => $content) {
            self::assertDoesNotMatchRegularExpression(
                '/#\[\s*AsPayload\s*\(/',
                $content,
                "Generator {$command} (file {$idx}) emits #[AsPayload(",
            );
            self::assertStringNotContainsString(
                'PublicEndpoint',
                $content,
                "Generator {$command} (file {$idx}) emits #[PublicEndpoint]",
            );
            self::assertStringNotContainsString(
                'vendor/bin/phpunit',
                $content,
                "Generator {$command} (file {$idx}) hardcodes vendor/bin/phpunit",
            );
            self::assertDoesNotMatchRegularExpression(
                '/(?:^|\W)[Cc]ycle[ -]\d+/',
                $content,
                "Generator {$command} (file {$idx}) contains a cycle-N reference",
            );
            if (str_contains($content, 'WebhookReplayStore')) {
                self::assertStringNotContainsString(
                    '->markSeen(',
                    $content,
                    "Generator {$command} (file {$idx}) emits seen()+markSeen() race; use markIfFirstSeen()",
                );
            }
        }
    }

    /**
     * Every class a generated file imports has to exist.
     *
     * The forbidden-pattern list above is a deny-list: it catches the stale
     * names somebody thought to write down. It did not catch four `use`
     * statements pointing at classes that were never there —
     * `Semitexa\Core\Attributes\AsResource` and two siblings under the
     * pluralised namespace, and `Semitexa\Ssr\Http\Response\HtmlResponse`
     * from before the SSR tree moved. `make:page`, `make:resource`,
     * `make:service` and `make:contract` all produced code that fatals on
     * first use, and nothing said so: a bad `use` is not a syntax error, so the
     * file writes cleanly and only dies when a request reaches it.
     *
     * So this is the allow-list half — mechanical, and it needs nobody to
     * predict which name will rot next.
     *
     * Imports inside the generated module's own namespace are skipped: those
     * name the siblings the generator is creating in the same run, which by
     * definition do not exist yet.
     */
    #[Test]
    #[DataProvider('planBuilderOutputs')]
    public function every_class_a_generated_file_imports_exists(string $command, array $contents): void
    {
        $imports = 0;
        $resolved = 0;

        foreach ($contents as $idx => $content) {
            if (!str_starts_with(ltrim($content), '<?php')) {
                continue; // twig, json, css, markdown — no imports to resolve
            }

            preg_match_all('/^use\s+([A-Za-z0-9_]+(?:\\\\[A-Za-z0-9_]+)*)\s*;/m', $content, $matches);

            foreach ($matches[1] as $fqcn) {
                $imports++;

                // The siblings this same generator run is creating. They do
                // not exist yet by definition, and never will under the
                // fixture module name.
                if (str_starts_with($fqcn, 'Semitexa\Modules\\') || str_starts_with($fqcn, 'App\Modules\\')) {
                    continue;
                }

                $resolved++;

                self::assertTrue(
                    class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn),
                    "Generator {$command} (file {$idx}) imports {$fqcn}, which does not exist. "
                    . 'A generated file with a bad `use` writes cleanly and fatals on the first '
                    . 'request that reaches it, so nothing between here and production reports it.',
                );
            }
        }

        // A generator whose files carry `use` lines must have had some of them
        // read. Without this the whole test passes by matching nothing, which
        // is how the four dead imports survived a suite that already rendered
        // every one of these files.
        $writesImports = false;
        foreach ($contents as $content) {
            if (str_contains($content, "\nuse ")) {
                $writesImports = true;
                break;
            }
        }

        if ($writesImports) {
            self::assertGreaterThan(0, $imports, "No import was extracted from {$command}, but its output has `use` lines — the extraction is broken, not the generator.");
        }

        self::assertTrue(true, 'a generator that imports only its own siblings is legitimately silent here');
    }

    #[Test]
    public function payload_plan_builder_emits_exactly_one_access_attribute(): void
    {
        // The three access attributes are mutually exclusive; emitting two
        // would silently confuse the access policy resolver. Pin the
        // exclusivity contract for every access flavour.
        $inflector = new NameInflector();
        $resolver = new TemplateResolver();
        $renderer = new TemplateRenderer();
        $builder = new PayloadPlanBuilder($inflector, $resolver, $renderer);

        $cases = [
            PayloadPlanBuilder::ACCESS_PUBLIC    => 'AsPublicPayload',
            PayloadPlanBuilder::ACCESS_PROTECTED => 'AsProtectedPayload',
            PayloadPlanBuilder::ACCESS_SERVICE   => 'AsServicePayload',
        ];
        $allAttrs = array_values($cases);

        foreach ($cases as $access => $expectedAttr) {
            $plan = $builder->build([
                'module' => 'ExclusivityCheck',
                'name' => 'PingCheck',
                'path' => '/ping',
                'method' => 'GET',
                'response' => 'PingCheck',
                'access' => $access,
                'dryRun' => true,
            ]);
            $content = $plan->files[0]->content;
            self::assertStringContainsString("#[{$expectedAttr}(", $content, "access={$access} should emit #[{$expectedAttr}(");
            foreach ($allAttrs as $other) {
                if ($other === $expectedAttr) {
                    continue;
                }
                self::assertStringNotContainsString(
                    $other,
                    $content,
                    "access={$access} must not also emit {$other}",
                );
            }
        }
    }

    #[Test]
    public function generator_templates_directory_contains_only_known_extensions(): void
    {
        // Snapshot the template-file inventory so a new template added in
        // a future cycle is forced to be considered against this scan
        // explicitly. The check is `*.tpl` extension only — anything
        // unusual (`.bak`, `.old`, `.orig`) likely indicates an editor
        // artifact left behind that operators should clean up.
        $dir = realpath(self::TEMPLATES_DIR);
        self::assertNotFalse($dir);
        $entries = scandir($dir);
        self::assertNotFalse($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($full)) {
                continue;
            }
            self::assertMatchesRegularExpression(
                '/\.(tpl|json|css|js|twig|html)$/',
                $entry,
                "Unexpected template file extension: {$entry}",
            );
        }
    }
}

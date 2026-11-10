<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Builder;

use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Application\Service\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Application\Service\Generation\Data\FileType;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationPlan;
use Semitexa\Dev\Application\Service\Generation\Data\PlannedFile;
use Semitexa\Dev\Application\Service\Generation\Support\ModuleNamespace;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;

final class ResourcePlanBuilder
{
    public function __construct(
        private readonly NameInflectorInterface $inflector,
        private readonly TemplateResolverInterface $templateResolver,
        private readonly TemplateRenderer $renderer,
    ) {}

    /**
     * @param array{module: string, name: string, handle: string, template?: string, withTemplate: bool, withAssets: bool, dryRun: bool, uiTree?: string} $params
     */
    public function build(array $params): GenerationPlan
    {
        $module = $this->inflector->toStudly($params['module']);
        $moduleNs = ModuleNamespace::of($module);
        $responseClass = $this->inflector->toResponseClass($params['name']);
        $kebabName = $this->inflector->toKebab($params['name']);

        $namespace = "{$moduleNs}\\Application\\Resource\\Response";
        $templatePath = $params['template'] ?? "@project-layouts-{$module}/pages/{$kebabName}.html.twig";

        $imports = [
            'use Semitexa\\Core\\Attribute\\AsResource;',
            'use Semitexa\\Core\\Contract\\ResourceInterface;',
            'use Semitexa\\Ssr\\Application\\Service\\Http\\Response\\HtmlResponse;',
        ];

        sort($imports);

        $template = $this->templateResolver->resolve('resource.php.tpl');
        $content = $this->renderer->render($template, [
            'namespace' => $namespace,
            'imports' => implode("\n", $imports),
            'handle' => $params['handle'],
            'template' => $templatePath,
            'className' => $responseClass,
        ], 'make:resource');

        $filePath = "src/modules/{$module}/src/Application/Resource/Response/{$responseClass}.php";
        $files = [new PlannedFile($filePath, $content, FileType::PhpClass)];

        if ($params['withTemplate'] ?? false) {
            $studlyName = $this->inflector->toStudly($params['name']);
            $twigTemplate = $this->templateResolver->resolve('page-template.html.twig.tpl');
            $twigContent = $this->renderer->render($twigTemplate, [
                'pageName' => $studlyName,
                'kebabName' => $kebabName,
                // An exported UI tree (make:page --ui-tree), indented into the
                // page body; empty leaves the template exactly as it was.
                'uiTree' => self::indent($params['uiTree'] ?? '', '        '),
                // The page's own script, when it has one (--with-assets).
                'assetRequire' => $params['withAssets']
                    ? "\n    {{ asset_require('{$module}:js:{$kebabName}') }}"
                    : '',
            ]);
            $twigPath = "src/modules/{$module}/src/Application/View/templates/pages/{$kebabName}.html.twig";
            $files[] = new PlannedFile($twigPath, $twigContent, FileType::TwigTemplate);
        }

        if ($params['withAssets'] ?? false) {
            $studlyName = $this->inflector->toStudly($params['name']);
            // Where the runtime reads a module's assets (tk-guard-make-assets-legacy-path):
            // Application/Static with one v2 assets.json per module. The old
            // View/assets/pages/<page>.{json,css,js} was read by nothing, so the
            // files never reached a page, and ai:verify refused the directory.
            $static = "src/modules/{$module}/src/Application/Static";

            // The manifest is the module's, not the page's: written for the first
            // page with assets, left alone after (its include rules already find
            // css/*.css and js/*.js, and --force must not replace it).
            if (!is_file(ProjectRoot::get() . "/{$static}/assets.json")) {
                $assetsJson = $this->templateResolver->resolve('page-assets.json.tpl');
                $files[] = new PlannedFile(
                    "{$static}/assets.json",
                    $this->renderer->render($assetsJson, ['module' => $module]),
                    FileType::JsonFile,
                );
            }

            // A stylesheet is module-scoped: the layout emits it on the module's
            // pages, and its rules sit under .page-<page>.
            $cssTemplate = $this->templateResolver->resolve('page-css.css.tpl');
            $cssContent = $this->renderer->render($cssTemplate, ['pageName' => $studlyName, 'kebabName' => $kebabName]);
            $files[] = new PlannedFile("{$static}/css/{$kebabName}.css", $cssContent, FileType::CssFile);

            // A script is page-scoped: the page template asks for it (asset_require).
            $jsTemplate = $this->templateResolver->resolve('page-js.js.tpl');
            $jsContent = $this->renderer->render($jsTemplate, ['pageName' => $studlyName, 'kebabName' => $kebabName]);
            $files[] = new PlannedFile("{$static}/js/{$kebabName}.js", $jsContent, FileType::JsFile);
        }

        return new GenerationPlan(
            command: 'make:resource',
            files: $files,
            dryRun: $params['dryRun'] ?? false,
        );
    }

    private static function indent(string $twig, string $pad): string
    {
        if (trim($twig) === '') {
            return '';
        }
        $lines = explode("\n", rtrim($twig, "\n"));

        return implode('', array_map(static fn (string $line): string => ($line === '' ? '' : $pad . $line) . "\n", $lines));
    }
}

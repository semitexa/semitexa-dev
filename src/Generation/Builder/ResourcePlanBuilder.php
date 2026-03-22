<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Builder;

use Semitexa\Dev\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Generation\Data\FileType;
use Semitexa\Dev\Generation\Data\GenerationPlan;
use Semitexa\Dev\Generation\Data\PlannedFile;
use Semitexa\Dev\Generation\Support\TemplateRenderer;

final class ResourcePlanBuilder
{
    public function __construct(
        private readonly NameInflectorInterface $inflector,
        private readonly TemplateResolverInterface $templateResolver,
        private readonly TemplateRenderer $renderer,
    ) {}

    /**
     * @param array{module: string, name: string, handle: string, template?: string, withTemplate: bool, withAssets: bool, dryRun: bool} $params
     */
    public function build(array $params): GenerationPlan
    {
        $module = $this->inflector->toStudly($params['module']);
        $responseClass = $this->inflector->toResponseClass($params['name']);
        $kebabName = $this->inflector->toKebab($params['name']);

        $namespace = "Semitexa\\Modules\\{$module}\\Application\\Resource\\Response";
        $templatePath = $params['template'] ?? "@project-layouts-{$module}/{$kebabName}.html.twig";

        $imports = [
            'use Semitexa\\Core\\Attributes\\AsResource;',
            'use Semitexa\\Core\\Contract\\ResourceInterface;',
            'use Semitexa\\Ssr\\Http\\Response\\HtmlResponse;',
        ];

        sort($imports);

        $template = $this->templateResolver->resolve('resource.php.tpl');
        $content = $this->renderer->render($template, [
            'namespace' => $namespace,
            'imports' => implode("\n", $imports),
            'handle' => $params['handle'],
            'template' => $templatePath,
            'className' => $responseClass,
        ]);

        $filePath = "src/modules/{$module}/Application/Resource/Response/{$responseClass}.php";
        $files = [new PlannedFile($filePath, $content, FileType::PhpClass)];

        if ($params['withTemplate'] ?? false) {
            $studlyName = $this->inflector->toStudly($params['name']);
            $twigTemplate = $this->templateResolver->resolve('page-template.html.twig.tpl');
            $twigContent = $this->renderer->render($twigTemplate, [
                'pageName' => $studlyName,
                'kebabName' => $kebabName,
            ]);
            $twigPath = "src/modules/{$module}/Application/View/templates/pages/{$kebabName}.html.twig";
            $files[] = new PlannedFile($twigPath, $twigContent, FileType::TwigTemplate);
        }

        if ($params['withAssets'] ?? false) {
            $studlyName = $this->inflector->toStudly($params['name']);

            $assetsJson = $this->templateResolver->resolve('page-assets.json.tpl');
            $assetsContent = $this->renderer->render($assetsJson, ['kebabName' => $kebabName]);
            $files[] = new PlannedFile(
                "src/modules/{$module}/Application/View/assets/pages/{$kebabName}.json",
                $assetsContent,
                FileType::JsonFile,
            );

            $cssTemplate = $this->templateResolver->resolve('page-css.css.tpl');
            $cssContent = $this->renderer->render($cssTemplate, ['pageName' => $studlyName, 'kebabName' => $kebabName]);
            $files[] = new PlannedFile(
                "src/modules/{$module}/Application/View/assets/pages/{$kebabName}.css",
                $cssContent,
                FileType::CssFile,
            );

            $jsTemplate = $this->templateResolver->resolve('page-js.js.tpl');
            $jsContent = $this->renderer->render($jsTemplate, ['pageName' => $studlyName, 'kebabName' => $kebabName]);
            $files[] = new PlannedFile(
                "src/modules/{$module}/Application/View/assets/pages/{$kebabName}.js",
                $jsContent,
                FileType::JsFile,
            );
        }

        return new GenerationPlan(
            command: 'make:resource',
            files: $files,
            dryRun: $params['dryRun'] ?? false,
        );
    }
}

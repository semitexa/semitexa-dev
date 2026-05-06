<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Support;

use Semitexa\Dev\Application\Service\Generation\Contract\TemplateResolverInterface;

final class TemplateResolver implements TemplateResolverInterface
{
    private string $templateDir;

    public function __construct(?string $templateDir = null)
    {
        // Walk up from src/Application/Service/Generation/Support to the package root,
        // then into the canonical package-level resources/ directory.
        $this->templateDir = $templateDir ?? dirname(__DIR__, 5) . '/resources/templates';
    }

    public function resolve(string $templateName): string
    {
        $path = $this->templateDir . '/' . $templateName;

        if (!is_file($path)) {
            throw new \RuntimeException("Template not found: {$path}");
        }

        return file_get_contents($path);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Support;

use Semitexa\Dev\Generation\Contract\TemplateResolverInterface;

final class TemplateResolver implements TemplateResolverInterface
{
    private string $templateDir;

    public function __construct(?string $templateDir = null)
    {
        $this->templateDir = $templateDir ?? dirname(__DIR__, 3) . '/resources/templates';
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

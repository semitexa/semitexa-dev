<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Support;

final class TemplateRenderer
{
    /**
     * @param string $template Template content with {{placeholder}} markers
     * @param array<string, string> $variables Key-value pairs for substitution
     */
    public function render(string $template, array $variables): string
    {
        $replacements = [];
        foreach ($variables as $key => $value) {
            $replacements['{{' . $key . '}}'] = $value;
        }

        return strtr($template, $replacements);
    }
}

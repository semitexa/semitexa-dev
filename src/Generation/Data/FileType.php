<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Data;

enum FileType: string
{
    case PhpClass = 'php_class';
    case TwigTemplate = 'twig_template';
    case JsonFile = 'json_file';
    case CssFile = 'css_file';
    case JsFile = 'js_file';
}

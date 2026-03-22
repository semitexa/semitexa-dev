<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Generation\Support\TemplateRenderer;

class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TemplateRenderer();
    }

    public function test_replaces_placeholders(): void
    {
        $template = 'Hello {{name}}, welcome to {{place}}!';
        $result = $this->renderer->render($template, [
            'name' => 'World',
            'place' => 'Semitexa',
        ]);
        $this->assertSame('Hello World, welcome to Semitexa!', $result);
    }

    public function test_leaves_unknown_placeholders(): void
    {
        $template = '{{known}} and {{unknown}}';
        $result = $this->renderer->render($template, ['known' => 'yes']);
        $this->assertSame('yes and {{unknown}}', $result);
    }

    public function test_empty_variables(): void
    {
        $template = '{{keep}}';
        $result = $this->renderer->render($template, []);
        $this->assertSame('{{keep}}', $result);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Generation\Data\GenerationResult;
use Semitexa\Dev\Generation\Support\LlmHintsFormatter;

class LlmHintsFormatterTest extends TestCase
{
    public function test_envelope_structure(): void
    {
        $formatter = new LlmHintsFormatter();
        $result = new GenerationResult('make:page', 'success', ['file.php']);

        $json = $formatter->format('page_scaffold', $result, [
            'fill_targets' => ['file.php' => ['Add logic']],
            'facts' => ['Fact 1'],
            'constraints' => ['Constraint 1'],
            'suggested_next_prompt' => 'Do something',
        ]);

        $data = json_decode($json, true);
        $this->assertSame('semitexa-dev.llm-hints/v1', $data['artifact']);
        $this->assertSame('page_scaffold', $data['hint_type']);
        $this->assertArrayHasKey('generated_at', $data);
        $this->assertSame(['file.php' => ['Add logic']], $data['fill_targets']);
        $this->assertSame(['Fact 1'], $data['facts']);
        $this->assertSame(['Constraint 1'], $data['constraints']);
        $this->assertSame('Do something', $data['suggested_next_prompt']);
        $this->assertSame('success', $data['result']['status']);
    }
}

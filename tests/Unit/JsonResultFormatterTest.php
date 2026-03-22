<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Generation\Data\GenerationResult;
use Semitexa\Dev\Generation\Support\JsonResultFormatter;

class JsonResultFormatterTest extends TestCase
{
    public function test_result_structure(): void
    {
        $result = new GenerationResult(
            command: 'make:payload',
            status: 'success',
            created: ['file1.php', 'file2.php'],
            skipped: [],
            conflicts: [],
            next_steps: ['Run composer dump-autoload'],
        );

        $formatter = new JsonResultFormatter();
        $json = $formatter->format($result);
        $data = json_decode($json, true);

        $this->assertSame('semitexa-dev.generation-result/v1', $data['artifact']);
        $this->assertArrayHasKey('generated_at', $data);
        $this->assertSame('make:payload', $data['result']['command']);
        $this->assertSame('success', $data['result']['status']);
        $this->assertSame(['file1.php', 'file2.php'], $data['result']['created']);
    }
}

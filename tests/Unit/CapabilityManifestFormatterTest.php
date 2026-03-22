<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Generation\Data\CapabilityManifest;
use Semitexa\Dev\Generation\Data\CommandCapability;
use Semitexa\Dev\Generation\Support\CapabilityManifestFormatter;

class CapabilityManifestFormatterTest extends TestCase
{
    public function test_schema_shape(): void
    {
        $manifest = new CapabilityManifest(
            artifact: 'semitexa.ai-capabilities/v1',
            generated_at: '2026-03-22T00:00:00+00:00',
            commands: [
                new CommandCapability(
                    name: 'ai:capabilities',
                    kind: 'introspection',
                    summary: 'List commands',
                    use_when: 'Starting',
                    avoid_when: 'Already know',
                ),
            ],
        );

        $formatter = new CapabilityManifestFormatter();
        $json = $formatter->format($manifest);
        $data = json_decode($json, true);

        $this->assertSame('semitexa.ai-capabilities/v1', $data['artifact']);
        $this->assertSame('2026-03-22T00:00:00+00:00', $data['generated_at']);
        $this->assertCount(1, $data['commands']);
        $this->assertSame('ai:capabilities', $data['commands'][0]['name']);
        $this->assertSame('introspection', $data['commands'][0]['kind']);
    }
}

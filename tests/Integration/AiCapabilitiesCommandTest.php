<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Capability\CapabilityRegistry;
use Semitexa\Dev\Generation\Data\CapabilityManifest;
use Semitexa\Dev\Generation\Support\CapabilityManifestFormatter;

class AiCapabilitiesCommandTest extends TestCase
{
    public function test_valid_manifest(): void
    {
        $capabilities = CapabilityRegistry::all();
        $manifest = new CapabilityManifest(
            artifact: 'semitexa.ai-capabilities/v1',
            generated_at: date('c'),
            commands: $capabilities,
        );

        $formatter = new CapabilityManifestFormatter();
        $json = $formatter->format($manifest);
        $data = json_decode($json, true);

        $this->assertSame('semitexa.ai-capabilities/v1', $data['artifact']);
        $this->assertArrayHasKey('generated_at', $data);
        $this->assertIsArray($data['commands']);
        $this->assertGreaterThanOrEqual(5, count($data['commands']));

        $names = array_column($data['commands'], 'name');
        $this->assertContains('ai:capabilities', $names);
        $this->assertContains('make:payload', $names);
        $this->assertContains('make:handler', $names);
        $this->assertContains('make:resource', $names);
        $this->assertContains('make:page', $names);
        $this->assertContains('deploy:check', $names);
        $this->assertContains('deploy:auto', $names);

        // Validate each command has required fields
        foreach ($data['commands'] as $cmd) {
            $this->assertArrayHasKey('name', $cmd);
            $this->assertArrayHasKey('kind', $cmd);
            $this->assertArrayHasKey('summary', $cmd);
            $this->assertArrayHasKey('use_when', $cmd);
            $this->assertArrayHasKey('avoid_when', $cmd);
        }
    }
}

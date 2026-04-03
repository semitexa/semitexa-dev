<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\RemoteDeployment;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\RemoteDeployment\Data\RemoteOsInfo;
use Semitexa\Dev\RemoteDeployment\Support\RemoteScenarioResolver;

final class RemoteScenarioResolverTest extends TestCase
{
    public function testResolvesUbuntuScenarioDirectory(): void
    {
        $resolver = new RemoteScenarioResolver();
        $path = $resolver->resolve(
            new RemoteOsInfo('ubuntu', '22.04', 'Ubuntu 22.04 LTS'),
            '/home/taras/Documents/Projects/semitexa.dev/packages/semitexa-dev',
        );

        self::assertStringEndsWith('/resources/remote-deploy/ubuntu/22.04', $path);
    }

    public function testRejectsUnsupportedUbuntuVersion(): void
    {
        $this->expectException(\RuntimeException::class);
        (new RemoteScenarioResolver())->normalizeUbuntuVersion('18.04');
    }
}

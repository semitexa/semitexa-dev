<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Deployment\Source\PrivateGitTagSource;

final class PrivateGitTagSourceTest extends TestCase
{
    public function testExtractsLatestStableTagFromLsRemoteOutput(): void
    {
        $source = new PrivateGitTagSource();

        $latest = $source->extractLatestStableTag([
            "1111111111111111111111111111111111111111\trefs/tags/2026.04.03.1200",
            "2222222222222222222222222222222222222222\trefs/tags/2026.04.03.1315",
            "3333333333333333333333333333333333333333\trefs/tags/2026.04.03.1315-beta",
            "4444444444444444444444444444444444444444\trefs/tags/2026.04.03.1315^{}",
        ]);

        self::assertSame('2026.04.03.1315', $latest);
    }
}

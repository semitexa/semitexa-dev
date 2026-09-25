<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Explorer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Explorer\OpenApiExporter;

final class OpenApiExporterTest extends TestCase
{
    #[Test]
    public function route_placeholders_lose_their_inline_requirements(): void
    {
        self::assertSame('/items/{id}/tags/{slug}', OpenApiExporter::openApiPath('/items/{id:\d+}/tags/{slug}'));
        self::assertSame('/about', OpenApiExporter::openApiPath('/about'));
    }
}

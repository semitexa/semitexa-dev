<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\ContextRedactor;
use Semitexa\Dev\Application\Service\Trace\RoutePath;

final class RoutePathTest extends TestCase
{
    #[Test]
    public function a_group_inside_a_requirement_does_not_shift_the_next_placeholder(): void
    {
        self::assertSame(
            ['id' => '42', 'slug' => 'blue'],
            RoutePath::params('/items/{id}/tags/{slug}', ['id' => '(\d+)'], '/items/42/tags/blue'),
            'positional captures handed slug the id — the placeholders are named now',
        );
    }

    #[Test]
    public function the_path_is_masked_where_a_placeholder_names_a_secret(): void
    {
        self::assertSame('/reset/' . ContextRedactor::MASK . '/7', RoutePath::redact('/reset/{token}/{id}', '/reset/abc123/7'));
        self::assertSame('/blog', RoutePath::redact('/blog', '/blog'), 'nothing to mask without placeholders');
        self::assertNull(RoutePath::redact('/items/{id}', '/other'), 'a path that does not match its pattern is not trusted');
    }
}

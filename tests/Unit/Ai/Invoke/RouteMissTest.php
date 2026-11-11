<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Invoke;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Invoke\RouteMiss;

/**
 * ai:invoke answers a miss with the routes the caller most likely meant
 * (tk-invoke-route-not-found-no-candidates): "route not found" alone sent an
 * agent guessing, one call per guess.
 */
final class RouteMissTest extends TestCase
{
    private const ROUTES = [
        ['path' => '/ui-playground/admin/pings/feed', 'methods' => ['GET']],
        ['path' => '/ui-playground/admin/pings', 'methods' => ['GET']],
        ['path' => '/ui-playground/admin/leads/feed', 'methods' => ['GET']],
        ['path' => '/install-demo', 'methods' => ['GET']],
        ['path' => '/orders', 'methods' => ['POST']],
    ];

    #[Test]
    public function a_path_one_segment_off_names_the_real_route_first(): void
    {
        $miss = RouteMiss::of('GET', '/ui-playground/admin/ping/feed', self::ROUTES);

        self::assertSame('GET /ui-playground/admin/pings/feed', $miss->nearest[0]);
        self::assertLessThanOrEqual(5, count($miss->nearest));
        self::assertStringContainsString('Nearest: GET /ui-playground/admin/pings/feed', $miss->getMessage());
        self::assertStringContainsString('bin/semitexa routes:list', $miss->getMessage());
    }

    #[Test]
    public function a_path_that_answers_another_method_says_so(): void
    {
        $miss = RouteMiss::of('GET', '/orders', self::ROUTES);

        self::assertSame(['POST'], $miss->otherMethods);
        self::assertStringContainsString('/orders answers POST, not GET.', $miss->getMessage());
        self::assertNotContains('POST /orders', $miss->nearest, 'the exact path is the method hint, not a nearest route');
    }

    #[Test]
    public function the_error_envelope_gets_the_routes_and_a_next_command_only_for_a_miss(): void
    {
        $fields = RouteMiss::fields(RouteMiss::of('GET', '/install-dem', self::ROUTES));

        self::assertSame('GET /install-demo', $fields['nearest_routes'][0]);
        self::assertSame('routes:list', $fields['next_command'][0]['cmd']);
        self::assertSame([], RouteMiss::fields(new \RuntimeException('route has no payload handler')));
    }
}

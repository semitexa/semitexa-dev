<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Explorer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Dev\Application\Service\Explorer\RouteCatalog;
use Semitexa\Dev\Application\Service\Explorer\RouteKind;
use Semitexa\Dev\Application\Service\Explorer\RouteKindClassifier;

final class RouteCatalogTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, RouteKind}> */
    public static function routes(): iterable
    {
        yield 'dev internals win over everything' => [['path' => '/__observatory', 'renderProfile' => RenderProfile::Html], RouteKind::Internal];
        yield 'sse declared as enum' => [['path' => '/feed', 'transport' => TransportType::Sse], RouteKind::Stream];
        yield 'sse declared as string' => [['path' => '/feed', 'transport' => 'sse'], RouteKind::Stream];
        yield 'event-stream produces' => [['path' => '/feed', 'produces' => ['text/event-stream']], RouteKind::Stream];
        yield 'graphql profile' => [['path' => '/graphql', 'renderProfile' => RenderProfile::GraphQL], RouteKind::GraphQl];
        yield 'html profile' => [['path' => '/about', 'renderProfile' => RenderProfile::Html], RouteKind::Page];
        yield 'json profile' => [['path' => '/api/items', 'renderProfile' => RenderProfile::Json], RouteKind::Api];
        yield 'multi-profile with html is a page' => [['path' => '/items', 'renderProfile' => [RenderProfile::Json, RenderProfile::Html]], RouteKind::Page];
        yield 'legacy produces html' => [['path' => '/legacy', 'produces' => ['text/html']], RouteKind::Page];
        yield 'legacy without any signal' => [['path' => '/legacy'], RouteKind::Api];
    }

    /** @param array<string, mixed> $route */
    #[Test]
    #[DataProvider('routes')]
    public function it_classifies_the_route_kind(array $route, RouteKind $expected): void
    {
        self::assertSame($expected, RouteKindClassifier::classify($route));
    }

    #[Test]
    public function it_builds_sorted_entries_without_implicit_methods(): void
    {
        $entries = RouteCatalog::fromRoutes([
            ['path' => '/api/items/{id}', 'methods' => ['GET', 'OPTIONS'], 'class' => 'App\\ItemPayload', 'renderProfile' => RenderProfile::Json,
                'handlers' => [['class' => 'App\\ItemHandler']], 'accessType' => 'public', 'module' => 'Shop'],
            ['path' => '/about', 'methods' => ['GET'], 'class' => 'App\\AboutPayload', 'renderProfile' => RenderProfile::Html],
            ['path' => '/only-options', 'methods' => ['OPTIONS'], 'class' => 'App\\X'],
            ['path' => '/no-payload', 'methods' => ['GET']],
        ]);

        self::assertSame(['GET /about', 'GET /api/items/{id}'], array_map(static fn ($e) => $e->id, $entries));

        $item = $entries[1];
        self::assertSame(['GET'], $item->methods);
        self::assertSame(['id'], $item->pathParams);
        self::assertSame('App\\ItemHandler', $item->handler);
        self::assertSame('public', $item->access);
        self::assertSame(['json'], $item->profiles);
        self::assertSame('api', $item->toArray()['kind']);
    }
}

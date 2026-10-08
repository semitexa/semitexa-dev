<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Transport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Transport\TransportDoorPolicy;

final class TransportDoorPolicyTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function removedDoors(): iterable
    {
        // The four doors that crept in before this guard existed.
        yield 'component events (2026-03)' => ['/__semitexa_component_event', ['POST']];
        yield 'ui event (2026-05)' => ['/__ui/event', ['POST']];
        yield 'ui dispatch (2026-05)' => ['/__ui/dispatch', ['POST']];
        yield 'a new stream' => ['/__ui/stream', ['GET']];
        // Once pending, never again: a replacement on the old path inherits nothing.
        yield 'form-doc back as POST' => ['/__ui/form-doc', ['POST']];
    }

    /** @param list<string> $methods */
    #[Test]
    #[DataProvider('removedDoors')]
    public function an_extra_door_is_a_violation(string $path, array $methods): void
    {
        $violations = TransportDoorPolicy::violations([['path' => $path, 'methods' => $methods, 'class' => 'X'], ...$this->pendingRoutes()]);
        self::assertCount(1, $violations);
        self::assertStringContainsString('KISS', $violations[0]['reason']);
    }

    #[Test]
    public function the_doors_system_pages_dev_tools_and_app_routes_pass(): void
    {
        self::assertSame([], TransportDoorPolicy::violations([...$this->pendingRoutes(),
            ['path' => '/__semitexa_kiss', 'methods' => ['GET']],
            ['path' => '/__semitexa_hug', 'methods' => ['GET']],
            ['path' => '/__semitexa_hug', 'methods' => ['POST', 'OPTIONS']],
            ['path' => '/__semitexa/error/404', 'methods' => ['GET']],
            ['path' => '/__observatory/stream', 'methods' => ['GET']],
            ['path' => '/__ui/workbench', 'methods' => ['GET']],
            ['path' => '/ui-playground/pings/feed', 'methods' => ['GET', 'POST']],
        ]));
    }

    #[Test]
    public function a_new_verb_on_a_door_is_a_violation(): void
    {
        $violations = TransportDoorPolicy::violations([['path' => '/__semitexa_kiss', 'methods' => ['POST'], 'class' => 'X'], ...$this->pendingRoutes()]);
        self::assertCount(1, $violations);
        self::assertStringContainsString('new verb', $violations[0]['reason']);
    }

    #[Test]
    public function a_dev_tool_prefix_does_not_cover_a_lookalike(): void
    {
        self::assertCount(1, TransportDoorPolicy::violations([['path' => '/__observatory-events', 'methods' => ['POST']], ...$this->pendingRoutes()]));
    }

    #[Test]
    public function every_pending_door_names_the_task_that_removes_it(): void
    {
        // The last extra door went with tk-kh-form-doc-over-kiss.
        self::assertArrayNotHasKey('/__ui/form-doc', TransportDoorPolicy::PENDING);
        foreach (TransportDoorPolicy::PENDING as $path => $task) {
            self::assertStringStartsWith('/__', $path);
            self::assertMatchesRegularExpression('/\Atk-[a-z0-9-]+\z/', $task);
        }
    }

    #[Test]
    public function a_pending_entry_without_its_route_is_a_violation(): void
    {
        $violations = TransportDoorPolicy::violations([]);
        self::assertCount(count(TransportDoorPolicy::PENDING), $violations);
        foreach ($violations as $violation) {
            self::assertStringContainsString('remove it from TransportDoorPolicy::PENDING', $violation['reason']);
        }
    }

    /** @return list<array{path: string, methods: list<string>}> the routes the pending entries still stand for */
    private function pendingRoutes(): array
    {
        return array_map(static fn (string $p): array => ['path' => $p, 'methods' => ['GET']], array_keys(TransportDoorPolicy::PENDING));
    }
}

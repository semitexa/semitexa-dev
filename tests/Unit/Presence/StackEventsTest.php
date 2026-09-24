<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Presence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Presence\StackEvents;

final class StackEventsTest extends TestCase
{
    #[Test]
    public function the_newest_event_comes_first_and_names_who_or_that_nobody_joined(): void
    {
        $root = sys_get_temp_dir() . '/semitexa-stack-' . uniqid();
        mkdir($root . '/var/ai-work', 0o755, true);
        file_put_contents($root . '/' . StackEvents::FILE, implode("\n", [
            '{"at":"2026-09-24T09:00:00+00:00","action":"start","by":null,"user":"t","detail":""}',
            'not json',
            '{"at":"2026-09-24T09:10:00+00:00","action":"restart","by":"codex-abc123","user":"t","detail":"app"}',
        ]) . "\n");

        $events = (new StackEvents($root))->recent(5, (int) strtotime('2026-09-24T09:13:00+00:00'));
        exec('rm -rf ' . escapeshellarg($root));

        self::assertSame(['restart', 'start'], array_column($events, 'action'));
        self::assertSame('restart (app) by codex-abc123, 3 min ago', StackEvents::describe($events[0]));
        self::assertStringContainsString('by an agent that never joined', StackEvents::describe($events[1]));
    }
}

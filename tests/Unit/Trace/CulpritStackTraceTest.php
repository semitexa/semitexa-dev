<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\CulpritStackTrace;

/**
 * A thrown error, rearranged so the line that caused it is read first.
 *
 * What this replaces: `getTraceAsString()` cut to six lines, which in this
 * framework is usually the machinery getting TO the caller's code — container,
 * pipeline, console — while the frame that matters sits below the cut.
 */
final class CulpritStackTraceTest extends TestCase
{
    /**
     * `Exception::getTrace()` is final, so the stack is handed over directly —
     * see CulpritStackTrace::fromFrames() on why that entry point exists.
     *
     * @param list<array{file: string, line: int, function: string, class: ?string}> $frames
     */
    private function withFrames(array $frames): CulpritStackTrace
    {
        return CulpritStackTrace::fromFrames(
            \RuntimeException::class,
            'it broke',
            $frames,
            '/app/src/modules/Shop/Handler.php',
            42,
        );
    }

    #[Test]
    public function the_first_frame_that_is_not_plumbing_is_the_culprit(): void
    {
        $trace = $this->withFrames([
            ['file' => '/app/packages/semitexa-core/src/Container/Container.php', 'line' => 10, 'function' => 'resolve', 'class' => 'Semitexa\\Core\\Container\\Container'],
            ['file' => '/app/packages/semitexa-core/src/Pipeline/RouteExecutor.php', 'line' => 20, 'function' => 'execute', 'class' => 'Semitexa\\Core\\Pipeline\\RouteExecutor'],
            ['file' => '/app/src/modules/Shop/Handler.php', 'line' => 42, 'function' => 'handle', 'class' => 'App\\Modules\\Shop\\Handler'],
        ]);

        self::assertSame('/app/src/modules/Shop/Handler.php', $trace->culprit['file']);
        self::assertSame(42, $trace->culprit['line']);
    }

    /**
     * Plumbing is a ROLE, not an address. This framework is developed in this
     * repository, so a bug in semitexa/cache is exactly the culprit somebody
     * wants named — treating everything under packages/ as framework would hide
     * the answer from the people who work on it most.
     */
    #[Test]
    public function a_package_frame_that_is_not_plumbing_can_be_the_culprit(): void
    {
        $trace = $this->withFrames([
            ['file' => '/app/packages/semitexa-core/src/Container/Container.php', 'line' => 10, 'function' => 'resolve', 'class' => 'Semitexa\\Core\\Container\\Container'],
            ['file' => '/app/packages/semitexa-cache/src/Application/Service/CacheManager.php', 'line' => 7, 'function' => 'put', 'class' => 'Semitexa\\Cache\\Application\\Service\\CacheManager'],
        ]);

        self::assertSame('Semitexa\\Cache\\Application\\Service\\CacheManager', $trace->culprit['class']);
    }

    #[Test]
    public function a_vendor_frame_is_plumbing_wherever_it_sits(): void
    {
        $trace = $this->withFrames([
            ['file' => '/app/vendor/somebody/lib/Runner.php', 'line' => 3, 'function' => 'run', 'class' => 'Somebody\\Lib\\Runner'],
            ['file' => '/app/src/modules/Shop/Handler.php', 'line' => 42, 'function' => 'handle', 'class' => 'App\\Modules\\Shop\\Handler'],
        ]);

        self::assertSame('/app/src/modules/Shop/Handler.php', $trace->culprit['file']);
    }

    #[Test]
    public function consecutive_plumbing_frames_collapse_into_one_line(): void
    {
        $trace = $this->withFrames([
            ['file' => '/app/vendor/a.php', 'line' => 1, 'function' => 'a', 'class' => 'Symfony\\Component\\Console\\Application'],
            ['file' => '/app/vendor/b.php', 'line' => 2, 'function' => 'b', 'class' => 'Symfony\\Component\\Console\\Command\\Command'],
            ['file' => '/app/packages/semitexa-core/src/Container/Container.php', 'line' => 3, 'function' => 'c', 'class' => 'Semitexa\\Core\\Container\\Container'],
            ['file' => '/app/src/modules/Shop/Handler.php', 'line' => 42, 'function' => 'handle', 'class' => 'App\\Modules\\Shop\\Handler'],
        ]);

        self::assertCount(2, $trace->frames, 'three plumbing frames are one line, then the real one');
        self::assertSame(3, $trace->frames[0]['collapsed']);
        self::assertStringContainsString('plumbing', $trace->frames[0]['label']);
        self::assertSame('handle', $trace->frames[1]['function']);
    }

    /**
     * A failure inside the machinery itself. Saying "no culprit" would hide the
     * only answer there is, so the throw site takes the role.
     */
    #[Test]
    public function an_all_plumbing_trace_falls_back_to_the_throw_site(): void
    {
        $trace = $this->withFrames([
            ['file' => '/app/vendor/a.php', 'line' => 1, 'function' => 'a', 'class' => 'Symfony\\Component\\Console\\Application'],
        ]);

        self::assertNotNull($trace->culprit);
        self::assertSame('(throw site)', $trace->culprit['function']);
        self::assertSame('/app/src/modules/Shop/Handler.php', $trace->culprit['file'], 'the throw site stands in');
    }

    #[Test]
    public function an_empty_trace_is_not_an_error(): void
    {
        $trace = $this->withFrames([]);

        self::assertSame([], $trace->frames);
        self::assertNotNull($trace->culprit);
        self::assertSame('it broke', $trace->message);
    }

    #[Test]
    public function the_envelope_shape_carries_everything_a_reader_needs(): void
    {
        $array = $this->withFrames([
            ['file' => '/app/src/modules/Shop/Handler.php', 'line' => 42, 'function' => 'handle', 'class' => 'App\\Modules\\Shop\\Handler'],
        ])->toArray();

        self::assertSame(['class', 'message', 'culprit', 'frames', 'source'], array_keys($array));
        self::assertSame([], $array['source'], 'no reader was given, so no source was read');
    }
}

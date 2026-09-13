<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\RequestTracer;

/**
 * Which traces are allowed to leave the machine.
 *
 * A trace FILE and an OTLP export answer different questions. An SSE connection
 * earns a file without asking — it cannot carry `?__trace=1`, and the panel
 * needs the session — but it was exported on the same flag, which made
 * switching the exporter on a very different decision from the one it looks
 * like: every disconnect became a synchronous POST inside the request, and a
 * collector that merely answers slowly held the worker for the full
 * OTEL_EXPORTER_OTLP_TIMEOUT_SECONDS (2s) each time. MEASURED 2026-09-12 on
 * loopback: 248us for 16 spans, 903us for 256, 412us with nothing listening,
 * +1.0015s against a collector sleeping one second.
 *
 * So export follows the MARKER — the developer who asked — and SSE joins only
 * on an explicit opt-in.
 */
final class TraceExportScopeTest extends TestCase
{
    private ?string $previous = null;

    protected function setUp(): void
    {
        $this->previous = getenv('SEMITEXA_OTEL_EXPORT_SSE') === false
            ? null
            : (string) getenv('SEMITEXA_OTEL_EXPORT_SSE');
        putenv('SEMITEXA_OTEL_EXPORT_SSE');
    }

    protected function tearDown(): void
    {
        if ($this->previous === null) {
            putenv('SEMITEXA_OTEL_EXPORT_SSE');
        } else {
            putenv('SEMITEXA_OTEL_EXPORT_SSE=' . $this->previous);
        }
    }

    /** @param array<string, mixed> $context */
    private function exports(array $context): bool
    {
        $method = new \ReflectionMethod(RequestTracer::class, 'wantsExport');

        return (bool) $method->invoke(new RequestTracer(), $context);
    }

    /** @param array<string, mixed> $context */
    private function writesFile(array $context): bool
    {
        $method = new \ReflectionMethod(RequestTracer::class, 'wantsFile');

        return (bool) $method->invoke(new RequestTracer(), $context);
    }

    #[Test]
    public function a_marked_request_is_exported(): void
    {
        self::assertTrue($this->exports(['marker' => '1']));
    }

    #[Test]
    public function an_ordinary_request_is_not(): void
    {
        self::assertFalse($this->exports([]));
        self::assertFalse($this->exports(['marker' => null]));
        self::assertFalse($this->exports(['marker' => '']));
        self::assertFalse($this->exports(['marker' => '0']));
    }

    /** The defect: a file, yes — a POST to somebody's collector, no. */
    #[Test]
    public function an_sse_connection_still_earns_a_file_but_is_not_exported(): void
    {
        self::assertTrue($this->writesFile(['sse' => true]), 'the panel needs the session');
        self::assertFalse($this->exports(['sse' => true]), 'nobody asked for this one');
    }

    #[Test]
    public function sse_is_exported_when_the_operator_asks_for_it(): void
    {
        foreach (['1', 'true', 'yes', 'on', 'ON', ' True '] as $value) {
            putenv('SEMITEXA_OTEL_EXPORT_SSE=' . $value);
            self::assertTrue($this->exports(['sse' => true]), "opt-in '{$value}' must be honoured");
        }
    }

    #[Test]
    public function an_unset_or_negative_opt_in_keeps_sse_out(): void
    {
        foreach (['0', 'false', 'no', 'off', ''] as $value) {
            putenv('SEMITEXA_OTEL_EXPORT_SSE=' . $value);
            self::assertFalse($this->exports(['sse' => true]), "'{$value}' must not turn it on");
        }
    }

    /** A marked SSE connection is still a developer asking, opt-in or not. */
    #[Test]
    public function a_marker_wins_over_the_sse_rule(): void
    {
        self::assertTrue($this->exports(['sse' => true, 'marker' => '1']));
    }
}

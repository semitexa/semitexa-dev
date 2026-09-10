<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\Otlp\OtlpTraceExporter;

/**
 * The exporter is off by default, and that is a safety property, not a default.
 *
 * semitexa/dev is a `require`, so this class is present on every production
 * install. An exporter that decided for itself when to run would start making
 * outbound HTTP calls on somebody's live server because they upgraded the
 * framework.
 */
final class OtlpTraceExporterTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT');
        unset($_ENV['OTEL_EXPORTER_OTLP_ENDPOINT'], $_SERVER['OTEL_EXPORTER_OTLP_ENDPOINT']);
        parent::tearDown();
    }

    #[Test]
    public function it_is_off_when_no_endpoint_is_configured(): void
    {
        self::assertFalse(OtlpTraceExporter::isConfigured());
    }

    #[Test]
    public function exporting_without_an_endpoint_does_nothing_at_all(): void
    {
        // No endpoint, no socket, no exception: the guard is inside export()
        // too, not only at the call site, because a second call site added later
        // must not be able to reintroduce the outbound call.
        OtlpTraceExporter::export(['totalMs' => 1.0, 'events' => [
            ['type' => 'begin', 'name' => 'request', 'cid' => 1, 'atMs' => 0.0, 'context' => []],
            ['type' => 'end', 'name' => 'request', 'cid' => 1, 'atMs' => 1.0, 'context' => []],
        ]]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function a_base_url_and_a_full_signal_url_resolve_to_the_same_endpoint(): void
    {
        $resolve = static function (string $configured): string {
            putenv('OTEL_EXPORTER_OTLP_ENDPOINT=' . $configured);
            $method = new \ReflectionMethod(OtlpTraceExporter::class, 'endpoint');

            return (string) $method->invoke(null);
        };

        // The standard variable names the collector's base URL, but people paste
        // the signal URL from a dashboard. Getting this wrong 404s every export
        // and the only symptom is an empty board.
        self::assertSame('http://collector:4318/v1/traces', $resolve('http://collector:4318'));
        self::assertSame('http://collector:4318/v1/traces', $resolve('http://collector:4318/'));
        self::assertSame('http://collector:4318/v1/traces', $resolve('http://collector:4318/v1/traces'));
    }

    #[Test]
    public function a_blank_endpoint_counts_as_unset(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=   ');

        self::assertFalse(OtlpTraceExporter::isConfigured());
    }

    /**
     * ignore_errors makes file_get_contents hand back the error BODY, so a
     * rejected export looked exactly like an accepted one. Raised in review of
     * semitexa-dev#78.
     */
    #[Test]
    public function the_last_status_line_is_the_one_that_counts(): void
    {
        self::assertSame(200, OtlpTraceExporter::statusOf(['HTTP/1.1 200 OK', 'Content-Type: application/json']));
        self::assertSame(401, OtlpTraceExporter::statusOf(['HTTP/1.1 401 Unauthorized']));
        self::assertSame(
            204,
            OtlpTraceExporter::statusOf(['HTTP/1.1 307 Temporary Redirect', 'Location: /v1/traces', 'HTTP/1.1 204 No Content']),
            'a redirect chain leaves one status per hop; only the final answer matters',
        );
        self::assertNull(OtlpTraceExporter::statusOf([]));
    }
}

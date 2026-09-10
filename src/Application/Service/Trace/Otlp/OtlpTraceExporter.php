<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace\Otlp;

use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Environment;

/**
 * Send a recorded trace to an OTLP/HTTP collector — Tempo, Jaeger, Datadog,
 * anything speaking the standard protocol.
 *
 * OFF unless `OTEL_EXPORTER_OTLP_ENDPOINT` is set. That is deliberate and is the
 * whole safety story here: this package ships in production (semitexa/dev is a
 * `require`, not a dev dependency), so an exporter that decided for itself when
 * to run would start making outbound HTTP calls on somebody's live server
 * because they upgraded the framework.
 *
 * JSON over HTTP rather than protobuf over gRPC: every collector accepts it,
 * it needs no extension and no generated code, and a trace export is not a hot
 * path. If it ever becomes one, the mapping in {@see OtlpSpanMapper} is already
 * separate from the transport here.
 *
 * ## It blocks the request that produced the trace
 *
 * The POST is synchronous, inside flush(), at the end of the root span. A
 * collector that is slow therefore adds its latency to that request, up to
 * `OTEL_EXPORTER_OTLP_TIMEOUT_SECONDS` (2s). Measured cost of the mapping alone,
 * in-container on 2026-09-10: 62us for a 16-span trace — the shape of a warm
 * page — and 648us at 256 spans. The transport dominates, not the mapping.
 *
 * Deferring the POST to a coroutine is the obvious next step and is deliberately
 * NOT done here: this runtime cancels parked coroutines on worker exit, and a
 * cancelled park does not always surface as cancelled, so the deferral needs to
 * be designed against those semantics rather than bolted on. Until then, treat
 * the endpoint as something to point at a collector on the same network.
 *
 * Fail-soft, like everything else on this path. A collector that is down, slow
 * or misconfigured must never affect the request that produced the trace — the
 * failure is logged once and swallowed. An observability tool that can take the
 * application down with it is worse than no observability tool.
 */
final class OtlpTraceExporter
{
    private const DEFAULT_TIMEOUT_SECONDS = 2;

    public static function isConfigured(): bool
    {
        return self::endpoint() !== null;
    }

    /**
     * @param array{rootCid?: int|null, totalMs?: float|int, events?: list<array<string, mixed>>} $trace
     */
    public static function export(array $trace): void
    {
        $endpoint = self::endpoint();
        if ($endpoint === null) {
            return;
        }

        try {
            // The wall clock at export time minus the trace's own duration: the
            // buffer records offsets from its start, not absolute times, so the
            // start has to be reconstructed. Good to within the export delay,
            // which is microseconds — and stated here rather than left for
            // someone to discover when two services' spans do not line up.
            $totalMs = is_numeric($trace['totalMs'] ?? null) ? (float) $trace['totalMs'] : 0.0;
            $startedAtNanos = (int) ((microtime(true) - ($totalMs / 1000)) * 1_000_000_000);

            $mapped = OtlpSpanMapper::map($trace, OtlpSpanMapper::id(16), $startedAtNanos);
            if ($mapped['spans'] === []) {
                return;
            }

            $payload = json_encode([
                'resourceSpans' => [[
                    'resource' => ['attributes' => [
                        ['key' => 'service.name', 'value' => ['stringValue' => self::serviceName()]],
                    ]],
                    'scopeSpans' => [['spans' => $mapped['spans']]],
                ]],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($payload === false) {
                return;
            }

            self::post($endpoint, $payload);

            if ($mapped['unclosed'] > 0) {
                StaticLoggerBridge::warning('trace', 'OTLP export dropped spans that never closed', [
                    'unclosed' => $mapped['unclosed'],
                ]);
            }
        } catch (\Throwable $e) {
            StaticLoggerBridge::warning('trace', 'OTLP export failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function post(string $endpoint, string $payload): void
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => self::timeout(),
            // A 4xx from the collector is information, not a reason to emit a
            // PHP warning into the log of the application being observed.
            'ignore_errors' => true,
        ]]);

        $response = @file_get_contents($endpoint, false, $context);
        if ($response === false) {
            StaticLoggerBridge::warning('trace', 'OTLP collector did not answer', ['endpoint' => $endpoint]);

            return;
        }

        // ignore_errors keeps a 4xx from raising a PHP warning, which also
        // means file_get_contents returns the error BODY and looks like a
        // success. Without reading the status back, a misconfigured endpoint
        // exported nothing and said nothing. Raised in review of
        // semitexa-dev#78.
        $status = self::statusOf($http_response_header ?? []);
        if ($status !== null && ($status < 200 || $status > 299)) {
            StaticLoggerBridge::warning('trace', 'OTLP collector rejected the export', [
                'endpoint' => $endpoint,
                'status' => $status,
            ]);
        }
    }

    /**
     * The status of the FINAL response in the header list — a redirect chain
     * leaves one status line per hop, and only the last one is the answer.
     *
     * @param list<string> $headers
     */
    public static function statusOf(array $headers): ?int
    {
        $status = null;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return $status;
    }

    private static function endpoint(): ?string
    {
        $configured = Environment::getEnvValue('OTEL_EXPORTER_OTLP_ENDPOINT');
        if (!is_string($configured) || trim($configured) === '') {
            return null;
        }

        $configured = rtrim(trim($configured), '/');

        // The standard variable names the collector's BASE url; the traces
        // signal lives under /v1/traces. Accepting either spelling avoids the
        // silent half-configured state where every export 404s and the only
        // symptom is an empty dashboard.
        return str_ends_with($configured, '/v1/traces') ? $configured : $configured . '/v1/traces';
    }

    private static function serviceName(): string
    {
        $configured = Environment::getEnvValue('OTEL_SERVICE_NAME');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : 'semitexa';
    }

    private static function timeout(): int
    {
        $configured = Environment::getEnvValue('OTEL_EXPORTER_OTLP_TIMEOUT_SECONDS');

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : self::DEFAULT_TIMEOUT_SECONDS;
    }
}

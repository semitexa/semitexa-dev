<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality;

use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\ModuleRegistry;

/**
 * What each page costs, read from a real request to a running server.
 *
 * In-process dispatch was tried first and measured the wrong thing: without a
 * Swoole worker's boot the asset and template registries are unwired, and the
 * home page came back as a 500 — a cost of the error page, not of the page. A
 * running server is the only place the pipeline is whole, so the probe asks it:
 * one GET per route, marked with a token in X-Semitexa-Trace so the trace file
 * it produces can be found among everyone else's and read. It is removed when
 * the probe may: the server writes traces as its own user, and a probe running as
 * another one leaves them where tracing always leaves them.
 *
 * Counts, never milliseconds. Timing moves with the machine and the load; the
 * number of statements a request issues does not, which is why a count survives
 * being compared across a laptop and a release clone.
 */
final class RequestCostProbe
{
    private const TIMEOUT_S = 5.0;

    /** @var array<string, list<array<string, mixed>>>|null path => query events, per probe run */
    private static ?array $cache = null;

    /** @var list<string> */
    private static array $unmeasured = [];

    /**
     * Query events per probed route. Measured once per process and shared by
     * every request-cost metric, so two metrics do not double the traffic.
     *
     * @return array<string, list<array<string, mixed>>> "GET /path" => query events
     *
     * @throws \RuntimeException when no server answers — a probe that cannot
     *                           measure must not read as a clean measurement
     */
    public static function queriesByRoute(string $projectRoot): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $base = self::baseUrl();
        $out = [];
        $unmeasured = [];
        foreach (self::corpus() as $path) {
            $token = 'quality-' . bin2hex(random_bytes(8));
            $before = array_flip(glob($projectRoot . '/var/trace/*.json') ?: []);
            $trace = self::get($base . $path, $token)
                ? self::findTrace($projectRoot . '/var/trace', $token, $before)
                : null;
            if ($trace === null) {
                $unmeasured[] = 'GET ' . $path;
                continue;
            }
            $out['GET ' . $path] = self::queriesIn($trace);
            @unlink($trace);
        }
        if ($out === []) {
            throw new \RuntimeException("request cost: {$base} answered but produced no trace — is it in dev mode?");
        }

        self::$unmeasured = $unmeasured;

        return self::$cache = $out;
    }

    /**
     * Stand in for a server, for a test of what a metric makes of the traces.
     *
     * @param array<string, list<array<string, mixed>>> $queriesByRoute
     * @param list<string>                              $unmeasured
     */
    public static function prime(array $queriesByRoute, array $unmeasured = []): void
    {
        self::$cache = $queriesByRoute;
        self::$unmeasured = $unmeasured;
    }

    public static function reset(): void
    {
        self::$cache = null;
        self::$unmeasured = [];
    }

    /**
     * Routes the last probe could not read — no answer, or no trace in time.
     *
     * Reported rather than dropped: a route that silently fell out of the
     * probe would take its duplicates with it, and the ledger would read that
     * as a fix.
     *
     * @return list<string>
     */
    public static function unmeasured(): array
    {
        return self::$unmeasured;
    }

    /**
     * GET routes a probe can call as-is: no path parameters, not the
     * framework's own /__ endpoints (streams, the panel, traces themselves).
     *
     * @return list<string>
     */
    private static function corpus(): array
    {
        $discovery = new AttributeDiscovery(new ClassDiscovery(), new ModuleRegistry(), new RouteRegistry());
        $discovery->initialize();
        $paths = [];
        foreach ($discovery->getRoutes() as $route) {
            $path = (string) ($route['path'] ?? '');
            $methods = (array) ($route['methods'] ?? [$route['method'] ?? 'GET']);
            if ($path === '' || !in_array('GET', $methods, true) || str_contains($path, '{') || str_starts_with($path, '/__')) {
                continue;
            }
            $paths[$path] = true;
        }
        $paths = array_keys($paths);
        sort($paths);

        return $paths;
    }

    private static function baseUrl(): string
    {
        $port = getenv('SWOOLE_PORT') ?: '9501';
        $candidates = array_filter([
            getenv('SEMITEXA_QUALITY_BASE_URL') ?: null,
            // Inside the app container, and from a one-off container on the
            // compose network, respectively.
            "http://127.0.0.1:{$port}",
            "http://app:{$port}",
        ]);
        foreach ($candidates as $base) {
            if (self::status(rtrim($base, '/') . '/', null, 2.0) !== null) {
                return rtrim($base, '/');
            }
        }

        throw new \RuntimeException('request cost: no running server at ' . implode(' or ', $candidates)
            . ' — start it (bin/semitexa server:start) or set SEMITEXA_QUALITY_BASE_URL');
    }

    private static function get(string $url, string $token): bool
    {
        return self::status($url, $token, self::TIMEOUT_S) !== null;
    }

    /**
     * The HTTP status, or null when nothing answered. Any status counts as an
     * answer: a 4xx or 5xx page still has a cost worth reading.
     */
    private static function status(string $url, ?string $token, float $timeout): ?int
    {
        // PHP fills this local on every response the http wrapper reads and
        // leaves it untouched when the connection fails — so it is cleared
        // first, or a dead address would inherit the previous one's answer.
        $http_response_header = [];
        @file_get_contents($url, false, self::context($token, $timeout));
        $first = $http_response_header[0] ?? '';

        return preg_match('#^HTTP/\S+\s+(\d{3})#', $first, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * @return resource
     */
    private static function context(?string $token, float $timeout)
    {
        $headers = ['Accept: text/html'];
        if ($token !== null) {
            $headers[] = 'X-Semitexa-Trace: ' . $token;
        }

        return stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $timeout,
            // 4xx/5xx still have a trace worth reading; a redirect is not followed,
            // so the cost is this route's and not its target's.
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
    }

    /**
     * The trace is flushed after the response is sent, so it can land a moment
     * after the body arrives. Only files that appeared since the request are
     * read — reading the newest forty per route made a 200-route probe take
     * over a minute.
     *
     * @param array<string, int> $before paths present before the request
     */
    private static function findTrace(string $dir, string $token, array $before): ?string
    {
        $needle = json_encode($token);
        for ($attempt = 0; $attempt < 40; $attempt++) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                if (isset($before[$file])) {
                    continue;
                }
                $body = (string) @file_get_contents($file);
                if (str_contains($body, '"marker":' . $needle) || str_contains($body, '"marker": ' . $needle)) {
                    return $file;
                }
                $before[$file] = 0;
            }
            usleep(25_000);
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function queriesIn(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true);
        $events = is_array($data['events'] ?? null) ? $data['events'] : [];

        return array_values(array_filter(
            array_map(static fn (mixed $e): array => is_array($e) && ($e['type'] ?? null) === 'query' ? (array) ($e['context'] ?? []) : [],
                $events),
            static fn (array $q): bool => $q !== [],
        ));
    }
}

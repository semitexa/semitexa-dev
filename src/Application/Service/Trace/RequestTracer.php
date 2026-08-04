<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Environment;
use Semitexa\Core\Pipeline\RequestTracerInterface;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Records one request's path through the framework, for a developer who asked
 * for that one request.
 *
 * ## Why it is opt-in per request, not per environment
 *
 * A developer traces the request they are investigating. Recording every request
 * in dev would bury that one under hundreds of page loads, asset requests and
 * SSE polls, and the interesting trace is the hardest to find in a pile of
 * uninteresting ones. So collection starts only when the request carries an
 * explicit marker.
 *
 * That also removes the usual dev-tool hazard. This service is registered
 * whenever semitexa/dev is installed, including on a machine where somebody
 * installed dev dependencies in production by mistake — but it still records
 * nothing, because no ordinary request carries the marker, and APP_ENV must be
 * dev as well.
 *
 * ## Contract obligations
 *
 * {@see RequestTracerInterface} forbids throwing: an observer that breaks the
 * request it observes turns a diagnostic into a source of faults. Every public
 * method here is wrapped, and a failure disables collection for the rest of the
 * request rather than retrying into the same error on every span.
 */
#[SatisfiesServiceContract(of: RequestTracerInterface::class)]
final class RequestTracer implements RequestTracerInterface
{
    /** @var list<array<string, mixed>> flat event log; nesting is rebuilt from depth */
    private array $events = [];

    private bool $recording = false;
    private bool $failed = false;
    private int $depth = 0;
    private float $startedAt = 0.0;

    /** @var array<string, float> open span name => start time in nanoseconds */
    private array $open = [];

    public function begin(string $name, array $context = []): void
    {
        try {
            if ($name === 'request') {
                $this->recording = $this->shouldRecord($context);
                $this->startedAt = (float) hrtime(true);
                $this->events = [];
                $this->depth = 0;
                $this->open = [];
            }

            if (!$this->recording || $this->failed) {
                return;
            }

            $this->open[$name] = (float) hrtime(true);
            $this->events[] = [
                'type' => 'begin',
                'name' => $name,
                'depth' => $this->depth,
                'atMs' => $this->sinceStartMs(),
                'context' => $this->scrub($context),
            ];
            $this->depth++;
        } catch (\Throwable) {
            $this->failed = true;
        }
    }

    public function end(string $name, array $context = []): void
    {
        try {
            if (!$this->recording || $this->failed) {
                return;
            }

            $this->depth = max(0, $this->depth - 1);
            $started = $this->open[$name] ?? null;
            unset($this->open[$name]);

            $this->events[] = [
                'type' => 'end',
                'name' => $name,
                'depth' => $this->depth,
                'atMs' => $this->sinceStartMs(),
                'durationMs' => $started !== null
                    ? round(((float) hrtime(true) - $started) / 1_000_000, 3)
                    : null,
                'context' => $this->scrub($context),
            ];

            if ($name === 'request') {
                $this->flush();
            }
        } catch (\Throwable) {
            $this->failed = true;
        }
    }

    public function mark(string $name, array $context = []): void
    {
        try {
            if (!$this->recording || $this->failed) {
                return;
            }

            $this->events[] = [
                'type' => 'mark',
                'name' => $name,
                'depth' => $this->depth,
                'atMs' => $this->sinceStartMs(),
                'context' => $this->scrub($context),
            ];
        } catch (\Throwable) {
            $this->failed = true;
        }
    }

    /**
     * Two independent conditions, both required.
     *
     * @param array<string, mixed> $context
     */
    private function shouldRecord(array $context): bool
    {
        if (Environment::getEnvValue('APP_ENV') !== 'dev') {
            return false;
        }

        // The marker is handed in by RouteExecutor, which holds the Request.
        // This service is resolved from the application container and has no
        // request of its own - and under Swoole the superglobals are empty, so
        // reading $_GET here would pass every unit test and record nothing in a
        // real worker.
        $marker = $context['marker'] ?? null;

        return is_string($marker) && $marker !== '' && $marker !== '0';
    }

    /**
     * Values are recorded for reading, so anything that is not obviously a scalar
     * is reduced to its shape. A trace is written to disk and read later by a
     * human; embedding request payloads wholesale would put user data in a file
     * nobody remembers writing.
     *
     * @param  array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function scrub(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $out[$key] = match (true) {
                $value === null, is_bool($value), is_int($value), is_float($value) => $value,
                is_string($value) => mb_substr($value, 0, 200),
                is_array($value) => '[array:' . count($value) . ']',
                is_object($value) => $value::class,
                default => get_debug_type($value),
            };
        }

        return $out;
    }

    private function sinceStartMs(): float
    {
        return round(((float) hrtime(true) - $this->startedAt) / 1_000_000, 3);
    }

    private function flush(): void
    {
        // Env rather than a constructor parameter: container-managed services must
        // have a parameterless constructor, so an injected path is not available.
        // SEMITEXA_TRACE_DIR also lets a developer send traces somewhere else
        // without touching code.
        $configured = Environment::getEnvValue('SEMITEXA_TRACE_DIR');
        $dir = is_string($configured) && $configured !== ''
            ? $configured
            : ProjectRoot::get() . '/var/trace';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $payload = json_encode(
            ['recordedAt' => date('c'), 'events' => $this->events],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($payload === false) {
            return;
        }

        // Written through a temp file and renamed: a reader tailing the directory
        // must never pick up a half-written trace and report it as a request that
        // stopped early.
        $final = $dir . '/' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.json';
        $tmp = $final . '.tmp';
        if (@file_put_contents($tmp, $payload) !== false) {
            @rename($tmp, $final);
        }

        $this->recording = false;
        $this->events = [];
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Environment;
use Semitexa\Core\Pipeline\RequestTracerInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Orm\OrmManager;

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
    // No per-request state on this class. It is a single instance shared by the
    // whole worker, so a concurrent untraced request would otherwise reset the
    // recording flag and clear the events of a traced request running beside it.
    // Everything lives in a TraceBuffer held in the coroutine context.

    #[InjectAsReadonly]
    protected OrmManager $orm;

    public function begin(string $name, array $context = []): void
    {
        try {
            // Both names can open a trace. 'sse' has to, because an SSE
            // connection is served by SseKissHandler INSIDE RouteExecutor rather
            // than intercepted ahead of routing - so when the surrounding page
            // request carries no marker there is no buffer for the SSE span to
            // land in, and tying the start to 'request' alone meant an SSE trace
            // could never begin. Starting only when nothing is open keeps a
            // marked page request as one trace instead of splitting it.
            if (($name === 'request' || $name === 'sse') && TraceContext::current() === null) {
                if (!$this->shouldRecord($context)) {
                    return;
                }

                TraceContext::begin(new TraceBuffer(
                    startedAt: (float) hrtime(true),
                    rootCid: TraceContext::identity()['cid'],
                    rootSpan: $name,
                ));
                $this->orm()->enableQueryLog();
            }

            $buffer = TraceContext::current();
            if ($buffer === null || $buffer->failed) {
                return;
            }

            if ($name === $buffer->rootSpan) {
                $buffer->rootOpen++;
            }

            $cid = TraceContext::identity()['cid'];
            $buffer->push($this->event('begin', $name, $buffer, $context));
            $buffer->enter($cid, $name, (float) hrtime(true));
        } catch (\Throwable) {
            $this->markFailed();
        }
    }

    public function end(string $name, array $context = []): void
    {
        try {
            $buffer = TraceContext::current();
            if ($buffer === null) {
                return;
            }

            // Read before the failure check. A buffer that failed mid-trace still
            // holds the ORM query log open, and a log left enabled goes on
            // collecting every statement the worker runs from then on - so the
            // root span has to finalize whether the recording is usable or not.
            //
            // Counted rather than matched on the name: only the OUTERMOST root
            // span closing ends the trace, so a nested dispatch cannot cut it
            // short halfway through.
            $isRoot = false;
            if ($name === $buffer->rootSpan) {
                $buffer->rootOpen = max(0, $buffer->rootOpen - 1);
                $isRoot = $buffer->rootOpen === 0;
            }

            if (!$buffer->failed) {
                $cid = TraceContext::identity()['cid'];
                $started = $buffer->leave($cid, $name);

                $event = $this->event('end', $name, $buffer, $context);
                $event['durationMs'] = $started !== null
                    ? round(((float) hrtime(true) - $started) / 1_000_000, 3)
                    : null;
                $buffer->push($event);
            }

            // Flush when the span that OPENED this buffer closes, not on a fixed
            // name: an SSE connection opened its own, a page request opened its
            // own, and closing on 'request' alone would leave an SSE trace on
            // disk-less forever.
            if ($isRoot) {
                $this->appendQueries($buffer);
                if (!$buffer->failed) {
                    $this->flush($buffer);
                }
                TraceContext::end();
            }
        } catch (\Throwable) {
            $this->markFailed();
        }
    }

    public function mark(string $name, array $context = []): void
    {
        try {
            $buffer = TraceContext::current();
            if ($buffer === null || $buffer->failed) {
                return;
            }

            $buffer->push($this->event('mark', $name, $buffer, $context));
        } catch (\Throwable) {
            $this->markFailed();
        }
    }

    /**
     * Every event carries the coroutine it was recorded from, and that
     * coroutine's parent. Two spans with different cids and overlapping times ran
     * concurrently; the viewer can state that instead of inferring it from clocks
     * that happen to overlap.
     *
     * @param  array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function event(string $type, string $name, TraceBuffer $buffer, array $context): array
    {
        $identity = TraceContext::identity();

        return [
            'type' => $type,
            'name' => $name,
            'depth' => $buffer->depth($identity['cid']),
            'atMs' => $buffer->sinceStartMs(),
            'cid' => $identity['cid'],
            'pcid' => $identity['pcid'],
            'context' => $this->scrub($context),
        ];
    }

    private function markFailed(): void
    {
        $buffer = TraceContext::current();
        if ($buffer !== null) {
            $buffer->failed = true;
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

        // SSE connections are traced without a marker. The browser builds that
        // URL, so a query parameter can never be on it unless the page puts it
        // there - and a developer cannot mark the connection they care about
        // after the fact. There are few SSE connections in a dev session, so
        // recording all of them costs little and is the only way this path is
        // reachable at all.
        if (($context['sse'] ?? false) === true) {
            return true;
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

    /**
     * The OrmManager, injected under the container and built lazily otherwise -
     * the same shape OrmBackedStore uses.
     */
    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }

    /**
     * Fold the recorded queries into the trace as marks, then stop recording.
     *
     * Queries appear as events rather than a separate section so they sit in the
     * same timeline as the spans - the point is seeing that forty of them
     * happened inside one handler, which a separate list would not show.
     */
    private function appendQueries(TraceBuffer $buffer): void
    {
        try {
            $queries = $this->orm()->drainQueryLog();
        } catch (\Throwable) {
            // No database configured, or ORM not booted. Not an error here: a
            // trace without queries is still a useful trace.
            return;
        } finally {
            // finally, because a drain that throws would otherwise leave the log
            // enabled for the life of the worker - an unbounded list of every
            // statement it runs, which is the one thing this switch documents as
            // unsafe to leave on.
            try {
                $this->orm()->disableQueryLog();
            } catch (\Throwable) {
                // Nothing further to try, and nowhere to report it from.
            }
        }

        $total = 0.0;
        foreach ($queries as $q) {
            $total += $q['timeMs'];
            $buffer->push([
                'type' => 'query',
                'name' => 'orm.query',
                'depth' => 1,
                'atMs' => null,
                'durationMs' => round($q['timeMs'], 3),
                'context' => ['sql' => mb_substr($q['sql'], 0, 300), 'params' => count($q['params'])],
            ]);
        }

        if ($queries !== []) {
            $buffer->push([
                'type' => 'mark',
                'name' => 'orm.summary',
                'depth' => 0,
                'atMs' => $buffer->sinceStartMs(),
                'context' => ['queries' => count($queries), 'totalMs' => round($total, 3)],
            ]);
        }
    }

    private function flush(TraceBuffer $buffer): void
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
            [
                'recordedAt' => date('c'),
                'truncated' => $buffer->truncated,
                // Outside the capped list on purpose. A long SSE connection can
                // reach the cap before its root span closes, and the end event
                // that carries the total is then the one dropped - leaving the
                // viewer to scale every bar against a total of zero.
                'totalMs' => $buffer->sinceStartMs(),
                'events' => $buffer->events,
            ],
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

    }
}

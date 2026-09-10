<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\ObservatoryStreamPayload;
use Semitexa\Dev\Application\Service\Trace\CoroutineSnapshot;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;
use Semitexa\Dev\Application\Service\Trace\ObservatoryReader;

/**
 * Holds one SSE connection open and pushes journal batches down it.
 *
 * ## Why this is not the ssr SSE server
 *
 * dev must not depend on ssr, and the panel must work on a stack where only
 * core and dev are installed. So this handler takes the raw Swoole response
 * pair the way the GraphQL streamer does (through core's SwooleBootstrap),
 * writes `text/event-stream` frames itself, and runs its own small tick loop.
 * It has none of the multiplexing, auth refresh or cross-worker delivery the
 * ssr server does — it needs none: the journal file is the shared medium,
 * and every tick is the same bounded read the polling feed does.
 *
 * ## Leaving cleanly
 *
 * The loop ends when the client goes away (a write fails), when the worker
 * begins draining ({@see \Semitexa\Core\Lifecycle\WorkerDrainSignal} — the
 * drain cancels parked coroutines, and a cancelled sleep returns instead of
 * throwing, so the flag is checked on every wake), when the coroutine is
 * cancelled, or after the age cap. Outside a Swoole worker (CLI, tests) there
 * is nothing to hold open and the handler answers 503 with `{"sse":false}`,
 * which is exactly what makes the panel fall back to polling.
 */
#[AsPayloadHandler(payload: ObservatoryStreamPayload::class, resource: ResourceResponse::class)]
final class ObservatoryStreamHandler implements TypedHandlerInterface
{
    /** Seconds between journal reads while the connection is open. */
    public const TICK_SECONDS = 0.25;

    /** An SSE comment this often keeps proxies and the browser from giving up on a quiet stream. */
    private const KEEPALIVE_SECONDS = 15;

    /** After this the stream closes itself; the client reconnects with its cursor and nothing is lost. */
    private const MAX_AGE_SECONDS = 2 * 3600;

    #[InjectAsReadonly]
    protected ObservatoryReader $reader;

    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    public function handle(ObservatoryStreamPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!$this->gate->allows()) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        $pair = $this->swoolePair();
        if ($pair === null) {
            return $resource
                ->setStatusCode(HttpStatus::ServiceUnavailable->value)
                ->setHeader('Content-Type', 'application/json; charset=utf-8')
                ->setHeader('Cache-Control', 'no-store')
                ->setContent('{"sse":false,"reason":"no live Swoole connection to hold open; poll /__observatory/feed?stream=1 instead"}');
        }

        $response = $pair[1];
        $response->status(HttpStatus::Ok->value);
        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');
        $response->header('Connection', 'keep-alive');
        $response->header('X-Accel-Buffering', 'no');

        $this->pump($response, $payload->after !== '' ? $payload->after : null);

        @$response->end();

        return $resource->setContent('')->markAsAlreadySent();
    }

    /**
     * @param \Swoole\Http\Response $response
     */
    private function pump(object $response, ?string $cursor): void
    {
        $startedAt = time();
        $lastWrite = microtime(true);
        $tick = 0;

        // Say hello first: the browser fires `open` only once bytes arrive,
        // and the panel switches its transport label on that.
        if (!$this->write($response, 'hello', ['transport' => 'sse', 'worker' => getmypid()])) {
            return;
        }

        while (true) {
            // A client that left is noticed on the next tick, not on the next
            // keepalive fifteen seconds later: the journal gets its end line
            // while the panel still remembers the session.
            if (method_exists($response, 'isWritable') && !$response->isWritable()) {
                return;
            }
            if ($this->mustStop() || time() - $startedAt > self::MAX_AGE_SECONDS) {
                $this->write($response, 'close', ['reason' => time() - $startedAt > self::MAX_AGE_SECONDS ? 'max_age' : 'worker_draining']);

                return;
            }

            $batch = $this->reader->stream($cursor);
            $cursor = $batch['cursor'];
            // Coroutines only every other tick: the snapshot is what makes
            // this worker's occupancy visible while the stream is pinned here.
            if (($tick++ % 2) === 0) {
                CoroutineSnapshot::maybeWrite();
            }

            if ($batch['reset'] || $batch['rows'] !== []) {
                if (!$this->write($response, 'batch', $batch)) {
                    return;
                }
                $lastWrite = microtime(true);
            } elseif (microtime(true) - $lastWrite > self::KEEPALIVE_SECONDS) {
                if (!(bool) @$response->write(":\n\n")) {
                    return;
                }
                $lastWrite = microtime(true);
            }

            \Swoole\Coroutine::sleep(self::TICK_SECONDS);
        }
    }

    /** @param array<string, mixed> $data */
    private function write(object $response, string $event, array $data): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return true;
        }

        return (bool) @$response->write('event: ' . $event . "\ndata: " . $json . "\n\n");
    }

    private function mustStop(): bool
    {
        if (class_exists(\Semitexa\Core\Lifecycle\WorkerDrainSignal::class) && \Semitexa\Core\Lifecycle\WorkerDrainSignal::isDraining()) {
            return true;
        }
        if (method_exists(\Swoole\Coroutine::class, 'isCanceled') && \Swoole\Coroutine::isCanceled()) {
            return true;
        }
        if (function_exists('connection_aborted') && connection_aborted()) {
            return true;
        }

        return false;
    }

    /** @return array{0: object, 1: object, 2: object}|null */
    private function swoolePair(): ?array
    {
        if (!class_exists(\Semitexa\Core\Server\SwooleBootstrap::class) || !class_exists(\Swoole\Coroutine::class, false)) {
            return null;
        }
        try {
            $pair = \Semitexa\Core\Server\SwooleBootstrap::getCurrentSwooleRequestResponse();
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($pair) || !isset($pair[1]) || !is_object($pair[1]) || !method_exists($pair[1], 'write')) {
            return null;
        }

        return [$pair[0], $pair[1], $pair[2] ?? $pair[1]];
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Cookie\CookieJar;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Queue\QueueConfig;
use Semitexa\Core\Request;
use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Session\SessionHandlerInterface;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Support\PayloadSerializer;
use Semitexa\Orm\OrmManager;

/**
 * Re-runs one recorded process in a sandbox (`ep-observatory`, decision 5).
 *
 * The envelope is what the trace already holds: route + method from the root
 * span, the REDACTED hydrated-payload snapshot from the hydrate span. Redacted
 * fields replay as the literal mask — by design: storing replayable secrets
 * would undo the redaction guarantee, and `--mutate` exists to supply a real
 * value when a case needs one.
 *
 * ## The two hard guards
 *
 * **Writes never commit.** The handler runs inside TransactionManager::run,
 * and the only way out of that closure is ReplayRollbackSignal — success and
 * failure both exit through the rollback branch. No replay code path commits.
 *
 * **Queue handoffs never leave the process.** QueueTransportRegistry is reset
 * and every known transport name rebound to a capturing stub before the
 * handler runs; captured messages are REPORTED, not delivered, and an unknown
 * transport name fails create() instead of reaching a broker. Fail-closed.
 *
 * ## The honest boundary
 *
 * SYNC event listeners run in-process, exactly as ai:invoke runs them — a
 * listener that calls an external service directly (mail, LLM) is NOT stubbed
 * yet. Their spans land in the replay trace, so what ran is at least visible.
 * Port-level stubbing is recorded follow-up work, not silently claimed.
 */
#[AsService]
final class ReplayRunner
{
    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    #[InjectAsReadonly]
    protected TraceReader $traces;

    /**
     * @param  array<string, mixed> $mutations
     * @return array<string, mixed>
     */
    public function replay(string $traceFile, array $mutations): array
    {
        $raw = $this->rawTrace($traceFile);
        if ($raw === null) {
            return ['error' => 'trace-unreadable', 'trace' => $traceFile];
        }

        $envelope = $this->extractEnvelope($raw);
        if (isset($envelope['error'])) {
            return $envelope;
        }

        /** @var array<string, mixed> $input */
        $input = array_merge($envelope['payload'], $mutations);

        try {
            [$handlerClass, $payloadClass, $resourceClass] = $this->resolveRouteTarget(
                (string) $envelope['path'],
                (string) $envelope['method'],
            );
        } catch (\Throwable $e) {
            return ['error' => 'target-unresolvable', 'detail' => $e->getMessage()];
        }

        $queueCaptor = $this->stubQueueTransports();

        $tracer = $this->optionalTracer();
        $before = $this->traceFiles();
        $tracer?->begin('request', [
            'method' => $envelope['method'],
            'path' => $envelope['path'],
            'route' => $envelope['route'],
            'marker' => 'replay',
        ]);

        $run = static function () use ($handlerClass, $payloadClass, $resourceClass, $input, $envelope): array {
            $payload = PayloadSerializer::hydrate(self::instantiate($payloadClass), $input);
            $resource = self::instantiate($resourceClass);

            $container = ContainerFactory::createRequestScoped();
            self::primeRequestContext($container, (string) $envelope['path'], (string) $envelope['method'], $input);
            $handler = $container->get($handlerClass);
            if (!method_exists($handler, 'handle')) {
                throw new \RuntimeException("handler {$handlerClass} has no handle() method");
            }

            return ['result' => $handler->handle($payload, $resource)];
        };

        $outcome = null;
        $handlerError = null;
        $dbGuard = 'transaction-rolled-back';
        $start = microtime(true);
        try {
            $tx = (new OrmManager())->getTransactionManager();
            try {
                $tx->run(static function () use ($run, &$outcome): never {
                    $outcome = $run();
                    throw new ReplayRollbackSignal($outcome);
                });
            } catch (ReplayRollbackSignal) {
                // The guard working, not a failure.
            }
        } catch (\Throwable $e) {
            if ($outcome !== null) {
                // Handler finished; the failure happened after it (rollback
                // machinery). Keep the outcome, report the guard state.
                $dbGuard = 'rollback-uncertain: ' . $e->getMessage();
            } else {
                $handlerError = [
                    'class' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ];
            }
        }
        $durationMs = round((microtime(true) - $start) * 1000, 2);

        $tracer?->end('request');
        $replayTrace = array_values(array_diff($this->traceFiles(), $before))[0] ?? null;

        $result = $outcome['result'] ?? null;

        return [
            'original_trace' => $traceFile,
            'replay_trace' => $replayTrace !== null ? basename($replayTrace) : null,
            'target' => ['handler' => $handlerClass, 'payload' => $payloadClass, 'resource' => $resourceClass],
            'input' => ContextRedactor::redact($input),
            'mutated_keys' => array_keys($mutations),
            'duration_ms' => $durationMs,
            'verdict' => $handlerError !== null ? 'handler_threw' : ($result === null ? 'null_result' : 'ok'),
            'handler_error' => $handlerError,
            'resource_class' => $result !== null ? $result::class : null,
            'resource' => $result !== null ? $this->serialize($result) : null,
            'guards' => [
                'db' => $dbGuard,
                'queue_captured' => $queueCaptor->drain(),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function rawTrace(string $file): ?array
    {
        $path = $this->traces->dir() . '/' . basename($file);
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($decoded) && isset($decoded['events']) ? $decoded : null;
    }

    /** @return array{path: string, method: string, route: ?string, payload: array<string, mixed>}|array{error: string, detail?: string} */
    private function extractEnvelope(array $raw): array
    {
        $root = null;
        $snapshot = [];
        foreach ($raw['events'] as $event) {
            if ($root === null && $event['type'] === 'begin' && in_array($event['name'], ['request', 'sse'], true)) {
                $root = $event;
            }
            if ($event['type'] === 'end' && $event['name'] === 'payload.hydrate_and_validate') {
                $snapshot = $event['context']['snapshot'] ?? [];
            }
        }

        if ($root === null) {
            return ['error' => 'no-root-span'];
        }
        if ($root['name'] === 'sse') {
            return ['error' => 'not-replayable', 'detail' => 'an SSE session is a live connection, not a request to re-run'];
        }

        $path = (string) ($root['context']['path'] ?? '');
        if ($path === '') {
            return ['error' => 'no-route-in-trace'];
        }

        return [
            'path' => $path,
            'method' => (string) ($root['context']['method'] ?? 'GET'),
            'route' => $root['context']['route'] ?? null,
            'payload' => is_array($snapshot) ? $snapshot : [],
        ];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function resolveRouteTarget(string $path, string $method): array
    {
        $this->attributeDiscovery->initialize();
        $route = $this->attributeDiscovery->findRoute($path, $method);
        if ($route === null) {
            foreach ($this->attributeDiscovery->getEnrichedRoutes() as $candidate) {
                $methods = array_map('strtoupper', $candidate['methods'] ?? [$candidate['method'] ?? 'GET']);
                if (($candidate['path'] ?? '') === $path && in_array($method, $methods, true)) {
                    $route = $candidate;
                    break;
                }
            }
        }
        if ($route === null) {
            throw new \RuntimeException("route not found: {$method} {$path}");
        }

        $handler = (string) ($route['handlers'][0]['class'] ?? '');
        $payload = (string) ($route['class'] ?? '');
        $resource = (string) ($route['responseClass'] ?? '');
        if ($handler === '' || $payload === '' || $resource === '') {
            throw new \RuntimeException("route {$method} {$path} is incompletely wired");
        }

        return [$handler, $payload, $resource];
    }

    private function stubQueueTransports(): CapturingQueueTransport
    {
        $captor = new CapturingQueueTransport();
        $factory = new class($captor) implements QueueTransportFactoryInterface {
            public function __construct(private readonly CapturingQueueTransport $captor)
            {
            }

            public function create(): QueueTransportInterface
            {
                return $this->captor;
            }
        };

        // Order matters: initialize() FIRST, captors OVER it. The registry
        // lazily initializes inside create(), so captors registered onto an
        // uninitialized registry would be overwritten by the real nats and
        // database factories the moment a handler publishes. Initializing
        // eagerly latches the flag; the captors then shadow every real
        // factory, and reset() clears the instance cache so nothing already
        // built in this process can bypass them.
        QueueTransportRegistry::reset();
        QueueTransportRegistry::initialize();
        foreach (array_unique([QueueConfig::defaultTransport(), 'in-memory', 'memory', 'database', 'nats', 'sync']) as $name) {
            QueueTransportRegistry::register($name, $factory);
        }

        return $captor;
    }

    private function optionalTracer(): ?\Semitexa\Core\Pipeline\RequestTracerInterface
    {
        $container = ContainerFactory::get();
        $resolved = $container->has(\Semitexa\Core\Pipeline\RequestTracerInterface::class)
            ? $container->get(\Semitexa\Core\Pipeline\RequestTracerInterface::class)
            : null;

        return \Semitexa\Core\Pipeline\SafeRequestTracer::wrap(
            $resolved instanceof \Semitexa\Core\Pipeline\RequestTracerInterface ? $resolved : null,
        );
    }

    /** @return list<string> */
    private function traceFiles(): array
    {
        return glob($this->traces->dir() . '/*.json') ?: [];
    }

    /** @return array<string, mixed>|null */
    private function serialize(object $result): ?array
    {
        try {
            return ContextRedactor::redact(PayloadSerializer::toArray($result));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function instantiate(string $class): object
    {
        if (!class_exists($class)) {
            throw new \RuntimeException("class not found: {$class}");
        }
        $ref = new \ReflectionClass($class);
        $ctor = $ref->getConstructor();

        return ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0)
            ? new $class()
            : $ref->newInstanceWithoutConstructor();
    }

    /** @param array<string, mixed> $payloadData */
    private static function primeRequestContext(
        \Semitexa\Core\Container\RequestScopedContainer $container,
        string $path,
        string $method,
        array $payloadData,
    ): void {
        $bag = [];
        foreach ($payloadData as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            $bag[$k] = is_scalar($v) ? (string) $v : (is_array($v) ? $v : (string) (json_encode($v) ?: ''));
        }

        $request = new Request(
            method: $method,
            uri: $path,
            headers: ['host' => 'replay.local', 'user-agent' => 'semitexa-ai-observe-replay/1'],
            query: in_array($method, ['GET', 'HEAD'], true) ? $bag : [],
            post: in_array($method, ['GET', 'HEAD'], true) ? [] : $bag,
            server: ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path],
            cookies: [],
            content: json_encode($payloadData) ?: null,
        );

        $session = new Session(
            id: 'replay-' . bin2hex(random_bytes(4)),
            handler: new class() implements SessionHandlerInterface {
                /** @var array<string, array<string, mixed>> */
                private array $store = [];

                public function read(string $sessionId): array
                {
                    return $this->store[$sessionId] ?? [];
                }

                public function write(string $sessionId, array $data, int $lifetimeSeconds = 3600): void
                {
                    $this->store[$sessionId] = $data;
                }

                public function destroy(string $sessionId): void
                {
                    unset($this->store[$sessionId]);
                }
            },
            cookieName: 'SEMITEXA_REPLAY',
            lifetimeSeconds: 3600,
        );

        $container->set(Request::class, $request);
        $container->set(SessionInterface::class, $session);
        $container->set(CookieJarInterface::class, new CookieJar($request));
        // A replay runs as a GUEST: the recorded auth claims are exactly the
        // kind of secret redaction strips, so impersonating the original user
        // from a trace is neither possible nor desirable. A case that needs an
        // authenticated view is a --mutate away from being an explicit choice.
        $container->set(\Semitexa\Core\Auth\AuthContextInterface::class, \Semitexa\Core\Auth\GuestAuthContext::getInstance());
    }
}

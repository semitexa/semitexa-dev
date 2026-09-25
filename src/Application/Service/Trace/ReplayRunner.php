<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Cookie\CookieJar;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Environment;
use Semitexa\Core\Resource\Metadata\ResourceMetadataCacheFile;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceMetadataSourceFingerprint;
use Semitexa\Core\Http\PayloadFactory;
use Semitexa\Core\Queue\QueueConfig;
use Semitexa\Core\Request;
use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Lifecycle\SandboxGuard;
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
 * SYNC event listeners run in-process, exactly as ai:invoke runs them. What
 * they reach for on the way out is governed by {@see SandboxGuard}: this runner
 * raises the flag, and a port honours it without either side naming the other —
 * so dev does not have to know every outbound port, and a port does not have to
 * know about replay.
 *
 * COVERED TODAY: mail. `MailTransportRegistry::get()` returns the null
 * transport while the flag is up, and records the attempt, so the envelope's
 * `withheld_outbound` says a listener tried to send rather than hiding it.
 *
 * NOT COVERED YET, and named rather than glossed: outbound webhooks, and the
 * LLM providers — the first has a transport contract and is a small follow-up,
 * the second reaches for curl directly with no port to honour anything. Their
 * spans land in the replay trace, so what ran is at least visible.
 */
#[AsService]
final class ReplayRunner
{
    /** Characters of response body a sandbox run hands back. */
    private const BODY_LIMIT = 1_000_000;

    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    #[InjectAsReadonly]
    protected TraceReader $traces;

    #[InjectAsReadonly]
    protected ResourceMetadataRegistry $resourceMetadata;

    #[InjectAsReadonly]
    protected ResourceMetadataCacheFile $resourceMetadataCache;

    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected Environment $environment;

    #[InjectAsReadonly]
    protected ResourceMetadataSourceFingerprint $resourceMetadataFingerprint;

    /**
     * @param  array<string, mixed> $mutations
     * @return array<string, mixed>
     */
    public function replay(string $traceFile, array $mutations): array
    {
        // Hard guard, not a courtesy: replay EXECUTES recorded requests, and
        // monitor mode exists precisely so a production box can journal
        // without ever running code on demand. Checked here, not only in the
        // command, so no future caller can reach the sandbox outside dev.
        if (!ObservatoryMode::full()) {
            return ['error' => 'replay-requires-dev', 'trace' => $traceFile];
        }

        $raw = $this->rawTrace($traceFile);
        if ($raw === null) {
            return ['error' => 'trace-unreadable', 'trace' => $traceFile];
        }

        $envelope = self::envelopeOf($raw);
        if (isset($envelope['error'])) {
            return $envelope;
        }

        /** @var array<string, mixed> $input */
        $input = array_merge($envelope['payload'], $mutations);

        $result = $this->execute($traceFile, $envelope, $input, $mutations, 'ai:trace replay — re-running a recorded process for inspection');
        unset($result['_body']);

        return $result;
    }

    /**
     * One request run through the sandbox without a recording behind it: the
     * API Explorer's "sandbox" mode. Same guards as a replay — the handler
     * runs inside a rolled-back transaction, queue handoffs are captured, mail
     * is withheld — and the same honest boundary: the handler is called
     * directly, so authentication, middleware and rendering do not run.
     *
     * The registries it arms are process-global, so this must run in a
     * process of its own (the Explorer spawns `ai:observe sandbox`), never
     * inside a worker that is serving other requests.
     *
     * @param  array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function sandbox(string $method, string $path, array $input): array
    {
        if (!ObservatoryMode::full()) {
            return ['error' => 'sandbox-requires-dev'];
        }

        $envelope = ['path' => $path, 'method' => strtoupper($method), 'route' => null, 'payload' => []];

        $result = $this->execute(null, $envelope, $input, [], 'API Explorer sandbox — one request with writes rolled back');

        // The response body in full, beside the redacted resource: a real call
        // hands the same bytes to the same developer's browser, and a body cut
        // at the redactor's 200 characters is not JSON any more. Replay output
        // (read by agents, pasted into chats) stays redacted.
        $body = $result['_body'] ?? null;
        unset($result['_body']);
        if (is_string($body)) {
            $result['body'] = mb_strlen($body) > self::BODY_LIMIT ? mb_substr($body, 0, self::BODY_LIMIT) : $body;
            $result['body_truncated'] = mb_strlen($body) > self::BODY_LIMIT;
        }

        return $result;
    }

    /**
     * @param  array{path: string, method: string, route: ?string, payload: array<string, mixed>} $envelope
     * @param  array<string, mixed> $input
     * @param  array<string, mixed> $mutations
     * @return array<string, mixed>
     */
    private function execute(?string $traceFile, array $envelope, array $input, array $mutations, string $reason): array
    {
        // A worker warms this at WorkerStartFinalize; a CLI process (ai:observe)
        // never boots a worker, and a resource-backed handler then fails on
        // "No ResourceObjectMetadata registered". Same call the worker makes.
        $this->resourceMetadata->ensureWarmed(
            discovery: $this->classDiscovery,
            cache: $this->resourceMetadataCache,
            production: $this->environment->appEnv === 'prod',
            fingerprint: $this->resourceMetadataFingerprint,
        );

        try {
            [$handlerClass, $payloadClass, $resourceClass] = $this->resolveRouteTarget(
                (string) $envelope['path'],
                (string) $envelope['method'],
            );
        } catch (\Throwable $e) {
            return ['error' => 'target-unresolvable', 'detail' => $e->getMessage()];
        }

        $queueCaptor = $this->stubQueueTransports();

        // The flag every outbound port consults on its way out. This runner
        // does NOT reach into mail, webhooks or the LLM providers to swap their
        // transports: that would put semitexa/dev in the position of knowing
        // every port in the ecosystem, and it could only ask whether each
        // package is installed with a runtime class check — the shape
        // `semitexa.explicitOptionalDependency` forbids. The flag lives in
        // core, which everything already requires, and a port honours it
        // without either side naming the other.
        SandboxGuard::enter($reason);

        try {
            $result = $this->runSandboxed($traceFile, $envelope, $input, $mutations, $handlerClass, $payloadClass, $resourceClass, $queueCaptor);

            // Reported, never swallowed: a replay whose listener tried to email
            // a customer should say so. Absent when nothing was withheld, so an
            // ordinary replay's envelope is unchanged.
            $withheld = SandboxGuard::withheldCalls();
            if ($withheld !== []) {
                $result['withheld_outbound'] = $withheld;
            }

            return $result;
        } finally {
            // Both registries are process-global; leaving either armed would
            // change the behaviour of everything LATER in this process —
            // publishes going to the captor instead of a broker, and mail
            // silently dropped. reset() restores lazy initialization, so the
            // next create() rebuilds the real factories.
            QueueTransportRegistry::reset();
            SandboxGuard::leave();
        }
    }

    /**
     * @param  array{path: string, method: string, route: ?string, payload: array<string, mixed>} $envelope
     * @param  array<string, mixed> $input
     * @param  array<string, mixed> $mutations
     * @return array<string, mixed>
     */
    private function runSandboxed(
        ?string $traceFile,
        array $envelope,
        array $input,
        array $mutations,
        string $handlerClass,
        string $payloadClass,
        string $resourceClass,
        CapturingQueueTransport $queueCaptor,
    ): array {
        $tracer = $this->optionalTracer();
        $before = $this->traceFiles();
        $tracer?->begin('request', [
            'method' => $envelope['method'],
            'path' => $envelope['path'],
            'route' => $envelope['route'],
            'marker' => 'replay',
        ]);

        // Built the way RouteExecutor builds it: with the resource's parts, and
        // its #[Inject*] properties filled. A bare instance left a resource that
        // renders through an injected service failing on an uninitialized
        // property before the handler's result could be read.
        $resourceParts = $this->attributeDiscovery->getPayloadPartRegistry()->getResourcePartsForClass($resourceClass);

        $run = static function () use ($handlerClass, $payloadClass, $resourceClass, $resourceParts, $input, $envelope): array {
            $payload = PayloadSerializer::hydrate(self::instantiate($payloadClass), $input);

            $container = ContainerFactory::createRequestScoped();
            self::primeRequestContext($container, (string) $envelope['path'], (string) $envelope['method'], $input);
            $resource = PayloadFactory::createInstance($resourceClass, $resourceParts);
            PropertyInjector::inject($resource, $container);
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
            // is_object, not null-check: handle() is not type-constrained
            // here, and ::class on an array or scalar is a TypeError that
            // would abort the command after the rollback already happened.
            'verdict' => $handlerError !== null ? 'handler_threw' : ($result === null ? 'null_result' : 'ok'),
            'handler_error' => $handlerError,
            'resource_class' => is_object($result) ? $result::class : ($result === null ? null : get_debug_type($result)),
            'resource' => is_object($result) ? $this->serialize($result) : null,
            'guards' => [
                'db' => $dbGuard,
                'queue_captured' => $this->redactCaptured($queueCaptor->drain()),
            ],
            // Unredacted; sandbox() hands it on, replay() drops it.
            '_body' => is_object($result) && method_exists($result, 'getContent') && is_string($result->getContent()) ? $result->getContent() : null,
        ];
    }

    /**
     * Captured queue payloads carry exactly the fields redaction protects
     * (tokens, reset links, addresses) — they must pass the same gate as
     * every other value before reaching the printed envelope.
     *
     * @param  list<array{queue: string, payload: string}> $captured
     * @return list<array<string, mixed>>
     */
    private function redactCaptured(array $captured): array
    {
        $out = [];
        foreach ($captured as $item) {
            $decoded = json_decode($item['payload'], true);
            $out[] = [
                'queue' => $item['queue'],
                'payload' => is_array($decoded)
                    ? ContextRedactor::redact($decoded)
                    : mb_substr($item['payload'], 0, 200),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function rawTrace(string $file): ?array
    {
        $path = $this->traces->dir() . '/' . basename($file);
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($decoded) && isset($decoded['events']) ? $decoded : null;
    }

    /**
     * What a recorded trace can re-run: route, method and the REDACTED
     * hydrated-payload snapshot. Public so the API Explorer can offer the
     * same snapshots as "recorded" variations.
     *
     * @param  array<string, mixed> $raw a decoded trace file
     * `hydrated` tells an empty input from a request stopped before
     * hydration (an auth refusal, say), which carries no snapshot at all.
     *
     * @return array{path: string, method: string, route: ?string, payload: array<string, mixed>, hydrated: bool}|array{error: string, detail?: string}
     */
    public static function envelopeOf(array $raw): array
    {
        $root = null;
        $snapshot = [];
        $hydrated = false;
        foreach ($raw['events'] as $event) {
            if ($root === null && $event['type'] === 'begin' && in_array($event['name'], ['request', 'sse'], true)) {
                $root = $event;
            }
            if ($event['type'] === 'end' && $event['name'] === 'payload.hydrate_and_validate') {
                // The raw input when the trace has it (LiveRequestInput): it goes
                // back through the setters as the request did. The payload
                // snapshot is the fallback for traces recorded before it.
                $snapshot = $event['context']['input'] ?? $event['context']['snapshot'] ?? [];
                $hydrated = isset($event['context']['input']) || isset($event['context']['snapshot']);
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
            // Uppercased at the source: the route table compares methods
            // strictly, and a trace that recorded 'get' must still resolve.
            'method' => strtoupper((string) ($root['context']['method'] ?? 'GET')),
            'route' => $root['context']['route'] ?? null,
            'payload' => is_array($snapshot) ? $snapshot : [],
            'hydrated' => $hydrated,
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
        // Wrapped whole: get() can throw even after has() said true, and an
        // optional observer failing to RESOLVE must degrade to no observer.
        try {
            $container = ContainerFactory::get();
            $resolved = $container->has(\Semitexa\Core\Pipeline\RequestTracerInterface::class)
                ? $container->get(\Semitexa\Core\Pipeline\RequestTracerInterface::class)
                : null;

            return \Semitexa\Core\Pipeline\SafeRequestTracer::wrap(
                $resolved instanceof \Semitexa\Core\Pipeline\RequestTracerInterface ? $resolved : null,
            );
        } catch (\Throwable) {
            return null;
        }
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
            // null means "field absent", not the four-character string "null"
            // json_encode would produce — a handler reading the bag must see
            // the same shape the original request had.
            if ($v === null) {
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

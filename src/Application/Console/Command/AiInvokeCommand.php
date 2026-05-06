<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Cookie\CookieJar;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Request;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Session\SessionHandlerInterface;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Support\PayloadSerializer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Dry-run a payload handler without standing up the HTTP layer.
 *
 * Resolves the handler (either directly by FQCN or via route path), hydrates
 * a Payload from a JSON string, instantiates an empty Resource, and calls
 * `handle(Payload, Resource)` through the container. Returns the resulting
 * resource as JSON, plus timing and any exception trace.
 *
 * Does **not** run the request pipeline — no auth, no middleware, no session,
 * no tenant resolution. That's deliberate: this is the fastest feedback loop
 * for "does my handler do the right thing with input X?", not a stand-in
 * for integration tests.
 *
 * Example:
 *   bin/semitexa ai:invoke --route=/pricing --payload='{}' --json
 *   bin/semitexa ai:invoke --handler='App\\Modules\\Hello\\Handler\\HelloHandler' --payload='{"name":"x"}' --json
 */
#[AsCommand(
    name: 'ai:invoke',
    description: 'Dry-run a payload handler from CLI — skip HTTP/auth/middleware. Use for fast feedback on changes.',
)]
final class AiInvokeCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    public function __construct()
    {
        parent::__construct('ai:invoke');
    }

    protected function configure(): void
    {
        $this
            ->addOption('route', null, InputOption::VALUE_REQUIRED, 'Route path (resolves handler via AttributeDiscovery). Mutually exclusive with --handler.')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'HTTP method when using --route', 'GET')
            ->addOption('handler', null, InputOption::VALUE_REQUIRED, 'Handler FQCN. Mutually exclusive with --route.')
            ->addOption('payload', null, InputOption::VALUE_REQUIRED, 'JSON string hydrated into the Payload DTO via PayloadSerializer', '{}')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON envelope (default for agents)')
            ->addOption('human', null, InputOption::VALUE_NONE, 'Force human-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $envelope = [
            'artifact'     => 'semitexa.ai-invoke/v1',
            'generated_at' => gmdate('c'),
        ];

        $handlerClass = (string) ($input->getOption('handler') ?? '');
        $routePath    = (string) ($input->getOption('route') ?? '');
        $method       = strtoupper((string) ($input->getOption('method') ?? 'GET'));
        $payloadJson  = (string) ($input->getOption('payload') ?? '{}');

        if ($handlerClass === '' && $routePath === '') {
            return $this->emitError($output, $envelope, 'either --handler=<FQCN> or --route=<path> is required', 'input');
        }
        if ($handlerClass !== '' && $routePath !== '') {
            return $this->emitError($output, $envelope, '--handler and --route are mutually exclusive', 'input');
        }

        $decoded = json_decode($payloadJson, true);
        if (!is_array($decoded)) {
            return $this->emitError($output, $envelope, "--payload must be a JSON object. Got: " . substr($payloadJson, 0, 80), 'input');
        }

        // Resolve (handlerClass, payloadClass, resourceClass)
        try {
            [$handlerClass, $payloadClass, $resourceClass] = $this->resolveTarget($handlerClass, $routePath, $method);
        } catch (\Throwable $e) {
            return $this->emitError($output, $envelope, $e->getMessage(), 'resolve');
        }

        // Instantiate payload + hydrate
        $payload = null;
        try {
            $payload = $this->instantiate($payloadClass);
            $payload = PayloadSerializer::hydrate($payload, $decoded);
        } catch (\Throwable $e) {
            return $this->emitError($output, $envelope, "failed to hydrate Payload ({$payloadClass}): " . $e->getMessage(), 'hydrate');
        }

        // Instantiate resource
        $resource = null;
        try {
            $resource = $this->instantiate($resourceClass);
        } catch (\Throwable $e) {
            return $this->emitError($output, $envelope, "failed to instantiate Resource ({$resourceClass}): " . $e->getMessage() . ' — resources with required ctor args are not supported yet.', 'resource_init');
        }

        // Resolve handler from a minimally-primed request-scoped container.
        // Handlers are execution-scoped, so they need Request + Session + CookieJar
        // populated before resolution. We provide in-memory stubs — no real HTTP.
        $handler = null;
        try {
            $container = ContainerFactory::createRequestScoped();
            $this->primeRequestContext($container, $routePath !== '' ? $routePath : '/_invoke', $method, $decoded);
            $handler = $container->get($handlerClass);
        } catch (\Throwable $e) {
            return $this->emitError($output, $envelope, "container could not resolve handler {$handlerClass}: " . $e->getMessage(), 'resolve_handler');
        }

        // Invoke handle()
        $start = microtime(true);
        $result = null;
        $handlerError = null;
        try {
            if (!method_exists($handler, 'handle')) {
                throw new \RuntimeException('handler has no handle() method');
            }
            $result = $handler->handle($payload, $resource);
        } catch (\Throwable $e) {
            $handlerError = [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => array_slice(explode("\n", $e->getTraceAsString()), 0, 6),
            ];
        }
        $durationMs = round((microtime(true) - $start) * 1000, 2);

        $envelope['target'] = [
            'handler_class'  => $handlerClass,
            'payload_class'  => $payloadClass,
            'resource_class' => $resourceClass,
            'route_path'     => $routePath !== '' ? $routePath : null,
            'method'         => $routePath !== '' ? $method : null,
        ];
        $envelope['payload_input'] = $decoded;
        $envelope['duration_ms']   = $durationMs;

        if ($handlerError !== null) {
            $envelope['verdict']       = 'handler_threw';
            $envelope['handler_error'] = $handlerError;
            $envelope['resource']      = null;
        } elseif ($result === null) {
            $envelope['verdict']  = 'null_result';
            $envelope['resource'] = null;
        } else {
            $envelope['verdict']        = 'ok';
            $envelope['resource_class'] = get_class($result);
            try {
                $envelope['resource'] = PayloadSerializer::toArray($result);
            } catch (\Throwable $e) {
                $envelope['resource']             = null;
                $envelope['resource_serialize_error'] = $e->getMessage();
            }
        }

        $envelope['next_command'] = $this->buildNextCommands($envelope);

        $forceJson  = (bool) $input->getOption('json');
        $forceHuman = (bool) $input->getOption('human');
        $useHuman   = $forceHuman || (!$forceJson && $input->isInteractive());

        if ($useHuman) {
            $this->renderHuman(new SymfonyStyle($input, $output), $envelope);
        } else {
            $output->writeln(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        }

        return $handlerError !== null ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string, 2: string} [handlerClass, payloadClass, resourceClass]
     */
    private function resolveTarget(string $handlerClass, string $routePath, string $method): array
    {
        if ($routePath !== '') {
            $this->attributeDiscovery->initialize();
            $route = $this->attributeDiscovery->findRoute($routePath, $method);
            if ($route === null) {
                // Fallback: tenant-scope resolver filters out module-scoped
                // routes when no tenant context is active (common in CLI).
                // Linear-scan the enriched set so agents can invoke module
                // routes without wiring up a tenant first.
                foreach ($this->attributeDiscovery->getEnrichedRoutes() as $candidate) {
                    $candidatePath = $candidate['path'] ?? '';
                    $candidateMethods = $candidate['methods'] ?? [$candidate['method'] ?? 'GET'];
                    $candidateMethodsUpper = array_map('strtoupper', $candidateMethods);
                    if ($candidatePath === $routePath && in_array($method, $candidateMethodsUpper, true)) {
                        $route = $candidate;
                        break;
                    }
                }
            }
            if ($route === null) {
                throw new \RuntimeException("route not found: {$method} {$routePath}");
            }
            $handlers = $route['handlers'] ?? [];
            if ($handlers === []) {
                throw new \RuntimeException("route has no payload handler: {$method} {$routePath}");
            }
            $handlerClass = (string) ($handlers[0]['class'] ?? '');
            $payloadClass = (string) ($route['class'] ?? '');
            $resourceClass = (string) ($route['responseClass'] ?? '');
            if ($handlerClass === '' || $payloadClass === '' || $resourceClass === '') {
                throw new \RuntimeException("route {$method} {$routePath} is incompletely wired (handler/payload/resource missing)");
            }
            return [$handlerClass, $payloadClass, $resourceClass];
        }

        if (!class_exists($handlerClass)) {
            throw new \RuntimeException("handler class not found: {$handlerClass}");
        }

        $ref = new \ReflectionClass($handlerClass);
        $attr = $ref->getAttributes(AsPayloadHandler::class)[0] ?? null;
        if ($attr === null) {
            throw new \RuntimeException("handler {$handlerClass} has no #[AsPayloadHandler] attribute — not a payload handler");
        }
        $instance = $attr->newInstance();

        return [$handlerClass, $instance->payload, $instance->resource];
    }

    /**
     * @param array<string, mixed> $payloadData
     */
    private function primeRequestContext(
        \Semitexa\Core\Container\RequestScopedContainer $container,
        string $path,
        string $method,
        array $payloadData,
    ): void {
        $uri = $path;
        $query = [];
        $post = [];
        $bag = [];
        foreach ($payloadData as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            if (is_scalar($v)) {
                $bag[$k] = (string) $v;
            } elseif (is_array($v)) {
                $bag[$k] = $v;
            } else {
                $bag[$k] = (string) (json_encode($v) ?: '');
            }
        }
        if ($method === 'GET' || $method === 'HEAD') {
            $query = $bag;
        } else {
            $post = $bag;
        }
        $request = new Request(
            method:  $method,
            uri:     $uri,
            headers: ['host' => 'invoke.local', 'user-agent' => 'semitexa-ai-invoke/1'],
            query:   $query,
            post:    $post,
            server:  ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri],
            cookies: [],
            content: json_encode($payloadData) ?: null,
        );

        $session = new Session(
            id:              'ai-invoke-' . bin2hex(random_bytes(4)),
            handler:         new class() implements SessionHandlerInterface {
                /** @var array<string, array<string, mixed>> */
                private array $store = [];
                public function read(string $sessionId): array { return $this->store[$sessionId] ?? []; }
                public function write(string $sessionId, array $data, int $lifetimeSeconds = 3600): void { $this->store[$sessionId] = $data; }
                public function destroy(string $sessionId): void { unset($this->store[$sessionId]); }
            },
            cookieName:      'SEMITEXA_INVOKE',
            lifetimeSeconds: 3600,
        );

        $cookieJar = new CookieJar($request);

        $container->set(Request::class,             $request);
        $container->set(SessionInterface::class,    $session);
        $container->set(CookieJarInterface::class,  $cookieJar);
    }

    private function instantiate(string $class): object
    {
        if (!class_exists($class)) {
            throw new \RuntimeException("class not found: {$class}");
        }
        $ref = new \ReflectionClass($class);
        $ctor = $ref->getConstructor();
        if ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0) {
            return new $class();
        }
        // readonly ctor with required params — instantiate without constructor so
        // setters/hydration can populate public properties.
        return $ref->newInstanceWithoutConstructor();
    }

    /**
     * @param array<string, mixed> $envelope
     * @return list<array{cmd: string, args: list<string>, why: string}>
     */
    private function buildNextCommands(array $envelope): array
    {
        $out = [];
        $verdict = $envelope['verdict'] ?? 'ok';
        $target = $envelope['target'] ?? [];
        $handler = $target['handler_class'] ?? null;

        if ($verdict === 'handler_threw') {
            $out[] = ['cmd' => 'logs:app', 'args' => ['--grep=error', '--lines=100', '--level=ERROR', '--json'], 'why' => 'recent runtime errors'];
            if ($handler !== null) {
                $out[] = ['cmd' => 'ai:review-graph:impact', 'args' => [$handler, '--json'], 'why' => 'see what the failing handler touches'];
            }
            return $out;
        }

        if ($verdict === 'ok' && $handler !== null) {
            $out[] = ['cmd' => 'ai:verify', 'args' => ['--json'], 'why' => 'verify the diff that produced this behaviour'];
            $out[] = ['cmd' => 'ai:review-graph:impact', 'args' => [$handler, '--json'], 'why' => 'confirm blast radius before committing'];
        }

        return $out;
    }

    private function emitError(OutputInterface $output, array $envelope, string $message, string $stage): int
    {
        $envelope['verdict'] = 'error';
        $envelope['stage']   = $stage;
        $envelope['error']   = $message;
        $output->writeln(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        return self::FAILURE;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function renderHuman(SymfonyStyle $io, array $envelope): void
    {
        $io->title('ai:invoke');
        $target = $envelope['target'] ?? [];
        $io->definitionList(
            ['Handler'  => $target['handler_class'] ?? '-'],
            ['Payload'  => $target['payload_class'] ?? '-'],
            ['Resource' => $target['resource_class'] ?? '-'],
            ['Route'    => $target['route_path'] !== null ? ($target['method'] . ' ' . $target['route_path']) : '(direct)'],
            ['Duration' => ($envelope['duration_ms'] ?? 0) . ' ms'],
            ['Verdict'  => $envelope['verdict'] ?? '-'],
        );

        if (isset($envelope['handler_error'])) {
            $io->section('Handler error');
            $err = $envelope['handler_error'];
            $io->text(($err['class'] ?? 'Error') . ': ' . ($err['message'] ?? ''));
            if (isset($err['file'], $err['line'])) {
                $io->text("  at {$err['file']}:{$err['line']}");
            }
            foreach (($err['trace'] ?? []) as $line) {
                $io->text('  ' . $line);
            }
        } elseif (isset($envelope['resource'])) {
            $io->section('Resource (' . ($envelope['resource_class'] ?? '?') . ')');
            $io->writeln(json_encode($envelope['resource'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: 'null');
        }

        if (!empty($envelope['next_command'])) {
            $io->section('Next');
            foreach ($envelope['next_command'] as $nc) {
                $io->writeln("  → bin/semitexa {$nc['cmd']} " . implode(' ', $nc['args']) . "   # {$nc['why']}");
            }
        }
    }
}

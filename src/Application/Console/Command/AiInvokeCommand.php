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
use Semitexa\Dev\Application\Service\Invoke\FieldExpectations;
use Semitexa\Dev\Application\Service\Invoke\InvocationContract;
use Semitexa\Dev\Application\Service\Trace\ContextRedactor;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run a payload handler without standing up the HTTP layer.
 *
 * NOT a dry run: `handle()` is called for real, so whatever the handler writes,
 * sends or charges, it writes, sends and charges. Only the pipeline in FRONT of
 * it is skipped. `--preview` is the non-executing mode, and execution is
 * refused outside dev — see {@see InvocationContract} for both, and for the
 * omissions this command reports next to every result.
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
    description: 'Run a payload handler from CLI, skipping HTTP/auth/middleware — dev only. Use --preview to resolve the target without executing.',
)]
final class AiInvokeCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    /** The payload exactly as the caller wrote it, for the follow-up commands. */
    private ?string $invokedPayloadJson = null;

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
            ->addOption('human', null, InputOption::VALUE_NONE, 'Force human-readable output')
            ->addOption('expect-field', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Assert path=value against the returned resource (dot path, repeatable). Compared as text; the run fails if any expectation does not hold.')
            ->addOption('preview', null, InputOption::VALUE_NONE, 'Resolve the target and report what would run, without calling handle(). Works outside dev.');
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
        $this->invokedPayloadJson = $payloadJson;

        if ($handlerClass === '' && $routePath === '') {
            return $this->emitError($output, $envelope, 'either --handler=<FQCN> or --route=<path> is required', 'input');
        }
        if ($handlerClass !== '' && $routePath !== '') {
            return $this->emitError($output, $envelope, '--handler and --route are mutually exclusive', 'input');
        }

        try {
            $expectations = FieldExpectations::fromOptions(array_map(strval(...), (array) $input->getOption('expect-field')));
        } catch (\InvalidArgumentException $e) {
            return $this->emitError($output, $envelope, $e->getMessage(), 'input');
        }
        $rawResource = null;

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

        $envelope['target'] = [
            'handler_class'  => $handlerClass,
            'payload_class'  => $payloadClass,
            'resource_class' => $resourceClass,
            'route_path'     => $routePath !== '' ? $routePath : null,
            'method'         => $routePath !== '' ? $method : null,
        ];
        // Carried whether or not anything runs: a reader of this envelope has
        // to be able to tell what was skipped without knowing the command.
        $envelope['omitted'] = InvocationContract::omissions();

        if ((bool) $input->getOption('preview')) {
            $envelope['verdict'] = 'preview';
            $envelope['note'] = 'Nothing was executed. Drop --preview to run the handler.';
            $envelope['payload_input'] = ContextRedactor::redact($decoded);

            return $this->emitEnvelope($input, $output, $envelope, self::SUCCESS);
        }

        if (!InvocationContract::executionAllowed()) {
            $envelope['verdict'] = 'refused';
            $envelope['reason'] = InvocationContract::refusalReason();

            return $this->emitEnvelope($input, $output, $envelope, self::FAILURE);
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

        // Input and result go through the same gate the Observatory uses. This
        // envelope is printed, piped and pasted into chats like any other, and
        // a handler's input is exactly where a token or a password arrives.
        //
        // The gate also BOUNDS what it passes — depth, item count, string
        // length — so what comes out may differ from what went in for reasons
        // that are not secrecy. Said plainly, because a reader comparing this
        // against the real thing should not have to guess which.
        $envelope['payload_input'] = ContextRedactor::redact($decoded);
        $envelope['redacted'] = 'payload_input and resource pass through the Observatory redactor: '
            . 'values under secret-looking keys are masked, and deep or long ones are bounded';
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
                // Kept raw for the expectations below and redacted for the
                // envelope: asserting a field under a secret-looking key must
                // compare the real value, not the mask it is printed as.
                $rawResource = PayloadSerializer::toArray($result);
                $envelope['resource'] = ContextRedactor::redact($rawResource);
            } catch (\Throwable $e) {
                // The handler ran, but the caller has no result to look at.
                // Reporting success here hands back an empty resource that
                // reads as "the handler returned nothing".
                $envelope['verdict']                  = 'resource_unserializable';
                $envelope['resource']                 = null;
                $envelope['resource_serialize_error'] = $e->getMessage();
            }
        }

        $expectationsFailed = false;
        if (!$expectations->isEmpty()) {
            $report = $expectations->check($rawResource ?? null);
            $envelope['expectations'] = $report;
            $expectationsFailed = $report['failed'] > 0;

            // The compact answer this flag exists for: one word a caller can
            // read without decoding the resource.
            if ($expectationsFailed && $envelope['verdict'] === 'ok') {
                $envelope['verdict'] = 'expectation_failed';
            }
        }

        $envelope['next_command'] = $this->buildNextCommands($envelope);

        $failed = $handlerError !== null
            || $envelope['verdict'] === 'resource_unserializable'
            || $expectationsFailed;

        return $this->emitEnvelope($input, $output, $envelope, $failed ? self::FAILURE : self::SUCCESS);
    }

    /**
     * One exit from this command, so every verdict is rendered the same way and
     * the exit code is decided next to the verdict that produced it.
     *
     * @param array<string, mixed> $envelope
     */
    private function emitEnvelope(InputInterface $input, OutputInterface $output, array $envelope, int $exitCode): int
    {
        // Early returns (preview, refused) reach here without having built
        // their hints; the execute path has already set them.
        $envelope['next_command'] ??= $this->buildNextCommands($envelope);

        $forceJson  = (bool) $input->getOption('json');
        $forceHuman = (bool) $input->getOption('human');
        $useHuman   = $forceHuman || (!$forceJson && $input->isInteractive());

        if ($useHuman) {
            $this->renderHuman(new SymfonyStyle($input, $output), $envelope);
        } else {
            $output->writeln(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        }

        return $exitCode;
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
    /**
     * How the target was addressed, in the form the next command needs.
     *
     * @param array<string, mixed> $target
     * @return list<string>
     */
    private function targetArgs(array $target): array
    {
        $route = is_string($target['route_path'] ?? null) ? $target['route_path'] : null;
        $method = is_string($target['method'] ?? null) ? $target['method'] : null;
        $handler = is_string($target['handler_class'] ?? null) ? $target['handler_class'] : null;

        if ($route !== null) {
            $args = ['--route=' . $route];
            if ($method !== null && $method !== 'GET') {
                $args[] = '--method=' . $method;
            }

            return $args;
        }

        return $handler !== null ? ['--handler=' . $handler] : [];
    }

    /**
     * The caller's payload, repeated only when repeating it gives nothing away.
     *
     * A suggested command is part of the envelope, and the envelope is printed,
     * piped and pasted around — so the same gate that masks `payload_input`
     * decides this. If the redactor would change ANY of it, the argument is
     * left out entirely and {@see payloadHint()} says why: the caller still has
     * what they typed, and this output does not become the copy of it that the
     * masking was there to prevent.
     *
     * @return list<string>
     */
    private function payloadArg(): array
    {
        return $this->payloadIsSafeToRepeat() ? ['--payload=' . $this->invokedPayloadJson] : [];
    }

    private function payloadHint(): string
    {
        return $this->payloadIsSafeToRepeat()
            ? ''
            : ' — pass your own --payload again, it is not repeated here because the redactor masks part of it';
    }

    private function payloadIsSafeToRepeat(): bool
    {
        if ($this->invokedPayloadJson === null || $this->invokedPayloadJson === '{}') {
            return false;
        }

        $decoded = json_decode($this->invokedPayloadJson, true);

        return is_array($decoded) && ContextRedactor::redact($decoded) === $decoded;
    }

    /**
     * @param array<string, mixed> $envelope
     * @return list<array{cmd: string, args: list<string>, why: string}>
     */
    private function buildNextCommands(array $envelope): array
    {
        $out = [];
        $verdict = $envelope['verdict'] ?? 'ok';
        /** @var array<string, mixed> $target */
        $target = is_array($envelope['target'] ?? null) ? $envelope['target'] : [];
        $handler = is_string($target['handler_class'] ?? null) ? $target['handler_class'] : null;

        // Every ai:invoke suggestion has to carry its target, or the command it
        // names fails on the required-target check before it does anything. The
        // payload is carried from what the caller actually passed, not from the
        // redacted copy in the envelope — a suggestion that runs a different
        // input than the one being discussed is worse than none.
        $targetArgs = $this->targetArgs($target);

        if ($verdict === 'refused') {
            $out[] = [
                'cmd' => 'ai:invoke',
                'args' => [...$targetArgs, ...$this->payloadArg(), '--preview', '--json'],
                'why' => 'resolve the target and see what would run, without executing it' . $this->payloadHint(),
            ];
            return $out;
        }

        if ($verdict === 'preview') {
            $out[] = [
                'cmd' => 'ai:invoke',
                'args' => [...$targetArgs, ...$this->payloadArg(), '--json'],
                'why' => 'run it for real (dev only) once the target looks right' . $this->payloadHint(),
            ];
            return $out;
        }

        if ($verdict === 'resource_unserializable') {
            $out[] = ['cmd' => 'ai:verify', 'args' => ['--json'], 'why' => 'the handler ran but its resource could not be serialized — check the resource DTO'];
            return $out;
        }

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

        $expectations = is_array($envelope['expectations'] ?? null) ? $envelope['expectations'] : null;
        if ($expectations !== null) {
            // Compact on purpose: somebody who passed --expect-field asked a
            // yes/no question, and the resource dump is not the answer.
            $io->section(sprintf(
                'Expectations — %d of %d held',
                (int) $expectations['checked'] - (int) $expectations['failed'],
                (int) $expectations['checked'],
            ));

            foreach (is_array($expectations['results'] ?? null) ? $expectations['results'] : [] as $result) {
                if (!is_array($result)) {
                    continue;
                }

                $path = is_string($result['path'] ?? null) ? $result['path'] : '?';
                $expected = is_string($result['expected'] ?? null) ? $result['expected'] : '';
                $actual = is_string($result['actual'] ?? null) ? $result['actual'] : '(absent)';

                if (($result['ok'] ?? false) === true) {
                    $io->writeln("  <info>ok</info>   {$path} = {$expected}");
                    continue;
                }

                $note = is_string($result['reason'] ?? null) ? ' — ' . $result['reason'] : '';
                $io->writeln("  <error>fail</error> {$path}: expected {$expected}, got {$actual}{$note}");
                if (($result['actual_redacted'] ?? false) === true) {
                    $io->writeln('       (shown masked; the comparison used the real value)');
                }
            }
        }

        $next = is_array($envelope['next_command'] ?? null) ? $envelope['next_command'] : [];
        if ($next !== []) {
            $io->section('Next');
            foreach ($next as $nc) {
                if (!is_array($nc)) {
                    continue;
                }

                $args = [];
                foreach (is_array($nc['args'] ?? null) ? $nc['args'] : [] as $arg) {
                    if (is_string($arg)) {
                        $args[] = self::shellArg($arg);
                    }
                }

                $cmd = is_string($nc['cmd'] ?? null) ? $nc['cmd'] : '';
                $why = is_string($nc['why'] ?? null) ? $nc['why'] : '';
                $io->writeln("  → bin/semitexa {$cmd} " . implode(' ', $args) . "   # {$why}");
            }
        }
    }

    /**
     * One argument, as a shell would have to be given it.
     *
     * This line is printed to be copied and run. A payload is JSON, so it
     * carries quotes and spaces as a matter of course —
     * `--payload={"title":"a thing"}` pasted raw loses its quotes and splits
     * into two arguments — and a value carrying `$(...)` or a backtick would be
     * run by the shell rather than passed to the command. The JSON envelope is
     * unaffected: it holds the arguments themselves, and a reader that executes
     * them does not go through a shell. Raised in review of dev#83.
     */
    private static function shellArg(string $arg): string
    {
        // Left alone when there is nothing a shell would do to it — the common
        // case, and quoting it would only make the line harder to read.
        if ($arg !== '' && preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $arg) === 1) {
            return $arg;
        }

        return escapeshellarg($arg);
    }
}

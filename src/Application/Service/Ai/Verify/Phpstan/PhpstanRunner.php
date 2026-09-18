<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Phpstan;

use Semitexa\Dev\Application\Service\Ai\Verify\ProcessRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\ShellProcessRunner;

/**
 * Runs PHPStan against a list of changed files using the focused
 * `packages/semitexa-dev/config/phpstan-ai-verify.neon` configuration that
 * loads only the Semitexa-owned DI rules.
 *
 * `ai:verify` does not implement DI rules itself — they live as PHPStan rules
 * in `packages/semitexa-core/src/PHPStan/Rules/` and are the project's single
 * source of truth (the same rules run via `composer phpstan`). This runner is
 * a thin orchestration shim: it invokes PHPStan and decodes its JSON output
 * so the wider `ai:verify` machinery can re-emit each diagnostic as an NDJSON
 * `violation` event carrying the original PHPStan identifier verbatim
 * (`semitexa.injectionViaConstructor`, `semitexa.staticContainerAccess`, …).
 *
 * Determinism: the runner shells out via {@see ProcessRunner}; given the same
 * inputs and PHPStan vendor version the output is reproducible.
 */
final class PhpstanRunner
{
    public const CONFIG_REL_PATH = 'packages/semitexa-dev/config/phpstan-ai-verify.neon';

    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $processRunner = new ShellProcessRunner(),
        private readonly ?string $phpstanBinary = null,
        private readonly ?string $configPath = null,
    ) {}

    /**
     * @param list<string> $relativePaths repo-relative paths to analyse; non-PHP entries are ignored by PHPStan itself
     * @return PhpstanRunResult
     */
    public function run(array $relativePaths): PhpstanRunResult
    {
        if ($relativePaths === []) {
            return new PhpstanRunResult(
                status: PhpstanRunResult::STATUS_PASS,
                diagnostics: [],
                rawSignal: 'no files to analyse',
            );
        }

        $binary = $this->phpstanBinary ?? $this->discoverPhpstanBinary();
        if ($binary === null) {
            return new PhpstanRunResult(
                status: PhpstanRunResult::STATUS_ERROR,
                diagnostics: [],
                rawSignal: 'phpstan binary not found (looked for vendor/bin/phpstan)',
                exitCode: 1,
            );
        }

        $config = $this->configPath ?? $this->discoverConfig();
        if (!is_file($config)) {
            return new PhpstanRunResult(
                status: PhpstanRunResult::STATUS_ERROR,
                diagnostics: [],
                rawSignal: "phpstan-ai-verify config missing: {$config}",
                exitCode: 1,
            );
        }

        $command = [
            $binary,
            'analyse',
            '--no-progress',
            '--memory-limit=512M',
            '--error-format=json',
            '-c',
            $config,
            ...$relativePaths,
        ];

        try {
            $result = $this->processRunner->run($command, $this->projectRoot);
        } catch (\Throwable $e) {
            return $this->error('phpstan process failed: ' . $this->compress($e->getMessage()));
        }

        $payload = $this->decode($result['output']);
        if ($payload === null) {
            return $this->error('phpstan output was not valid JSON: ' . $this->compress($result['output']), $result['exit']);
        }
        if (!$this->validPayload($payload)) {
            return $this->error('phpstan output has an invalid or inconsistent result schema', $result['exit']);
        }

        $diagnostics = $this->extractDiagnostics($payload);

        // Diagnostics and process success are independent evidence. A crash
        // that printed an empty object (or even a clean result) is not a pass.
        if (!in_array($result['exit'], [0, 1], true)
            || ($result['exit'] === 0 && $diagnostics !== [])
            || ($result['exit'] === 1 && $diagnostics === [])
            || $payload['errors'] !== []) {
            return $this->error('phpstan analysis incomplete or exit/result mismatch (exit ' . $result['exit'] . ')', $result['exit'], $diagnostics);
        }

        // Split AFTER the exit/diagnostic cross-check above: that check is
        // about whether PHPStan itself behaved, and must see what it actually
        // reported.
        [$unresolved, $accepted] = $this->partitionAccepted($diagnostics);

        $status = $unresolved === []
            ? PhpstanRunResult::STATUS_PASS
            : PhpstanRunResult::STATUS_FAIL;

        $signal = $unresolved === []
            ? 'phpstan_di → 0 violations'
            : sprintf(
                'phpstan_di → %d violation(s); first: %s %s',
                count($unresolved),
                $unresolved[0]['identifier'] ?? 'phpstan.error',
                $unresolved[0]['path'] ?? '?',
            );

        if ($accepted !== []) {
            // Named, never hidden. The point of the registry is that a reader
            // learns both that the rule fired and that somebody already decided
            // about it — the opposite of a baseline.
            $signal .= sprintf(' (%d accepted: %s)', count($accepted), $accepted[0]['path'] ?? '?');
        }

        // Accepted entries are reported BESIDE the diagnostics, never among
        // them. They travel to the envelope as `violations`, and a consumer
        // that gates on "violations is empty" would go red on a green run —
        // the signal line is where a reader learns the rule fired and was
        // excused.
        return new PhpstanRunResult(
            status: $status,
            diagnostics: $unresolved,
            rawSignal: $signal,
            exitCode: $result['exit'],
            accepted: $accepted,
        );
    }

    /**
     * Separate the violations this project has already decided about from the
     * ones it has not.
     *
     * An accepted diagnostic keeps everything it had and gains the decision:
     * severity drops to `accepted` and the reason travels with it, so the
     * envelope can say "the rule fired here, and here is why that is allowed"
     * instead of either failing or going quiet.
     *
     * The allowance is counted. Accepting one occurrence in a file does not
     * accept a second that shows up later — the extra ones stay unresolved.
     *
     * @param list<array<string, mixed>> $diagnostics
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} [unresolved, accepted]
     */
    private function partitionAccepted(array $diagnostics): array
    {
        $unresolved = [];
        $accepted = [];
        $used = [];

        foreach ($diagnostics as $diagnostic) {
            $path = (string) ($diagnostic['path'] ?? '');
            $rule = (string) ($diagnostic['identifier'] ?? '');
            // Canonical, because allowanceFor() is: keyed by the raw spelling,
            // the workspace and vendor paths for ONE file each consume the
            // allowance separately and two diagnostics slip through as one.
            $key = AcceptedViolations::canonicalise($path) . "\0" . $rule;
            $allowance = AcceptedViolations::allowanceFor($path, $rule);

            if ($allowance === 0 || ($used[$key] ?? 0) >= $allowance) {
                $unresolved[] = $diagnostic;
                continue;
            }

            // And at the site that was accepted, not merely somewhere in that
            // file. Remove the blessed call, write a different one elsewhere in
            // the same class, and the file-and-rule key consumed the allowance
            // for it — green, with somebody else's reason attached, while the
            // textual ratchet saw an unchanged count either way. Raised in
            // review of dev#83.
            $site = AcceptedViolations::siteFor($path, $rule);
            if ($site !== null && !$this->isAtSite($path, $diagnostic, $site)) {
                $unresolved[] = $diagnostic;
                continue;
            }

            $used[$key] = ($used[$key] ?? 0) + 1;
            $diagnostic['severity'] = 'accepted';
            $diagnostic['accepted_reason'] = AcceptedViolations::reasonFor($path, $rule);
            $accepted[] = $diagnostic;
        }

        return [$unresolved, $accepted];
    }

    /**
     * Is this diagnostic in the method the entry accepted?
     *
     * A diagnostic with no usable line cannot be placed, and neither can one in
     * a file this process cannot read — a consumer install analysing a path
     * that is not on disk here, say. Both answer NO: an allowance is a
     * statement about one place, and a violation that cannot be shown to be in
     * that place is reported rather than absorbed.
     *
     * @param array<string, mixed> $diagnostic
     */
    private function isAtSite(string $path, array $diagnostic, string $site): bool
    {
        $line = (int) ($diagnostic['line'] ?? 0);
        if ($line <= 0) {
            return false;
        }

        $absolute = str_starts_with($path, '/') ? $path : $this->projectRoot . '/' . $path;

        return EnclosingSymbol::at($absolute, $line) === $site;
    }

    /** @param list<array<string, mixed>> $diagnostics */
    private function error(string $signal, int $exit = 1, array $diagnostics = []): PhpstanRunResult
    {
        return new PhpstanRunResult(PhpstanRunResult::STATUS_ERROR, $diagnostics, $signal, $exit === 0 ? 1 : $exit);
    }

    /** @param array<string, mixed> $payload */
    private function validPayload(array $payload): bool
    {
        $totals = $payload['totals'] ?? null;
        $files = $payload['files'] ?? null;
        $errors = $payload['errors'] ?? null;
        if (!is_array($totals) || !is_array($files) || !is_array($errors) || !array_is_list($errors)
            || !is_int($totals['errors'] ?? null) || !is_int($totals['file_errors'] ?? null)
            || $totals['errors'] !== count($errors) || $totals['file_errors'] < 0) {
            return false;
        }
        foreach ($errors as $error) {
            if (!is_string($error) || $error === '') {
                return false;
            }
        }
        $messageCount = 0;
        foreach ($files as $path => $file) {
            if (!is_string($path) || $path === '' || !is_array($file)
                || !is_array($file['messages'] ?? null) || !array_is_list($file['messages'])
                || !is_int($file['errors'] ?? null) || $file['errors'] !== count($file['messages'])) {
                return false;
            }
            foreach ($file['messages'] as $message) {
                if (!is_array($message) || !is_string($message['message'] ?? null) || $message['message'] === '') {
                    return false;
                }
            }
            $messageCount += count($file['messages']);
        }
        return $messageCount === $totals['file_errors'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function extractDiagnostics(array $payload): array
    {
        $rows = [];
        $files = $payload['files'] ?? [];
        if (is_array($files)) {
            foreach ($files as $absPath => $info) {
                if (!is_array($info)) {
                    continue;
                }
                $messages = $info['messages'] ?? [];
                if (!is_array($messages)) {
                    continue;
                }
                $rel = $this->relativisePath((string) $absPath);
                foreach ($messages as $msg) {
                    if (!is_array($msg)) {
                        continue;
                    }
                    $rawIdentifier = isset($msg['identifier']) && is_string($msg['identifier']) && $msg['identifier'] !== ''
                        ? $msg['identifier']
                        : 'phpstan.error';
                    $messageText = isset($msg['message']) && is_string($msg['message']) ? $msg['message'] : '';
                    $identifier  = $this->namespaceBrokenFqcn($rawIdentifier, $messageText);
                    $rows[] = [
                        'check'         => 'phpstan_di',
                        'severity'      => 'error',
                        'rule'          => $identifier,
                        'identifier'    => $identifier,
                        'path'          => $rel,
                        'line'          => (int) ($msg['line'] ?? 0),
                        'message'       => $messageText,
                        'tip'           => isset($msg['tip']) && is_string($msg['tip']) ? $msg['tip'] : '',
                        'doc_ref'       => 'packages/semitexa-docs/docs/AI_BEST_PRACTICES.md',
                        'suggested_fix' => $this->suggestionFor($identifier),
                    ];
                }
            }
        }
        // Top-level (path-less) errors — usually configuration / discovery issues.
        $errors = $payload['errors'] ?? [];
        if (is_array($errors)) {
            foreach ($errors as $err) {
                if (!is_string($err) || $err === '') {
                    continue;
                }
                $rows[] = [
                    'check'         => 'phpstan_di',
                    'severity'      => 'error',
                    'rule'          => 'phpstan.error',
                    'identifier'    => 'phpstan.error',
                    'path'          => '',
                    'line'          => 0,
                    'message'       => $err,
                    'tip'           => '',
                    'doc_ref'       => 'packages/semitexa-docs/docs/AI_BEST_PRACTICES.md',
                    'suggested_fix' => 'Resolve the PHPStan configuration issue and re-run ai:verify.',
                ];
            }
        }
        return $rows;
    }

    private function suggestionFor(string $identifier): string
    {
        return match ($identifier) {
            'semitexa.inertConstructorBody'      => 'Move the constructor body into initialize() and implement Semitexa\\Core\\Contract\\InitializesAfterInjectionInterface, which the container calls once every injected property is populated. The container builds container-managed classes with newInstanceWithoutConstructor(), so this code never runs — it is not merely poor style, it is dead. An empty constructor is fine, and a #[AsCommand] class is exempt: Application::instantiateCommand() builds it with plain new, so its constructor does run.',
            'semitexa.unquotedSqlIdentifier'     => 'Take the identifier quotes OUT of the SQL literal and pass the value through Semitexa\\Orm\\Adapter\\SqlIdentifier::quote() — or quoteAll() for a list, quoteQualified() for alias.column. Writing `%s` in the format string wraps the name without escaping it: a backtick inside the name closes the quote and everything after it is parsed as SQL. Pass SqlIdentifier::DOUBLE_QUOTE as the second argument on a path that emits ANSI quotes (SyncEngine\'s SQLite branch). If the value is genuinely a SQL fragment rather than a name, it does not belong in an identifier position at all.',
            'semitexa.staticFacadeAccess'        => 'Inject the service via a protected #[InjectAsReadonly] property instead of calling the retired static facade. The static surface still exists, but only for wiring listeners and the static contexts already on the rule\'s allowlist; calling it from anywhere else is what makes the service impossible to substitute in a test.',
            'semitexa.workerServiceConnectionHandle' => 'Do not hold a live connection handle on a #[AsService] property. One instance is shared across every concurrent request coroutine, so a second coroutine can run on that handle mid-statement and corrupt overlapping transactions. Take a connection from the ConnectionPool per operation, keep it in CoroutineLocal if it must span calls within one request, or mark the class #[ExecutionScoped] only if it is genuinely cloned per request.',
            'semitexa.injectionViaConstructor'   => 'Replace the constructor parameter with a #[InjectAsReadonly] (or #[InjectAsMutable] / #[InjectAsFactory]) protected property and reduce the constructor to either parameterless or a parent::__construct() call.',
            'semitexa.staticContainerAccess'     => 'Drop the ContainerFactory:: call. Declare each required collaborator as a protected #[InjectAsReadonly] property typed against the concrete service contract.',
            'semitexa.injectedPropertyVisibility' => 'Make the injected property `protected`. Public/private visibility is forbidden on #[InjectAs*] / #[Config] properties.',
            'semitexa.injectOnScalarType'        => 'Use #[Config] for scalar configuration values. #[InjectAs*] applies only to class types.',
            'semitexa.configOnArrayType',
            'semitexa.configOnClassType'         => 'Adjust the property type. #[Config] supports scalar (int, float, string, bool) and backed-enum types only.',
            'semitexa.unannotatedServiceProperty' => 'Add the appropriate #[InjectAsReadonly] / #[InjectAsMutable] / #[InjectAsFactory] attribute, or remove the property if it is not a service.',
            'semitexa.traitInjection'            => 'Move the injected property out of the trait. Container-managed classes must declare their injection points directly.',
            'semitexa.noOpMapper'                => 'Give the #[AsMapper] a distinct domainModel: create a dedicated domain (business) model class separate from the persistence resource model, point domainModel: at it, and map its fields explicitly inside toDomain() / toSourceModel() instead of cloning the resource model. resourceModel and domainModel must never be the same class.',
            'semitexa.mapperTypeConversion'      => 'Pass the field straight through in both directions and delete the conversion. The ORM owns COLUMN-TYPE conversion: TypeCaster turns BINARY(16) into a canonical uuid string on every read and back into 16 bytes on every write, so a mapper that converts it again is handed 36 characters and throws. A mapper owns the storage shapes the column type cannot express — a JSON string that becomes an array, an enum spread across columns — and that half stays. Binding a uuid into a raw WHERE is a different thing and belongs in the repository, where nothing hydrates it.',
            'semitexa.domainModelEncapsulation'  => 'Encapsulate the domain model: make every property private and expose it through accessors — a getX() (or isX()/hasX()) for each field, plus a setX() for mutable fields (mark the property readonly if it is immutable). Public/protected fields on a mapped domain model are forbidden; only ORM resource models keep public promoted properties.',
            // The eleven below ran only against orm/core/ssr/tenancy until the
            // rule list was unified on 2026-09-18. They can now fire on any
            // changed file, so each needs an answer rather than "fix it".
            'semitexa.explicitOptionalDependency' => 'Express the optional dependency as a composer requirement instead of a runtime class_exists(). If the package is genuinely optional, declare it in "suggest" and inject it as #[InjectAsReadonly(optional: true)] on a NON-nullable property with an isset-guarded accessor; the injector skips an optional it cannot resolve. A class_exists() check reads as "this may be absent" while telling composer nothing, so the absence is discovered at runtime by the consumer rather than at install time.',
            'semitexa.factoryStringKey'          => 'Key the factory on a class-string or a backed enum, not a free string. A string key cannot be checked by anything: a typo resolves to nothing at runtime, and no analyser can tell you which keys exist.',
            'semitexa.factoryContract'           => 'Have the factory return the declared contract rather than a concrete class. A factory that hands back a concrete type removes the reason it exists — the caller is now bound to the implementation it was supposed to be insulated from.',
            'semitexa.factoryClosedWorld'        => 'List every implementation the factory can produce, in the factory. An open-ended factory that resolves whatever it is handed cannot be checked, and a missing case becomes a runtime failure in a consumer rather than an analysis error here.',
            'semitexa.moduleContractOwnership'   => 'Move the contract into the module that owns it. A module may depend on another module\'s CONTRACT, never on its implementation, and a contract declared outside its owner leaves nobody responsible for its shape.',
            'semitexa.handlerReturnsResponse'    => 'Return the resource the handler declares, not a response. A #[AsPayloadHandler] states its resource class; building an HttpResponse inside the handler bypasses the renderer, so content negotiation, the page-document path and the shell envelope never run for that route.',
            'semitexa.directResponseConstruction' => 'Do not construct a response directly in application code. Return the declared resource and let ResponseRenderer decide the shape — it is what applies the route\'s produces list, the CSP nonce and the deferred-region manifest.',
            'semitexa.eventListenerMissingExecution' => 'Give the #[AsEventListener] an explicit execution mode: Sync, Async or Queued. Leaving it unstated means the dispatcher picks, and the difference between "before the response" and "after the worker picks it up" is exactly what the caller needs to know.',
            'semitexa.executionScopedWithoutAttribute' => 'Add #[ExecutionScoped] to the class, or stop holding per-request state on it. A container-managed service is ONE instance shared across every concurrent coroutine; a property that belongs to a request is read by another request without it.',
            'semitexa.discoveryCallSite'         => 'Do not call discovery from application code. Discovery runs once at boot and its result is cached for the worker\'s lifetime; calling it per request re-scans the classmap and is the stampede this rule exists to prevent.',
            'semitexa.disallowErrorLog'          => 'Use the framework logger instead of error_log(). error_log() writes outside the application\'s own log, so the entry is invisible to `ai:ask logs`, carries no channel and no context, and cannot be correlated with the request that produced it.',
            'semitexa.brokenFqcn'                => 'Vendor migration likely — the referenced FQCN no longer resolves. Check whether the class moved namespaces (e.g. `Foo\\Contract\\X` → `Foo\\Domain\\Contract\\X`, or attribute relocations from `Core\\Attribute\\` to the owning package). Update the `use` statement, inline FQCN, or typehint to the new canonical FQCN. If this is a recently-renamed contract, run `bin/semitexa ai:review-graph:generate` to refresh the impact graph.',
            default                              => 'Fix the issue reported by PHPStan.',
        };
    }

    /**
     * Remap PHPStan's native broken-class-reference identifiers (`class.notFound`,
     * `interface.notFound`, `phpstan.error` with a "class not found" message
     * shape, …) to a Semitexa-namespaced `semitexa.brokenFqcn`. Lets
     * {@see self::suggestionFor()} return guidance tuned to the time-bomb
     * scenario (vendor migration / DDD rename / contract move) instead of a
     * generic "fix the issue PHPStan reported" — better DX, better blame
     * surface in the NDJSON report. Original PHPStan identifier is captured
     * via `rule` for callers who want to drill into the raw diagnostic.
     */
    private function namespaceBrokenFqcn(string $rawIdentifier, string $message): string
    {
        $brokenIdentifiers = [
            'class.notFound'     => true,
            'interface.notFound' => true,
            'trait.notFound'     => true,
            'enum.notFound'      => true,
        ];
        if (isset($brokenIdentifiers[$rawIdentifier])) {
            return 'semitexa.brokenFqcn';
        }
        // Older PHPStan versions / some message shapes carry no identifier
        // and surface as `phpstan.error`. Pattern-match the canonical
        // missing-class phrasings so we still recognise the time-bomb shape.
        if ($rawIdentifier === 'phpstan.error' || $rawIdentifier === '') {
            // A bare ` not found.` matches unrelated diagnostics (e.g. "Constant X
            // not found."). Require a class-like keyword before "not found" so only
            // missing-symbol errors are remapped to the broken-FQCN time-bomb shape.
            if (preg_match('/\b(class|interface|trait|enum)\b.*\bnot found\b/i', $message) === 1) {
                return 'semitexa.brokenFqcn';
            }
            $needles = [
                'has unknown class ',
                'unknown class ',
                'unknown interface ',
                'has invalid type ',
                'has invalid return type ',
            ];
            foreach ($needles as $needle) {
                if (str_contains(strtolower($message), strtolower($needle))) {
                    return 'semitexa.brokenFqcn';
                }
            }
        }
        return $rawIdentifier;
    }

    private function discoverConfig(): string
    {
        $workspace = $this->projectRoot . '/' . self::CONFIG_REL_PATH;
        if (is_file($workspace)) {
            return $workspace;
        }
        // Resolve relative to the package itself: works with Composer vendor
        // installs and path repositories without requiring a packages/ tree.
        return dirname(__DIR__, 6) . '/config/phpstan-ai-verify-consumer.neon';
    }

    private function discoverPhpstanBinary(): ?string
    {
        foreach (['/vendor/bin/phpstan', '/bin/phpstan'] as $candidate) {
            $abs = $this->projectRoot . $candidate;
            if (is_file($abs) && is_executable($abs)) {
                return $abs;
            }
        }
        return null;
    }

    private function decode(string $output): ?array
    {
        $start = strpos($output, '{');
        if ($start === false) {
            return null;
        }
        $candidate = substr($output, $start);
        try {
            $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    private function relativisePath(string $absOrRel): string
    {
        $root = rtrim($this->projectRoot, '/') . '/';
        if (str_starts_with($absOrRel, $root)) {
            return substr($absOrRel, strlen($root));
        }
        return $absOrRel;
    }

    private function compress(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return strlen($value) > 240 ? substr($value, 0, 237) . '...' : $value;
    }
}

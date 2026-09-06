<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Request-scoped state must not live on anything the worker keeps.
 *
 * A Swoole worker serves many requests, and its container services are built
 * once for the worker's whole life. Storing one request's tenant, user or
 * session on such an object hands it to whoever is served next — and the bug
 * does not announce itself, because a single-user test never interleaves two
 * requests. The project swept roughly 120 singletons for this once; nothing
 * stopped the shape coming back.
 *
 * MEASURED 2026-09-06 when this was written: ZERO container services assign
 * request-shaped values to their own properties, and all four mutable statics
 * whose names suggest request scope consult the coroutine context first, keeping
 * the static only as a non-coroutine fallback for CLI and tests. Both facts are
 * now assertions rather than observations.
 */
final class CoroutineStateLeakGuardTest extends TestCase
{
    /** Names and expressions that mean "this belongs to one request". */
    private const REQUEST_SHAPED = '(tenant|session|auth|user|request|payload|principal|locale)';

    /**
     * Statics that read as request state by name and were checked not to be.
     * Named one at a time, with the reason, and never exempted by TYPE: an
     * `int` is a perfectly good tenant or user id and a `bool` is a perfectly
     * good "is this caller an admin", so a type-wide exemption would wave
     * through the two cheapest ways to leak an identity.
     */
    private const VERIFIED_NOT_REQUEST_STATE = [
        // A refcount of live trace sessions, not a session identity. Read only
        // to decide when to clear the query log; never carries request data.
        'QueryRecorder::$sessions',

        // moduleName => locales DIRECTORY. Paths, not a locale: registered once
        // at worker boot by a container-managed listener and identical for
        // every request. The request's own locale is $localeContext, which this
        // same class clones per coroutine into CoroutineLocal — and which this
        // guard clears on its own, without an entry here.
        'Translator::$packageLocaleDirs',
    ];

    /**
     * A worker-lifetime object may not take request-scoped state into a
     * property. The container builds #[AsService] classes once per worker, so a
     * property written from the current tenant or user is shared with every
     * later request that worker handles.
     */
    #[Test]
    public function no_container_service_stores_request_scoped_state_in_a_property(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $path => $source) {
            foreach ($this->leakingProperties($source) as $offence) {
                $offenders[] = $path . ': ' . $offence;
            }
        }

        sort($offenders);

        self::assertSame(
            [],
            $offenders,
            "Request-scoped state on a worker-lifetime service leaks to the next request served.\n"
            . "Put it in the coroutine context (see AuthContextStore) rather than a property:\n  - "
            . implode("\n  - ", $offenders),
        );
    }

    /**
     * A mutable static whose name says "request" must consult the coroutine
     * context, keeping the static as the fallback for CLI and tests only.
     *
     * This is the shape the correct classes already use — AuthContextStore and
     * IsomorphicContextStore both branch on inCoroutine() and reach for
     * Coroutine::getContext() first. A class holding such a static without ever
     * touching the coroutine context is the original trap, unfixed.
     */
    #[Test]
    public function a_request_shaped_static_always_consults_the_coroutine_context(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $path => $source) {
            foreach ($this->leakingStatics($source) as $offence) {
                $offenders[] = $path . ': ' . $offence;
            }
        }

        sort($offenders);

        self::assertSame(
            [],
            $offenders,
            "These hold request-shaped state in a mutable static without ever reading the coroutine "
            . "context, so every coroutine in the worker shares one value:\n  - " . implode("\n  - ", $offenders),
        );
    }

    /**
     * The two checks above pass by finding nothing, so they are worth exactly
     * what the matchers are worth. A guard whose pattern quietly stops matching
     * is indistinguishable from a clean repository — this project has shipped
     * that bug before, in a structural guard whose class pattern missed
     * `final readonly class` and so overlooked 465 classes while reporting
     * green. These fixtures fail if either matcher goes blind.
     */
    #[Test]
    public function the_matchers_catch_the_shapes_they_exist_for(): void
    {
        $leakyService = <<<'PHP'
            #[AsService]
            final class Leaky
            {
                private string $tenantId = '';
                public function handle(Request $request): void
                {
                    $this->tenantId = $request->currentTenantId();
                }
            }
            PHP;

        self::assertSame(
            ['$this->tenantId = $request->currentTenantId()'],
            $this->leakingProperties($leakyService),
            'the property matcher no longer sees a request-scoped write',
        );

        $safeService = <<<'PHP'
            #[AsService]
            final class Safe
            {
                #[InjectAsReadonly]
                private TenantResolver $tenantResolver;

                private bool $built = false;
                public function boot(): void
                {
                    $this->tenantResolver = new TenantResolver();
                    $this->built = true;
                }
            }
            PHP;

        self::assertSame([], $this->leakingProperties($safeService), 'an injected collaborator is not a leak');

        $leakyStatic = <<<'PHP'
            final class Holder
            {
                private static ?string $currentUser = null;
                public static function set(string $user): void
                {
                    self::$currentUser = $user;
                }
            }
            PHP;

        self::assertSame(
            ['static $currentUser'],
            $this->leakingStatics($leakyStatic),
            'the static matcher no longer sees request-shaped shared state',
        );

        $counter = <<<'PHP'
            final class QueryRecorder
            {
                private static int $sessions = 0;
                public static function open(): void { self::$sessions++; }
            }
            PHP;

        self::assertSame(
            [],
            $this->leakingStatics($counter),
            'the named exemption stopped working',
        );
    }

    /**
     * The three ways this guard was too loose when it was first written. Each
     * fixture is a leak the earlier matchers called safe.
     */
    #[Test]
    public function the_matchers_do_not_wave_through_the_shapes_that_only_look_safe(): void
    {
        // 1. A constructor is not a safe place for request state. #[AsService]
        // classes take no constructor arguments and are built ONCE per worker,
        // so this captures the first request's tenant and serves it to every
        // request the worker handles afterwards.
        $capturedInConstructor = <<<'PHP'
            #[AsService]
            final class Captured
            {
                private string $tenantId;
                public function __construct()
                {
                    $this->tenantId = $request->currentTenantId();
                }
            }
            PHP;

        self::assertSame(
            ['$this->tenantId = $request->currentTenantId()'],
            $this->leakingProperties($capturedInConstructor),
            'a constructor that captures request state is a worker-lifetime leak, not an exemption',
        );

        // 2. Scalars carry identities perfectly well. A numeric tenant id and
        // an "is this caller an admin" flag are the two cheapest ways to leak
        // one, and a type-wide exemption waves both through.
        $scalarTenant = <<<'PHP'
            final class Scope
            {
                private static int $tenantId = 0;
                public static function enter(int $id): void { self::$tenantId = $id; }
            }
            PHP;

        self::assertSame(
            ['static $tenantId'],
            $this->leakingStatics($scalarTenant),
            'an int is a perfectly good tenant id',
        );

        $scalarFlag = <<<'PHP'
            final class Gate
            {
                private static bool $userIsAdmin = false;
                public static function grant(): void { self::$userIsAdmin = true; }
            }
            PHP;

        self::assertSame(
            ['static $userIsAdmin'],
            $this->leakingStatics($scalarFlag),
            'a bool is a perfectly good authorization decision',
        );

        // 3. Coroutine storage somewhere in the file proves nothing about THIS
        // static. Here the context is consulted for an unrelated value while
        // the user is assigned straight to shared memory.
        $unrelatedContext = <<<'PHP'
            final class Mixed
            {
                private static ?string $currentUser = null;

                public static function locale(): string
                {
                    return Coroutine::getContext()['locale'] ?? 'en';
                }

                public static function setUser(string $user): void
                {
                    self::$currentUser = $user;
                }
            }
            PHP;

        self::assertSame(
            ['static $currentUser'],
            $this->leakingStatics($unrelatedContext),
            'a coroutine call elsewhere in the file does not make this static safe',
        );

        // The real shape, for contrast: context and fallback in ONE method,
        // the static reached only when there is no coroutine. AuthContextStore.
        $contextFirst = <<<'PHP'
            final class Store
            {
                private static ?string $currentUser = null;

                public static function setUser(string $user): void
                {
                    if (Coroutine::getCid() > 0) {
                        Coroutine::getContext()[self::KEY] = $user;
                        return;
                    }
                    self::$currentUser = $user;
                }
            }
            PHP;

        self::assertSame([], $this->leakingStatics($contextFirst), 'the context-first fallback is the fix, not a leak');
    }

    /**
     * A floor: if the scan stops finding files, both checks pass while
     * examining nothing.
     */
    #[Test]
    public function the_scan_actually_reads_the_repository(): void
    {
        $files = $this->sourceFiles();

        self::assertGreaterThan(1000, count($files), 'the source scan found almost nothing');
        self::assertGreaterThan(
            50,
            count(array_filter($files, static fn (string $s): bool => str_contains($s, '#[AsService]'))),
            'no container services were seen, so the first check examined nothing',
        );
    }

    /** @return list<string> request-scoped writes to a container service's own properties */
    private function leakingProperties(string $source): array
    {
        if (!str_contains($source, '#[AsService]')) {
            return [];
        }

        preg_match_all('/#\[Inject(?:AsReadonly|AsMutable)\][^;]*?\$(\w+)\s*;/s', $source, $m);
        $injected = $m[1] ?? [];

        preg_match_all(
            '/\$this->(\w+)\s*=\s*([^;\n]*' . self::REQUEST_SHAPED . '[^;\n]*);/i',
            $source,
            $writes,
            PREG_SET_ORDER,
        );

        $offenders = [];

        foreach ($writes as $write) {
            if (in_array($write[1], $injected, true)) {
                continue; // an injected collaborator, not request data
            }

            // A constructor is NOT exempt. #[AsService] classes take no
            // constructor arguments and are built once per worker, so a
            // constructor that reads ambient request state captures the FIRST
            // request's tenant or user and serves it to every request after.
            $offenders[] = '$this->' . $write[1] . ' = ' . trim($write[2]);
        }

        return $offenders;
    }

    /** @return list<string> request-shaped mutable statics with no coroutine context behind them */
    private function leakingStatics(string $source): array
    {
        preg_match_all(
            '/^\s*(?:private|protected|public)\s+static\s+(?!readonly)(?:\??[\w\\\\|]+)?\s*\$(\w+)/m',
            $source,
            $statics,
            PREG_SET_ORDER,
        );

        $class = preg_match('/^(?:final |abstract |readonly )*class (\w+)/m', $source, $c) === 1 ? $c[1] : '';
        $offenders = [];

        foreach ($statics as $static) {
            $name = $static[1];

            if (preg_match('/' . self::REQUEST_SHAPED . '/i', $name) !== 1) {
                continue;
            }
            if (in_array($class . '::$' . $name, self::VERIFIED_NOT_REQUEST_STATE, true)) {
                continue;
            }

            $writes = $this->writeOffsets($source, $name);
            if ($writes === []) {
                continue; // never written; a constant in all but name
            }

            foreach ($writes as $offset) {
                if (!$this->methodAround($source, $offset, $reads)) {
                    continue;
                }
                if ($reads) {
                    continue 2; // context-first, with this static as the fallback
                }
            }

            $offenders[] = 'static $' . $name;
        }

        return $offenders;
    }

    /**
     * @return list<int> byte offsets of every write to self::$name
     */
    private function writeOffsets(string $source, string $name): array
    {
        preg_match_all(
            '/(?:self|static)::\$' . preg_quote($name, '/') . '\s*(?:=[^=]|\[|\+\+|--)/',
            $source,
            $m,
            PREG_OFFSET_CAPTURE,
        );

        return array_map(static fn (array $hit): int => (int) $hit[1], $m[0] ?? []);
    }

    /**
     * Whether the method containing $offset also consults coroutine-local
     * storage, reported through $reads.
     *
     * Bound to the METHOD, not the file. A class can call
     * Coroutine::getContext() somewhere unrelated while assigning the static
     * directly elsewhere, and a file-wide check calls that safe — the same
     * too-loose matcher this whole test exists to prevent. The shape that IS
     * safe keeps both in one place: read the context, return; otherwise fall
     * back to the static. See AuthContextStore.
     */
    private function methodAround(string $source, int $offset, ?bool &$reads): bool
    {
        preg_match_all(
            '/^\s*(?:(?:final|abstract|public|protected|private|static)\s+)*function\s/m',
            $source,
            $starts,
            PREG_OFFSET_CAPTURE,
        );

        $bounds = array_map(static fn (array $hit): int => (int) $hit[1], $starts[0] ?? []);
        if ($bounds === []) {
            return false;
        }

        $start = null;
        $end = strlen($source);
        foreach ($bounds as $bound) {
            if ($bound <= $offset) {
                $start = $bound;
                continue;
            }
            $end = $bound;
            break;
        }

        if ($start === null) {
            return false;
        }

        $body = substr($source, $start, $end - $start);
        $reads = str_contains($body, 'Coroutine::getContext()') || str_contains($body, 'CoroutineLocal');

        return true;
    }

    /** @return array<string, string> relative path => source */
    private function sourceFiles(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $dir = dirname(__DIR__, 4);
        $out = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace($dir . '/', '', $file->getPathname());
            if (!preg_match('#^[a-z0-9-]+/src/#', $path)) {
                continue;
            }
            $out[$path] = (string) file_get_contents($file->getPathname());
        }

        return $cache = $out;
    }
}

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
        // every request.
        'Translator::$packageLocaleDirs',

        // The BOOT-TIME locale context, deliberately shared. Its two capturing
        // writes are setService() ("call at worker boot") and initialize(),
        // which is guarded by an $initialized flag and runs once per worker.
        // The REQUEST's locale is never this property: getRequestLocaleContext()
        // clones it per coroutine into CoroutineLocal, precisely so mutating a
        // locale cannot escape the request that did it.
        'Translator::$localeContext',
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
     * The two ways this guard was still too generous after the first round of
     * review, each a leak it called safe.
     */
    #[Test]
    public function one_careful_setter_does_not_excuse_a_careless_one(): void
    {
        // A correct context-first setter, and a second method that assigns the
        // same static directly. Checking only until the first safe write clears
        // the class — and this is the likeliest shape in real code: a helper
        // added later next to an accessor somebody thought about.
        $mixed = <<<'PHP'
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

                public static function forceUser(string $user): void
                {
                    self::$currentUser = $user;
                }
            }
            PHP;

        self::assertSame(
            ['static $currentUser'],
            $this->leakingStatics($mixed),
            'a direct write elsewhere in the class is still a direct write',
        );
    }

    /**
     * `=` is not the only way to write to a static. A matcher that knows only
     * `=` reports these as NEVER WRITTEN, which this guard skips as "a constant
     * in all but name" — the quietest possible way to miss a leak.
     */
    #[Test]
    public function every_assignment_operator_counts_as_a_write(): void
    {
        $template = <<<'PHP'
            final class Scope
            {
                private static ?string $tenantId = null;
                public static function enter(string $id): void { self::$tenantId OP $id; }
            }
            PHP;

        foreach (['??=', '.=', '+=', '|=', '&=', '^=', '*=', '/=', '%=', '<<=', '>>='] as $operator) {
            self::assertSame(
                ['static $tenantId'],
                $this->leakingStatics(str_replace('OP', $operator, $template)),
                "a write with {$operator} was not seen as a write",
            );
        }

        // And a comparison is not a write: a static only ever READ is a constant
        // in all but name, and flagging it would be noise.
        $compared = <<<'PHP'
            final class Probe
            {
                private static ?string $tenantId = null;
                public static function isSame(string $id): bool { return self::$tenantId === $id; }
                public static function isEqual(string $id): bool { return self::$tenantId == $id; }
                public static function isOther(string $id): bool { return self::$tenantId != $id; }
            }
            PHP;

        self::assertSame([], $this->leakingStatics($compared), 'a comparison was mistaken for a write');
    }

    /**
     * Resetting shared state is not leaking it.
     *
     * Every correct context-first store in this repository has a teardown
     * helper that nulls its fallback for CLI and tests, and that helper does
     * not consult the coroutine context — there is nothing to consult. Treating
     * it as a leak flagged AuthContextStore, LocaleContextStore,
     * IsomorphicContextStore and Translator all at once, which is the guard
     * calling the fix a bug.
     */
    #[Test]
    public function clearing_a_static_is_not_capturing_a_request(): void
    {
        $resettable = <<<'PHP'
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

                public static function clearFallback(): void
                {
                    self::$currentUser = null;
                }
            }
            PHP;

        self::assertSame([], $this->leakingStatics($resettable), 'a teardown helper is not a leak');

        // A default that is not null. LocaleContextStore resets to 'en', which
        // is what its declaration already gives every caller.
        $nonNullDefault = <<<'PHP'
            final class Locales
            {
                private static string $staticLocale = 'en';

                public static function setLocale(string $locale): void
                {
                    if (Coroutine::getCid() > 0) {
                        Coroutine::getContext()[self::KEY] = $locale;
                        return;
                    }
                    self::$staticLocale = $locale;
                }

                public static function reset(): void
                {
                    self::$staticLocale = 'en';
                }
            }
            PHP;

        self::assertSame([], $this->leakingStatics($nonNullDefault), 'restoring the declared default is not a leak');

        // The same class, with the helper capturing instead of clearing.
        $capturing = str_replace('self::$currentUser = null;', 'self::$currentUser = $GLOBALS[\'u\'];', $resettable);

        self::assertSame(
            ['static $currentUser'],
            $this->leakingStatics($capturing),
            'a helper that stores a value is a leak, resettable class or not',
        );
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
            '/^\s*(?:private|protected|public)\s+static\s+(?!readonly)(?:\??[\w\\\\|]+)?\s*\$(\w+)\s*(?:=\s*([^;]*))?;/m',
            $source,
            $statics,
            PREG_SET_ORDER,
        );

        $class = preg_match('/^(?:final |abstract |readonly )*class (\w+)/m', $source, $c) === 1 ? $c[1] : '';
        $offenders = [];

        foreach ($statics as $static) {
            $name = $static[1];
            $default = isset($static[2]) ? trim($static[2]) : 'null';

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

            // EVERY capturing write, not the first safe one. A class can hold a
            // correct context-first setter and a second method that assigns the
            // static directly, and clearing the static on the first safe write
            // would call that class clean — the shape most likely to appear as a
            // "convenience" helper next to a careful accessor.
            $safe = true;
            foreach ($writes as $offset) {
                if ($this->restoresDefault($source, $offset, $default)) {
                    continue;
                }
                if (!$this->methodAround($source, $offset, $reads) || $reads !== true) {
                    $safe = false;
                    break;
                }
            }

            if ($safe) {
                continue;
            }

            $offenders[] = 'static $' . $name;
        }

        return $offenders;
    }

    /**
     * Byte offsets of every write to self::$name.
     *
     * All of PHP's assignment operators, not just `=`. `self::$tenantId ??= $id`
     * is a write, and so are `.=`, `+=` and the bitwise forms; a matcher that
     * only knew `=` reported such a static as never written, which this guard
     * treats as "a constant in all but name" and skips entirely.
     *
     * Comparisons are excluded by the trailing (?!=): `==` and `===` fail it,
     * and `!=`, `<=`, `>=` never reach it because their first character is not
     * in the operator set.
     *
     * @return list<int>
     */
    private function writeOffsets(string $source, string $name): array
    {
        preg_match_all(
            '/(?:self|static)::\$' . preg_quote($name, '/')
                . '\s*(?:\[|\+\+|--|(?:\?\?|\*\*|<<|>>|[-+.*\/%|&^])?=(?!=))/',
            $source,
            $m,
            PREG_OFFSET_CAPTURE,
        );

        return array_map(static fn (array $hit): int => (int) $hit[1], $m[0] ?? []);
    }

    /**
     * Whether this write puts the property back to its DECLARED DEFAULT.
     *
     * Not "is it a literal" — that was tried and was wrong. `self::$isAdmin =
     * true;` is a literal and grants every later request on the worker admin;
     * the test fixture for exactly that caught the mistake. What is safe is
     * restoring the value the declaration already gives everybody:
     *
     *   private static ?string $fallbackUser = null;   reset writes null   safe
     *   private static string  $staticLocale = 'en';   reset writes 'en'   safe
     *   private static bool    $isAdmin     = false;   a write of true     LEAK
     *
     * Teardown helpers are written exactly like the first two
     * (AuthContextStore::clearFallback(), LocaleContextStore::reset()), and
     * requiring them to consult the coroutine context first flagged four
     * CORRECT context-first stores at once — the guard calling the fix a bug.
     */
    private function restoresDefault(string $source, int $offset, string $default): bool
    {
        $end = strpos($source, ';', $offset);
        if ($end === false) {
            return false;
        }

        $rhs = strstr(substr($source, $offset, $end - $offset), '=');
        if ($rhs === false) {
            return false; // ++ / -- / [] append: never a reset
        }

        $written = self::normalizeValue(substr($rhs, 1));
        $declared = self::normalizeValue($default);

        // A nullable property with no written default defaults to null anyway.
        return $written === $declared || ($written === 'null' && $declared === '');
    }

    private static function normalizeValue(string $value): string
    {
        return strtolower((string) preg_replace('/\s+/', '', trim($value)));
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

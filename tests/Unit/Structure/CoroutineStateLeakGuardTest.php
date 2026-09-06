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

        $contextFirst = str_replace(
            'self::$currentUser = $user;',
            'if (Coroutine::getCid() > 0) { Coroutine::getContext()[self::KEY] = $user; return; } self::$currentUser = $user;',
            $leakyStatic,
        );

        self::assertSame([], $this->leakingStatics($contextFirst), 'a coroutine-context-first store is the fix, not a leak');

        $counter = <<<'PHP'
            final class Sessions
            {
                private static int $sessions = 0;
                public static function open(): void { self::$sessions++; }
            }
            PHP;

        self::assertSame([], $this->leakingStatics($counter), 'an int cannot carry another request\'s data');
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
        $constructor = preg_match('/function __construct.*?\n    \}/s', $source, $c) === 1 ? $c[0] : '';

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
            if ($constructor !== '' && str_contains($constructor, $write[0])) {
                continue; // built once with the object
            }

            $offenders[] = '$this->' . $write[1] . ' = ' . trim($write[2]);
        }

        return $offenders;
    }

    /** @return list<string> request-shaped mutable statics with no coroutine context behind them */
    private function leakingStatics(string $source): array
    {
        preg_match_all(
            '/^\s*(?:private|protected|public)\s+static\s+(?!readonly)(\??[\w\\\\|]+)?\s*\$(\w+)/m',
            $source,
            $statics,
            PREG_SET_ORDER,
        );

        $offenders = [];

        foreach ($statics as $static) {
            [, $type, $name] = $static;

            if (preg_match('/' . self::REQUEST_SHAPED . '/i', $name) !== 1) {
                continue;
            }
            // A scalar counter or flag cannot carry one request's data into the
            // next: QueryRecorder::$sessions is a refcount whose name only reads
            // as request state.
            if (in_array(strtolower(ltrim($type ?? '', '?')), ['int', 'bool', 'float'], true)) {
                continue;
            }
            if (preg_match('/(?:self|static)::\$' . preg_quote($name, '/') . '\s*(?:=|\[|\+\+|--)/', $source) !== 1) {
                continue; // never written; a constant in all but name
            }
            if (str_contains($source, 'Coroutine::getContext()') || str_contains($source, 'CoroutineLocal')) {
                continue;
            }

            $offenders[] = 'static $' . $name;
        }

        return $offenders;
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

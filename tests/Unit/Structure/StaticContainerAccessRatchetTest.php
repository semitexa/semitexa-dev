<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static container access, counted across the whole repository.
 *
 * `semitexa.staticContainerAccess` has forbidden this in application code for a
 * long time and the rule works. What it never did was run over everything: the
 * `phpstan_di` gate analyses the files a change touches, so a violation in a
 * file nobody edits is never looked at again. Sixteen of them accumulated that
 * way, in packages whose tests were green the whole time — the rule reported
 * nothing because it was never asked.
 *
 * This is the asking. It is a ratchet: the sites below are the ones that do not
 * terminate in a single step, each with the reason. A new one fails the build;
 * removing one fails it too, which is the prompt to delete the entry.
 */
final class StaticContainerAccessRatchetTest extends TestCase
{
    /**
     * Namespaces the rule itself blesses — core internals plus the narrow
     * dynamic-dispatch tier. Kept in step with StaticContainerAccessRule; this
     * test asserts they still agree.
     */
    private const ALLOWED_PREFIXES = [
        'Semitexa\\Core\\Container\\',
        'Semitexa\\Core\\Console\\',
        'Semitexa\\Core\\Server\\',
        'Semitexa\\Core\\Queue\\',
        'Semitexa\\Core\\PHPStan\\Rules\\',
    ];

    /**
     * Compared with ===, never as a prefix. A class name used as a prefix
     * blesses more than it names: 'Semitexa\\Core\\Application' permitted the
     * whole 'Semitexa\\Core\\Application\\' namespace along with the class, and
     * would equally permit a future 'ApplicationWorker'.
     */
    private const ALLOWED_EXACT = [
        'Semitexa\\Core\\Application',
        'Semitexa\\Core\\Log\\StaticLoggerBridge',
        'Semitexa\\Core\\Event\\EventDispatcher',
        'Semitexa\\Scheduler\\Application\\Service\\RunExecutor',
        'Semitexa\\Core\\Application\\Console\\Command\\TestHandlerCommand',
        'Semitexa\\Dev\\Application\\Service\\Trace\\ReplayRunner',
    ];

    /**
     * MEASURED 2026-09-06: down from 16. The eleven that were removed were
     * classes the container builds, so they could take the container as an
     * injected typed property — the UiDispatchHandler pattern.
     *
     * These five cannot, and each for a stated reason. None is a TODO with a
     * date on it; they are the cases where property injection is not the
     * answer, and the entry says why so the next reader does not re-derive it.
     */
    private const KNOWN = [
        // Builds a NEW request-scoped container rather than reading the
        // current one. Nothing to inject: the container it wants does not
        // exist yet. Same tier as ReplayRunner, which the rule blesses by name.
        'packages/semitexa-dev/src/Application/Console/Command/AiInvokeCommand.php' => 1,

        // Constructed directly, not by the container (they declare their own
        // constructors), so an injected property is never filled. Fixing these
        // means changing who builds them, which is not a one-step change.
        'packages/semitexa-ledger/src/Application/Service/CommandProcessor.php' => 1,
        'packages/semitexa-ledger/src/Application/Service/LedgerReplayer.php' => 1,
        'packages/semitexa-ssr/src/Application/Service/Layout/SlotHandlerPipeline.php' => 1,
        'packages/semitexa-graphql/src/Application/Service/Runtime/ContainerHandlerInvoker.php' => 2,
    ];

    #[Test]
    public function no_production_class_reaches_the_container_statically_beyond_the_known_five(): void
    {
        $found = $this->staticAccessSites();
        $known = self::KNOWN;
        ksort($known);

        self::assertSame(
            $known,
            $found,
            "Static container access changed.\n"
            . "A NEW entry: inject the container as a typed property instead — see UiDispatchHandler,\n"
            . "  #[InjectAsReadonly] protected ContainerInterface \$container;\n"
            . "  (or SemitexaContainer when you need resolve(), which PSR-11 cannot express).\n"
            . "A MISSING entry: good — delete it from KNOWN.",
        );
    }

    /**
     * The list above is only meaningful if the scan can still see the code it
     * is counting. A matcher that quietly stops matching produces an empty
     * result, and an empty result is what a clean repository looks like.
     */
    #[Test]
    public function the_scan_still_sees_the_repository_and_the_shape_it_counts(): void
    {
        $files = $this->productionFiles();
        self::assertGreaterThan(1000, count($files), 'the production scan found almost nothing');

        // The blessed callers are real code containing the exact shape being
        // counted. If the matcher went blind, these would vanish too.
        $blessed = 0;
        foreach ($files as $path => $source) {
            if (preg_match(self::pattern(), $source) === 1 && !isset(self::KNOWN[$path])) {
                $blessed++;
            }
        }
        self::assertGreaterThan(
            5,
            $blessed,
            'no allowlisted ContainerFactory call sites were seen, so the matcher is not matching',
        );
    }

    /**
     * The ratchet's allowlist and the PHPStan rule's must not drift: an entry
     * added to one and not the other means the two disagree about what is
     * forbidden, and the looser of them wins silently.
     */
    /**
     * A prefix entry must BE a namespace. This is the invariant whose breach
     * exempted TestHandlerCommand without anyone deciding to: a class name in
     * the prefix list quietly blesses every namespace and class that starts
     * with it.
     */
    #[Test]
    public function every_prefix_entry_is_a_namespace_not_a_class_name(): void
    {
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            self::assertStringEndsWith(
                '\\',
                $prefix,
                "'{$prefix}' is a class name, not a namespace. Prefix matching would bless "
                . 'everything starting with it — move it to ALLOWED_EXACT.',
            );
        }
    }

    #[Test]
    public function the_allowlist_matches_the_phpstan_rule(): void
    {
        $rule = (string) file_get_contents(
            dirname(__DIR__, 4) . '/semitexa-core/src/PHPStan/Rules/StaticContainerAccessRule.php',
        );

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if ($prefix === 'Semitexa\\Core\\PHPStan\\Rules\\') {
                continue; // the rule cannot flag itself; excluded here, not there
            }
            self::assertStringContainsString(
                str_replace('\\', '\\\\', $prefix),
                $rule,
                "This test blesses {$prefix}; StaticContainerAccessRule does not.",
            );
        }

        foreach (self::ALLOWED_EXACT as $class) {
            self::assertStringContainsString(str_replace('\\', '\\\\', $class), $rule);
        }
    }

    private static function pattern(): string
    {
        return '/\bContainerFactory::\w+\(/';
    }

    /** @return array<string, int> relative path => number of static call sites */
    private function staticAccessSites(): array
    {
        $found = [];

        foreach ($this->productionFiles() as $path => $source) {
            $count = preg_match_all(self::pattern(), $source);
            if ($count === 0 || $count === false) {
                continue;
            }
            if ($this->isBlessed($source)) {
                continue;
            }
            $found[$path] = $count;
        }

        // Compared as identical arrays, so both a new site and a stale entry
        // fail; both sides are sorted so the diff reads as one.
        ksort($found);

        return $found;
    }

    /**
     * Mirrors the rule: a prefix is tested against the namespace AND the fully
     * qualified class name. Entries such as `Semitexa\Core\Application` and
     * `Semitexa\Scheduler\Application\Service\RunExecutor` name a CLASS, not
     * a namespace, so checking only the namespace blesses neither — and the
     * ratchet would then report core internals the rule deliberately permits.
     */
    private function isBlessed(string $source): bool
    {
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $m) !== 1) {
            return false;
        }
        $namespace = trim($m[1]);
        $fqcn = preg_match('/^(?:final |abstract |readonly )*class (\w+)/m', $source, $c) === 1
            ? $namespace . '\\' . $c[1]
            : '';

        if ($fqcn !== '' && in_array($fqcn, self::ALLOWED_EXACT, true)) {
            return true;
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($namespace . '\\', $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> relative path => source, production code only */
    private function productionFiles(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        // __DIR__ is packages/semitexa-dev/tests/Unit/Structure, so four levels
        // up is the packages directory itself, not the project root.
        $packages = dirname(__DIR__, 4);
        $projectRoot = dirname($packages);
        $out = [];

        foreach ([$packages, $projectRoot . '/src'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = str_replace($projectRoot . '/', '', $file->getPathname());
                // Production only: tests may reach for the container freely,
                // and the PHPStan rule is not run over them either.
                if (!preg_match('#(^packages/[a-z0-9-]+/src/|^src/modules/[A-Za-z0-9]+/src/)#', $path)) {
                    continue;
                }
                $out[$path] = (string) file_get_contents($file->getPathname());
            }
        }

        return $cache = $out;
    }
}

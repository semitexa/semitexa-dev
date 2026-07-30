<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Capability;

use ReflectionClass;
use Semitexa\Core\Attribute\Capability;
use Semitexa\Core\Discovery\ClassDiscovery;

/**
 * The framework's own account of what it can do, read from `#[Capability]`.
 *
 * One reader for every surface that speaks about mechanisms — the catalog
 * command, the mechanism lint, the page generator. Each of those had started
 * reflecting over the attributes itself, and three copies of "how a capability
 * is described" is exactly how the three would eventually describe the same
 * mechanism differently.
 *
 * Derived on every call rather than stored: that is what lets a consumer project
 * pick up a capability added to an installed package without editing anything of
 * its own.
 *
 * Distinct from {@see CapabilityRegistry}, which lists the CLI commands
 * available to run. Same word, different question.
 */
final readonly class FrameworkCapabilityCatalog
{
    public function __construct(
        private ClassDiscovery $classDiscovery,
    ) {
    }

    /**
     * @return list<array{id: string, summary: string, use_when: string, avoid_when: string,
     *                    replaces: list<string>, see_also: string, kind: string,
     *                    declared_by: string, declared_by_short: string, package: string}>
     */
    public function all(): array
    {
        $this->classDiscovery->initialize();

        $out = [];
        foreach ($this->classDiscovery->findClassesWithAttribute(Capability::class) as $class) {
            // Discovery lists what the classmap claims. A type that cannot load
            // — an optional package half-removed — must not take the catalog down
            // with it, because every caller here is a diagnostic surface.
            //
            // All four kinds, not just classes: `Attribute::TARGET_CLASS` covers
            // interfaces, traits and enums too, and `class_exists()` alone
            // answers false for them — which would drop such a declaration from
            // the catalog without a word.
            if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
                continue;
            }

            // Same reasoning one level down. `newInstance()` runs the attribute
            // constructor, so a single misdeclared #[Capability] in one
            // installed package would otherwise take out every surface that
            // speaks about mechanisms — the lint, the graph command, the page
            // generator. A diagnostic that dies on the thing it is diagnosing
            // is worth less than one that reports the rest.
            try {
                $described = self::describe(new ReflectionClass($class));
            } catch (\Throwable) {
                continue;
            }

            foreach ($described as $entry) {
                $out[] = $entry;
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return $out;
    }

    /**
     * Every declaration in the monorepo, including packages this checkout has
     * never installed.
     *
     * {@see all()} reflects over the classmap, which is the right answer to
     * "what can I use here" and the wrong one for the shipped index. The index
     * exists to advertise what a project has NOT installed — and the packages
     * least likely to be installed anywhere are exactly `theme-sky`,
     * `showcase-kit` and `demo`, which no working copy requires. Built from the
     * classmap alone, the index silently omitted precisely the entries it
     * exists to carry, while reporting all forty packages in its `packages`
     * field: an artifact that looks complete and is not.
     *
     * So the sweep follows the same source the package list already does — the
     * directories on disk. Only the conventional `src/Capabilities.php` is read:
     * a mechanism lives on an attribute class that consumer code must be able to
     * write anyway, so a package shipping one is a package somebody installs.
     *
     * Monorepo-only by construction — a consumer has no `packages/` tree, and
     * would get an empty sweep and the live catalog unchanged.
     *
     * @return list<array<string, mixed>>
     */
    public function everythingOnDisk(string $projectRoot): array
    {
        // Only what a Composer package owns. In the monorepo `ClassDiscovery`
        // also reaches `src/modules/`, so an application module declaring
        // `#[Capability]` would ride into the shipped index and advertise, to
        // every consumer, something they cannot require — the index names a
        // package to install, and this one would name the project it was built
        // in. The module-structure spec permits the file there; this is the
        // boundary where permitting it would actually cost something.
        $packages = CapabilityIndex::packagesOnDisk($projectRoot);

        $byId = [];
        foreach ($this->all() as $entry) {
            if (!in_array((string) $entry['package'], $packages, true)) {
                continue;
            }
            $byId[(string) $entry['id']] = $entry;
        }

        // Installed wins: the classmap is the loaded truth, and a file read off
        // disk cannot know about a class the autoloader resolved elsewhere.
        foreach (self::declarationsOnDisk($projectRoot) as $entry) {
            $byId[(string) $entry['id']] ??= $entry;
        }

        $out = array_values($byId);
        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return $out;
    }

    /**
     * `#[Capability]` read from each package's conventional declaration file.
     *
     * The file has to be required rather than autoloaded, because the whole
     * point is that nothing has loaded it. That is safe only because the
     * convention makes it safe: `Capabilities` carries no code, no
     * dependencies, and one class per file. A package that puts logic there has
     * broken a documented rule, and the module-structure spec is where that gets
     * caught.
     *
     * It does leave a mark on the process, and the mark is not obvious: after
     * this runs, `class_exists()` answers yes for classes the autoloader would
     * have refused, so anything using it as a proxy for "is this package
     * installed" starts lying. {@see all()} is unaffected — it reads the
     * classmap, which a `require` does not join — but a test asserting on
     * `class_exists` did fail this way, passing alone and failing in the suite.
     * Ask the catalog what is installed; do not ask PHP what is loaded.
     *
     * @return list<array<string, mixed>>
     */
    private static function declarationsOnDisk(string $projectRoot): array
    {
        $out = [];
        // Same glob shape the package list already uses. Two ideas of which
        // directories count would put a package in one field of the index and
        // not the other.
        foreach ((array) glob($projectRoot . '/packages/semitexa-*/src/Capabilities.php') as $file) {
            $class = self::classIn((string) $file);
            if ($class === null) {
                continue;
            }

            if (!class_exists($class, false)) {
                require_once (string) $file;
            }

            // Still absent means the file did not declare what its namespace
            // said it would. Skipping keeps a malformed package from taking down
            // a release gate; the module-structure check is what reports it.
            if (!class_exists($class, false)) {
                continue;
            }

            try {
                $described = self::describe(new ReflectionClass($class));
            } catch (\Throwable) {
                continue;
            }

            foreach ($described as $entry) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * The class a `Capabilities.php` declares, from its namespace alone.
     *
     * Cheaper and safer than loading the file to find out: reading it must not
     * be what decides whether it is worth reading.
     */
    private static function classIn(string $file): ?string
    {
        $source = (string) file_get_contents($file);
        if (preg_match('/^namespace\s+([^;\s]+)\s*;/m', $source, $matches) !== 1) {
            return null;
        }

        return $matches[1] . '\\Capabilities';
    }

    /**
     * One declaring class rendered as catalog entries.
     *
     * Shared by both readers on purpose: the shipped index is compared against
     * the live catalog by hashing these arrays, so two ways of building one
     * would make the freshness gate fail on a difference nobody made.
     *
     * The shape is spelled out rather than widened to `array<string, mixed>`.
     * It used to be an array literal inside {@see all()}, where the analyser
     * read the real shape whatever the docblock said; moving it here without
     * carrying the shape along silently degraded every caller's inference —
     * `make:page` alone picked up seven new `mixed` errors from a refactor that
     * changed no behaviour.
     *
     * @param ReflectionClass<object> $reflection
     * @return list<array{id: string, summary: string, use_when: string, avoid_when: string,
     *                    replaces: list<string>, see_also: string, kind: string,
     *                    declared_by: string, declared_by_short: string, package: string}>
     */
    private static function describe(ReflectionClass $reflection): array
    {
        $out = [];
        foreach ($reflection->getAttributes(Capability::class) as $attribute) {
            $capability = $attribute->newInstance();
            $out[] = [
                'id' => $capability->id,
                'summary' => $capability->summary,
                'use_when' => $capability->useWhen,
                'avoid_when' => $capability->avoidWhen,
                'replaces' => array_values($capability->replaces),
                'see_also' => $capability->seeAlso,
                // A declaring class that is itself an attribute describes a
                // mechanism someone writes into their code; anything else
                // describes what a package offers. Derived rather than
                // declared, so the two cannot disagree.
                'kind' => $reflection->getAttributes(\Attribute::class) !== [] ? 'mechanism' : 'package',
                'declared_by' => $reflection->getName(),
                'declared_by_short' => $reflection->getShortName(),
                'package' => self::packageOf($reflection),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: string, summary: string, use_when: string, avoid_when: string,
     *                    replaces: list<string>, see_also: string, kind: string,
     *                    declared_by: string, declared_by_short: string, package: string}>
     */
    public function inArea(string $area): array
    {
        $prefix = rtrim($area, '.') . '.';

        return array_values(array_filter(
            $this->all(),
            static fn (array $c): bool => str_starts_with((string) $c['id'], $prefix)
        ));
    }

    /** @return array<string, array<string, mixed>> keyed by capability id */
    public function keyedById(): array
    {
        $out = [];
        foreach ($this->all() as $entry) {
            $out[(string) $entry['id']] = $entry;
        }

        return $out;
    }

    /**
     * The Composer package that actually ships the declaring class.
     *
     * Read from the owning `composer.json` rather than derived from the
     * namespace, because the two do not agree and the disagreement is not
     * cosmetic. `Semitexa\PlatformUi\` maps to `semitexa/platformui`, while the
     * package installs as `semitexa/platform-ui` — and this string is what
     * `dev:graph:mechanisms` prints to an agent under "Not installed: provided
     * by …". A name that cannot be required sends whoever followed it back to
     * hand-rolling the thing the capability exists to advertise.
     *
     * Walking up from the class file finds the right manifest wherever the
     * package sits: `vendor/semitexa/ssr/` in a consumer, `packages/semitexa-ssr/`
     * in the monorepo, no special case for either.
     */
    private static function packageOf(ReflectionClass $reflection): string
    {
        $file = $reflection->getFileName();

        if (is_string($file)) {
            $dir = dirname($file);
            // The walk terminates on its own at the filesystem root, where
            // dirname() becomes a fixed point. Bounded anyway: an unreadable
            // tree must not spin a diagnostic command.
            for ($depth = 0; $depth < 32; $depth++) {
                $manifest = $dir . '/composer.json';
                if (is_file($manifest)) {
                    $decoded = json_decode((string) file_get_contents($manifest), true);
                    // A manifest without a name is a project root, not a
                    // package. Keep climbing rather than giving up: nothing
                    // says the first composer.json found is the owning one.
                    if (is_array($decoded) && is_string($decoded['name'] ?? null) && $decoded['name'] !== '') {
                        return $decoded['name'];
                    }
                }

                $parent = dirname($dir);
                if ($parent === $dir) {
                    break;
                }
                $dir = $parent;
            }
        }

        // Nothing on disk to read — an eval'd or internal class. The namespace
        // guess is wrong often enough that it is a label, not an install
        // target, but it beats an empty column in a diagnostic.
        return self::packageFromNamespace($reflection->getName());
    }

    /** Last-resort label for a class with no file behind it. */
    private static function packageFromNamespace(string $class): string
    {
        $parts = explode('\\', $class);

        return isset($parts[1]) ? strtolower($parts[0] . '/' . $parts[1]) : $parts[0];
    }
}

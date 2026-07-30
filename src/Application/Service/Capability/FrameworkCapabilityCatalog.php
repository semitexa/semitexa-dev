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
            // Discovery lists what the classmap claims. A class that cannot load
            // — an optional package half-removed — must not take the catalog down
            // with it, because every caller here is a diagnostic surface.
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
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
                    'declared_by' => $class,
                    'declared_by_short' => $reflection->getShortName(),
                    'package' => self::packageOf($reflection),
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
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

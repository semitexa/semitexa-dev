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
     *                    replaces: list<string>, see_also: string, attribute: string,
     *                    attribute_short: string, package: string}>
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
                    'attribute' => $class,
                    'attribute_short' => $reflection->getShortName(),
                    'package' => self::packageOf($class),
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

    /** Best-effort package label from the namespace, for grouping only. */
    private static function packageOf(string $class): string
    {
        $parts = explode('\\', $class);

        return isset($parts[1]) ? strtolower($parts[0] . '/' . $parts[1]) : $parts[0];
    }
}

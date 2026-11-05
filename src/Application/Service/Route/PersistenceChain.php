<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Route;

use Semitexa\Core\Attribute\InjectAsFactory;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Attribute\FromTable;

/**
 * The tables a route writes or reads, and what a change to one of their fields
 * has to touch: the resource model, the mapper, the domain model and the
 * repository. Followed from the handlers through their injections, so an agent
 * adding a field stops finding the chain by reading the code.
 */
final class PersistenceChain
{
    /** Handler, then a service, then the repository behind its interface. */
    private const MAX_DEPTH = 3;

    public function __construct(private readonly ClassDiscovery $classes)
    {
    }

    /**
     * @param list<string> $classes the route's payload and handlers
     * @return list<array{table: string, resource: string, repository: ?string, mapper: ?string, domain: ?string}>
     */
    public function of(array $classes): array
    {
        $repositories = $this->repositoriesByContract();
        $mappers = $this->mappersByResource();
        $found = [];
        $seen = [];
        $queue = array_map(static fn (string $class): array => [$class, 0], $classes);

        while ($queue !== []) {
            [$class, $depth] = array_shift($queue);
            if (isset($seen[$class]) || !self::isOurs($class) || !(class_exists($class) || interface_exists($class))) {
                continue;
            }
            $seen[$class] = true;
            $reflection = new \ReflectionClass($class);

            foreach (self::resourcesNamedIn($reflection) as $resource) {
                $found[$resource] ??= [
                    'table' => self::tableOf($resource),
                    'resource' => $resource,
                    'repository' => null,
                    'mapper' => $mappers[$resource]['mapper'] ?? null,
                    'domain' => $mappers[$resource]['domain'] ?? null,
                ];
                if ($depth > 0) {
                    $found[$resource]['repository'] ??= $class;
                }
            }

            if ($depth >= self::MAX_DEPTH) {
                continue;
            }
            foreach ($repositories[$class] ?? [] as $implementation) {
                $queue[] = [$implementation, $depth + 1];
            }
            foreach (self::injectedTypes($reflection) as $type) {
                $queue[] = [$type, $depth + 1];
            }
        }

        return array_values($found);
    }

    /** @return array<string, list<string>> contract → the classes that satisfy it */
    private function repositoriesByContract(): array
    {
        $byContract = [];
        foreach ($this->classes->findClassesWithAttribute(SatisfiesRepositoryContract::class) as $class) {
            foreach ((new \ReflectionClass($class))->getAttributes(SatisfiesRepositoryContract::class) as $attribute) {
                $byContract[$attribute->newInstance()->of][] = $class;
            }
        }

        return $byContract;
    }

    /** @return array<string, array{mapper: string, domain: string}> resource → its mapper and domain model */
    private function mappersByResource(): array
    {
        $byResource = [];
        foreach ($this->classes->findClassesWithAttribute(AsMapper::class) as $class) {
            foreach ((new \ReflectionClass($class))->getAttributes(AsMapper::class) as $attribute) {
                $mapper = $attribute->newInstance();
                $byResource[$mapper->resourceModel] = ['mapper' => $class, 'domain' => $mapper->domainModel];
            }
        }

        return $byResource;
    }

    /** @return list<string> */
    private static function injectedTypes(\ReflectionClass $class): array
    {
        $types = [];
        foreach ($class->getProperties() as $property) {
            $injected = $property->getAttributes(InjectAsReadonly::class) !== []
                || $property->getAttributes(InjectAsMutable::class) !== []
                || $property->getAttributes(InjectAsFactory::class) !== [];
            $type = $property->getType();
            if ($injected && $type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $types[] = $type->getName();
            }
        }

        return $types;
    }

    /**
     * The resource models a class names: its imports, its own namespace's `::class`, and a `model:` on its route attribute.
     *
     * @return list<string>
     */
    private static function resourcesNamedIn(\ReflectionClass $class): array
    {
        $names = [];
        foreach ($class->getAttributes() as $attribute) {
            $model = $attribute->getArguments()['model'] ?? null;
            if (is_string($model)) {
                $names[] = $model;
            }
        }
        $file = $class->getFileName();
        $source = is_string($file) ? (string) file_get_contents($file) : '';
        if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?;/m', $source, $imports) > 0) {
            array_push($names, ...$imports[1]);
        }
        // A class of the same namespace needs no import.
        if (preg_match_all('/(?<![\\\\\w])([A-Z]\w*)::class/', $source, $local) > 0) {
            foreach ($local[1] as $short) {
                $names[] = $class->getNamespaceName() . '\\' . $short;
            }
        }

        return array_values(array_unique(array_filter($names, self::isResource(...))));
    }

    private static function isResource(string $class): bool
    {
        return self::isOurs($class) && class_exists($class) && (new \ReflectionClass($class))->getAttributes(FromTable::class) !== [];
    }

    /** Only framework and project classes: walking a library would load it for nothing. */
    private static function isOurs(string $class): bool
    {
        return str_starts_with($class, 'Semitexa\\') || str_starts_with($class, 'App\\');
    }

    private static function tableOf(string $resource): string
    {
        $attribute = (new \ReflectionClass($resource))->getAttributes(FromTable::class)[0] ?? null;

        return $attribute === null ? '' : $attribute->newInstance()->name;
    }
}

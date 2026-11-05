<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Route;

use Semitexa\Core\Attribute\InjectAsFactory;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Attribute\AsRepository;
use Semitexa\Orm\Attribute\FromTable;

/**
 * The tables a route's code names, and what a change to one of their fields has
 * to touch: the resource model, the mapper, the domain model and the repository.
 * Followed from the handlers through their injections, so an agent adding a field
 * stops finding the chain by reading the code.
 *
 * Naming a table is not proof of reading it: a page that prints a resource model's
 * source names it too. `named_by` says which class to open when that matters.
 */
final class PersistenceChain
{
    /** Handler, then a service, then a repository; an interface and its parent cost no step. */
    private const MAX_DEPTH = 3;

    /** @var list<string> */
    private array $unreadable = [];

    public function __construct(private readonly ClassDiscovery $classes)
    {
    }

    /**
     * @param list<string> $classes the route's payload and handlers
     * @return array{
     *     tables: list<array{table: string, resource: string, repository: ?string, mapper: ?string, domain: ?string, named_by: string}>,
     *     unreadable: list<string>
     * } `unreadable` names the classes the walk could not load, so an empty `tables` is never a guess
     */
    public function of(array $classes): array
    {
        $this->unreadable = [];
        $implementations = $this->implementationsByContract();
        $mappers = $this->mappersByResource();
        $found = [];
        $seen = [];
        $queue = array_map(static fn (string $class): array => [$class, 0, null], $classes);

        while ($queue !== []) {
            [$class, $depth, $repository] = array_shift($queue);
            $visit = $class . '|' . $repository;
            if (isset($seen[$visit]) || !self::isOurs($class)) {
                continue;
            }
            $seen[$visit] = true;
            $reflection = $this->reflect($class);
            if ($reflection === null) {
                continue;
            }
            $repository ??= self::isRepository($reflection) ? $class : null;

            foreach ($this->resourcesNamedIn($reflection) as $resource) {
                $found[$resource] ??= [
                    'table' => $this->tableOf($resource),
                    'resource' => $resource,
                    'repository' => null,
                    'mapper' => $mappers[$resource]['mapper'] ?? null,
                    'domain' => $mappers[$resource]['domain'] ?? null,
                    'named_by' => $class,
                ];
                $found[$resource]['repository'] ??= $repository;
            }

            foreach ($implementations[$class] ?? [] as $implementation) {
                $queue[] = [$implementation, $depth, null];
            }
            $parent = $reflection->getParentClass();
            if ($parent !== false) {
                $queue[] = [$parent->getName(), $depth, $repository];
            }
            if ($depth < self::MAX_DEPTH) {
                foreach (self::injectedTypes($reflection) as $type) {
                    $queue[] = [$type, $depth + 1, null];
                }
            }
        }

        return ['tables' => array_values($found), 'unreadable' => array_values(array_unique($this->unreadable))];
    }

    /** @return array<string, list<string>> contract → the classes that satisfy it */
    private function implementationsByContract(): array
    {
        $byContract = [];
        foreach ([SatisfiesRepositoryContract::class, SatisfiesServiceContract::class] as $attributeClass) {
            foreach ($this->classes->findClassesWithAttribute($attributeClass) as $class) {
                foreach ($this->attributesOf($class, $attributeClass) as $attribute) {
                    $byContract[$attribute->of][] = $class;
                }
            }
        }

        return $byContract;
    }

    /** @return array<string, array{mapper: string, domain: string}> resource → its mapper and domain model */
    private function mappersByResource(): array
    {
        $byResource = [];
        foreach ($this->classes->findClassesWithAttribute(AsMapper::class) as $class) {
            foreach ($this->attributesOf($class, AsMapper::class) as $mapper) {
                $byResource[$mapper->resourceModel] = ['mapper' => $class, 'domain' => $mapper->domainModel];
            }
        }

        return $byResource;
    }

    /**
     * @template T of object
     * @param class-string<T> $attributeClass
     * @return list<T>
     */
    private function attributesOf(string $class, string $attributeClass): array
    {
        try {
            return array_map(
                static fn (\ReflectionAttribute $attribute): object => $attribute->newInstance(),
                (new \ReflectionClass($class))->getAttributes($attributeClass),
            );
        } catch (\Throwable) {
            $this->unreadable[] = $class;

            return [];
        }
    }

    /** Loading a class runs its file: a missing parent or a bad declaration must cost that class only. */
    private function reflect(string $class): ?\ReflectionClass
    {
        try {
            if (class_exists($class) || interface_exists($class)) {
                return new \ReflectionClass($class);
            }
        } catch (\Throwable) {
            $this->unreadable[] = $class;
        }

        return null;
    }

    private static function isRepository(\ReflectionClass $class): bool
    {
        return $class->getAttributes(SatisfiesRepositoryContract::class) !== [] || $class->getAttributes(AsRepository::class) !== [];
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
     * The resource models a class's code names: an import it uses, a `::class` in its
     * namespace or fully qualified, and a `model:` on its route attribute.
     *
     * @return list<string>
     */
    private function resourcesNamedIn(\ReflectionClass $class): array
    {
        $names = [];
        foreach ($class->getAttributes() as $attribute) {
            $model = $attribute->getArguments()['model'] ?? null;
            if (is_string($model)) {
                $names[] = $model;
            }
        }
        $file = $class->getFileName();
        $code = self::codeOf(is_string($file) ? (string) file_get_contents($file) : '');
        $importLine = '/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+(\w+))?;/m';
        $body = (string) preg_replace($importLine, '', $code);
        if (preg_match_all($importLine, $code, $imports, PREG_SET_ORDER) > 0) {
            foreach ($imports as $import) {
                $short = $import[2] ?? substr((string) strrchr('\\' . $import[1], '\\'), 1);
                if (preg_match('/(?<![\\\\\w$])' . preg_quote($short, '/') . '\b/', $body) === 1) {
                    $names[] = $import[1];
                }
            }
        }
        if (preg_match_all('/(?<![\\\\\w])\\\\([A-Z][\w\\\\]+)::class/', $code, $qualified) > 0) {
            array_push($names, ...$qualified[1]);
        }
        // A class of the same namespace needs no import.
        if (preg_match_all('/(?<![\\\\\w])([A-Z]\w*)::class/', $code, $local) > 0) {
            foreach ($local[1] as $short) {
                $names[] = $class->getNamespaceName() . '\\' . $short;
            }
        }

        return array_values(array_unique(array_filter(
            $names,
            fn (string $name): bool => self::isOurs($name) && $this->isResource($name),
        )));
    }

    /** The source without comments and strings: a table mentioned there is not a table the class names. */
    private static function codeOf(string $source): string
    {
        $code = '';
        foreach (\PhpToken::tokenize($source) as $token) {
            if (!$token->is([T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
                $code .= $token->text;
            }
        }

        return $code;
    }

    private function isResource(string $class): bool
    {
        $reflection = $this->reflect($class);

        return $reflection !== null && $reflection->getAttributes(FromTable::class) !== [];
    }

    /** Only framework and project classes: walking a library would load it for nothing. */
    private static function isOurs(string $class): bool
    {
        return str_starts_with($class, 'Semitexa\\') || str_starts_with($class, 'App\\');
    }

    private function tableOf(string $resource): string
    {
        $attribute = $this->attributesOf($resource, FromTable::class)[0] ?? null;

        return $attribute === null ? '' : $attribute->name;
    }
}

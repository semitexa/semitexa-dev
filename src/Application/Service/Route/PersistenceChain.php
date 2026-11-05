<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Route;

use Semitexa\Core\Attribute\AsPayloadHandler;
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

    /** @var list<string> */
    private array $stoppedAt = [];

    public function __construct(private readonly ClassDiscovery $classes)
    {
    }

    /**
     * @param list<string> $classes the route's payload and handlers
     * @return array{
     *     tables: list<array{table: string, resource: string, repositories: list<string>, mapper: ?string, domain: ?string, named_by: string}>,
     *     unreadable: list<string>,
     *     stopped_at: list<string>
     * } `unreadable` names the classes the walk could not load, and `stopped_at` those whose
     *   injections lie past its last step, so an empty `tables` is never a guess
     */
    public function of(array $classes): array
    {
        $this->unreadable = array_keys($this->classes->unloadableClasses());
        $this->stoppedAt = [];
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
                $this->unreadable[] = $class;
                continue;
            }
            $repository ??= self::isRepository($reflection) ? $class : null;

            foreach ($this->resourcesNamedIn($reflection) as $resource) {
                $found[$resource] ??= [
                    'table' => $this->tableOf($resource),
                    'resource' => $resource,
                    'repositories' => [],
                    'mapper' => $mappers[$resource]['mapper'] ?? null,
                    'domain' => $mappers[$resource]['domain'] ?? null,
                    'named_by' => $class,
                ];
                if ($repository !== null && !in_array($repository, $found[$resource]['repositories'], true)) {
                    $found[$resource]['repositories'][] = $repository;
                }
            }

            // Same step, so to the front: the queue stays in order of depth, and the
            // first visit to a class is the shortest way to it.
            $sameStep = array_map(
                static fn (string $implementation): array => [$implementation, $depth, null],
                $implementations[$class] ?? [],
            );
            foreach ($reflection->getTraitNames() as $trait) {
                $sameStep[] = [$trait, $depth, $repository];
            }
            $parent = $reflection->getParentClass();
            if ($parent !== false) {
                $sameStep[] = [$parent->getName(), $depth, $repository];
            }
            array_unshift($queue, ...$sameStep);

            $injected = array_values(array_filter(self::injectedTypes($reflection), self::isOurs(...)));
            if ($depth >= self::MAX_DEPTH && $injected !== []) {
                $this->stoppedAt[] = $class;
            } elseif ($depth < self::MAX_DEPTH) {
                foreach ($injected as $type) {
                    $queue[] = [$type, $depth + 1, null];
                }
            }
        }

        return [
            'tables' => array_values($found),
            'unreadable' => array_values(array_unique($this->unreadable)),
            'stopped_at' => array_values(array_unique($this->stoppedAt)),
        ];
    }

    /**
     * The same answer for a module: every payload handler in its namespace.
     *
     * @return array{tables: list<array<string, mixed>>, unreadable: list<string>, stopped_at: list<string>}
     */
    public function ofNamespace(string $namespace): array
    {
        return $this->of(array_values(array_filter(
            $this->classes->findClassesWithAttribute(AsPayloadHandler::class),
            static fn (string $class): bool => str_starts_with($class, $namespace . '\\'),
        )));
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
            if (class_exists($class) || interface_exists($class) || trait_exists($class)) {
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
            $factory = $property->getAttributes(InjectAsFactory::class)[0] ?? null;
            $injected = $factory !== null
                || $property->getAttributes(InjectAsReadonly::class) !== []
                || $property->getAttributes(InjectAsMutable::class) !== [];
            $type = $property->getType();
            if ($injected && $type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $types[] = $factory === null ? $type->getName() : self::contractOfFactory($factory, $type->getName());
            }
        }

        return $types;
    }

    /** A factory's type names its contract: FactoryStorageInterface is the factory of StorageInterface. */
    private static function contractOfFactory(\ReflectionAttribute $factory, string $type): string
    {
        $of = $factory->getArguments()['of'] ?? null;

        return is_string($of) ? $of : (string) preg_replace('/\\\\Factory(\w+)$/', '\\\\$1', $type);
    }

    /**
     * The resource models a class's code names, read from its tokens: a comment or a
     * string names nothing, and an import names something only where it is used.
     *
     * @return list<string>
     */
    private function resourcesNamedIn(\ReflectionClass $class): array
    {
        $names = $this->modelsOnAttributes($class);
        $file = $class->getFileName();
        $source = is_string($file) ? @file_get_contents($file) : '';
        if ($source === false) {
            $this->unreadable[] = $class->getName();

            return $names;
        }
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $token): bool => !$token->isIgnorable(),
        ));
        [$imports, $body] = self::splitImports($tokens);

        foreach ($body as $index => $token) {
            $before = $body[$index - 1] ?? null;
            $after = $body[$index + 1] ?? null;
            if ($token->is(T_NAME_FULLY_QUALIFIED)) {
                $names[] = ltrim($token->text, '\\');
            } elseif ($token->is(T_NAME_QUALIFIED) && $before?->is(T_NAMESPACE) !== true) {
                $names[] = self::resolved($token->text, $imports, $class->getNamespaceName());
            } elseif ($token->is(T_STRING) && self::canBeAClass($before, $after)) {
                $names[] = self::resolved($token->text, $imports, $class->getNamespaceName());
            }
        }

        return array_values(array_filter(
            array_unique($names),
            fn (string $name): bool => self::isOurs($name) && $this->isResource($name),
        ));
    }

    /** @return list<string> a `model:` on the class's attributes, a route's among them */
    private function modelsOnAttributes(\ReflectionClass $class): array
    {
        $models = [];
        foreach ($class->getAttributes() as $attribute) {
            try {
                $model = $attribute->getArguments()['model'] ?? null;
            } catch (\Throwable) {
                $this->unreadable[] = $class->getName();
                continue;
            }
            if (is_string($model)) {
                $models[] = $model;
            }
        }

        return $models;
    }

    /** A name in code that is not a member, a declaration or a function call. */
    private static function canBeAClass(?\PhpToken $before, ?\PhpToken $after): bool
    {
        if ($before?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_NAMESPACE]) === true) {
            return false;
        }

        return $after?->text !== '(' || $before?->is(T_NEW) === true;
    }

    /**
     * The file's imports, alias → class, and its tokens without them. Only what
     * stands before the first brace: `use` after it is a trait or a closure's.
     *
     * @param list<\PhpToken> $tokens
     * @return array{array<string, string>, list<\PhpToken>}
     */
    private static function splitImports(array $tokens): array
    {
        $imports = [];
        $body = [];
        $statement = null;
        $header = true;
        foreach ($tokens as $token) {
            if ($statement !== null) {
                if ($token->text === ';') {
                    $imports += self::importsIn($statement);
                    $statement = null;
                } else {
                    $statement[] = $token;
                }
            } elseif ($header && $token->is(T_USE)) {
                $statement = [];
            } else {
                $header = $header && $token->text !== '{';
                $body[] = $token;
            }
        }

        return [$imports, $body];
    }

    /**
     * @param list<\PhpToken> $statement what stands between `use` and `;`
     * @return array<string, string>
     */
    private static function importsIn(array $statement): array
    {
        if ($statement === [] || $statement[0]->is([T_FUNCTION, T_CONST])) {
            return [];
        }
        $text = implode(' ', array_map(static fn (\PhpToken $token): string => $token->text, $statement));
        $prefix = '';
        if (preg_match('/^(.*?)\s*\\\\\s*\{(.*)\}\s*$/s', $text, $group) === 1) {
            $prefix = $group[1] . '\\';
            $text = $group[2];
        }
        $imports = [];
        foreach (explode(',', $text) as $part) {
            $pieces = preg_split('/\s+as\s+/i', trim($part));
            $class = is_array($pieces) ? ltrim($prefix . trim($pieces[0]), '\\') : '';
            if ($class !== '' && is_array($pieces)) {
                $alias = $pieces[1] ?? substr((string) strrchr('\\' . $class, '\\'), 1);
                $imports[strtolower(trim($alias))] = $class;
            }
        }

        return $imports;
    }

    /** @param array<string, string> $imports */
    private static function resolved(string $name, array $imports, string $namespace): string
    {
        [$first, $rest] = array_pad(explode('\\', $name, 2), 2, null);
        $imported = $imports[strtolower($first)] ?? null;
        if ($imported !== null) {
            return $rest === null ? $imported : $imported . '\\' . $rest;
        }

        return $namespace === '' ? $name : $namespace . '\\' . $name;
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

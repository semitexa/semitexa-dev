<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Api;

use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Turns a class into the part of it a caller can use.
 *
 * A trait's and an abstract class's protected methods count: they are called by
 * the class that takes them on, which is how `validateRegex()` is meant to be
 * reached. A public-only view would hide exactly what was being looked for.
 */
final class ApiReflector
{
    /** Called by the engine for its own reasons; the other magic methods are how a class is used. */
    private const array ENGINE_METHODS = [
        '__construct', '__destruct', '__clone', '__debuginfo', '__serialize', '__unserialize', '__sleep', '__wakeup', '__set_state',
    ];

    private const int MAX_VALUE_LENGTH = 60;

    public function __construct(
        private readonly string $projectRoot,
    ) {}

    /**
     * @param class-string $class
     */
    public function describe(string $class): ApiClass
    {
        $reflection = new ReflectionClass($class);
        $parent = $reflection->getParentClass();
        $parameters = $this->parameters($reflection);

        return new ApiClass(
            name: $reflection->getName(),
            kind: $this->kind($reflection),
            file: $this->relativeFile($reflection),
            summary: $this->summary($reflection->getDocComment()),
            stable: preg_match('/@api\b/', (string) $reflection->getDocComment()) === 1,
            extends: $parent === false ? null : $parent->getName(),
            traits: $reflection->getTraitNames(),
            parameters: $parameters,
            members: [
                ...$this->cases($reflection),
                ...$this->constants($reflection),
                ...$this->properties($reflection, $parameters),
                ...$this->methods($reflection),
            ],
        );
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function kind(ReflectionClass $reflection): string
    {
        return match (true) {
            $reflection->isEnum() => 'enum',
            $reflection->isTrait() => 'trait',
            $reflection->isInterface() => 'interface',
            $reflection->getAttributes(\Attribute::class) !== [] => 'attribute',
            $reflection->isAbstract() => 'abstract class',
            default => 'class',
        };
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function relativeFile(ReflectionClass $reflection): string
    {
        $file = (string) $reflection->getFileName();
        $prefix = rtrim($this->projectRoot, '/') . '/';

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function reachable(ReflectionClass $reflection, ReflectionMethod $method): bool
    {
        return $method->isPublic()
            || ($method->isProtected() && ($reflection->isTrait() || $reflection->isAbstract()));
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return list<ApiParameter>
     */
    private function parameters(ReflectionClass $reflection): array
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null || !$this->reachable($reflection, $constructor)) {
            return [];
        }

        $parameters = [];
        foreach ($constructor->getParameters() as $parameter) {
            $parameters[] = new ApiParameter(
                name: $parameter->getName(),
                type: (string) $parameter->getType(),
                default: $this->defaultOf($parameter),
                summary: $this->parameterSummary($reflection, $parameter),
                cases: $this->enumCasesOf($parameter),
                variadic: $parameter->isVariadic(),
                byReference: $parameter->isPassedByReference(),
            );
        }

        return $parameters;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function parameterSummary(ReflectionClass $reflection, ReflectionParameter $parameter): string
    {
        if (!$parameter->isPromoted() || !$reflection->hasProperty($parameter->getName())) {
            return '';
        }

        return $this->summary($reflection->getProperty($parameter->getName())->getDocComment());
    }

    /**
     * Read from the parameter's printed form, not getDefaultValue(): that one
     * evaluates the expression, and a `new` default would run a constructor just
     * because somebody asked what the signature looks like.
     */
    private function defaultOf(ReflectionParameter $parameter): ?string
    {
        if (preg_match('/ = (.*) \]$/s', (string) $parameter, $match) !== 1) {
            return null;
        }

        return $match[1] === 'NULL' ? 'null' : $match[1];
    }

    /**
     * @return list<string>
     */
    private function enumCasesOf(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin() || !enum_exists($type->getName())) {
            return [];
        }

        $cases = [];
        foreach ((new ReflectionEnum($type->getName()))->getCases() as $case) {
            $cases[] = $case->getName();
        }

        return $cases;
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return list<ApiMember>
     */
    private function cases(ReflectionClass $reflection): array
    {
        if (!$reflection->isEnum()) {
            return [];
        }

        $members = [];
        $enum = new ReflectionEnum($reflection->getName());
        foreach ($enum->getCases() as $case) {
            $value = $case instanceof \ReflectionEnumBackedCase ? ' = ' . var_export($case->getBackingValue(), true) : '';
            $members[] = new ApiMember('case', $case->getName(), 'case ' . $case->getName() . $value, $this->summary($case->getDocComment()));
        }

        return $members;
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return list<ApiMember>
     */
    private function constants(ReflectionClass $reflection): array
    {
        $members = [];
        foreach ($reflection->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if ($constant->isEnumCase() || !$this->isFramework($constant->getDeclaringClass()->getName())) {
                continue;
            }
            $members[] = new ApiMember(
                'constant',
                $constant->getName(),
                $this->constantSignature($constant),
                $this->summary($constant->getDocComment()),
                $this->fromOther($reflection, $constant->getDeclaringClass()->getName()),
            );
        }

        return $members;
    }

    private function constantSignature(\ReflectionClassConstant $constant): string
    {
        $signature = 'const ' . $constant->getName();
        try {
            $value = $constant->getValue();
        } catch (\Throwable) {
            return $signature; // Its expression needs a class that is not installed.
        }
        if (!is_scalar($value)) {
            return $signature;
        }

        $printed = var_export($value, true);

        return $signature . ' = ' . (strlen($printed) > self::MAX_VALUE_LENGTH ? substr($printed, 0, self::MAX_VALUE_LENGTH) . '…' : $printed);
    }

    /**
     * A promoted property is only left out when the constructor on show is the
     * one that promotes it: a private constructor, or a child's constructor that
     * merely reuses the name, leaves the property as the only way to learn of it.
     *
     * @param ReflectionClass<object> $reflection
     * @param list<ApiParameter>      $parameters
     *
     * @return list<ApiMember>
     */
    private function properties(ReflectionClass $reflection, array $parameters): array
    {
        if ($reflection->isEnum()) {
            return []; // name and value belong to the engine; the cases say it all.
        }

        $promotedBy = $parameters === [] ? null : $reflection->getConstructor()?->getDeclaringClass()->getName();

        $members = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $declaring = $property->getDeclaringClass();
            if (($property->isPromoted() && $declaring->getName() === $promotedBy) || !$this->isFramework($declaring->getName())) {
                continue;
            }
            $members[] = new ApiMember(
                'property',
                $property->getName(),
                $this->propertySignature($property),
                $this->summary($property->getDocComment()),
                $this->traitWith($declaring, static fn (ReflectionClass $trait): bool => $trait->hasProperty($property->getName()))
                    ?? $this->fromOther($reflection, $declaring->getName()),
            );
        }

        return $members;
    }

    private function propertySignature(ReflectionProperty $property): string
    {
        // readonly is implicitly protected(set); saying so again adds nothing.
        $write = match (true) {
            $property->isReadOnly() => 'readonly ',
            $property->isPrivateSet() => 'private(set) ',
            $property->isProtectedSet() => 'protected(set) ',
            default => '',
        };

        return trim(($property->isStatic() ? 'static ' : '') . $write . $property->getType() . ' $' . $property->getName());
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return list<ApiMember>
     */
    private function methods(ReflectionClass $reflection): array
    {
        $members = [];
        foreach ($reflection->getMethods() as $method) {
            if (
                !$this->reachable($reflection, $method)
                || $method->isInternal()
                || in_array(strtolower($method->getName()), self::ENGINE_METHODS, true)
                || !$this->isFramework($method->getDeclaringClass()->getName())
            ) {
                continue;
            }
            $members[] = new ApiMember(
                'method',
                $method->getName(),
                $this->methodSignature($method),
                $this->summary($method->getDocComment()),
                $this->traitWith($method->getDeclaringClass(), fn (ReflectionClass $trait): bool => $this->declaresSame($trait, $method))
                    ?? $this->fromOther($reflection, $method->getDeclaringClass()->getName()),
            );
        }

        return $members;
    }

    private function methodSignature(ReflectionMethod $method): string
    {
        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $default = $this->defaultOf($parameter);
            $parameters[] = trim(
                (string) $parameter->getType()
                . ' ' . ($parameter->isPassedByReference() ? '&' : '')
                . ($parameter->isVariadic() ? '...' : '')
                . '$' . $parameter->getName(),
            ) . ($default === null ? '' : ' = ' . $default);
        }

        $abstract = $method->isAbstract() && !$method->getDeclaringClass()->isInterface();
        $modifiers = ($abstract ? 'abstract ' : '') . ($method->isProtected() ? 'protected ' : '') . ($method->isStatic() ? 'static ' : '');
        $returns = $method->hasReturnType() ? ': ' . $method->getReturnType() : '';

        return $modifiers . ($method->returnsReference() ? '&' : '') . $method->getName() . '(' . implode(', ', $parameters) . ')' . $returns;
    }

    /**
     * The same file is not enough: a class and the trait it uses can share one,
     * and the class's own methods would then be credited to the trait and vanish
     * from search. Compared by position, not name: `foo as bar` has no `bar` in
     * the trait.
     *
     * @param ReflectionClass<object> $trait
     */
    private function declaresSame(ReflectionClass $trait, ReflectionMethod $method): bool
    {
        foreach ($trait->getMethods() as $declared) {
            if ($declared->getFileName() === $method->getFileName() && $declared->getStartLine() === $method->getStartLine()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function fromOther(ReflectionClass $reflection, string $declaring): ?string
    {
        return $declaring === $reflection->getName() ? null : $declaring;
    }

    /**
     * Reflection reports a trait's member as the using class's own. Copies of it
     * would fill a search with one method, so the trait is named as its source.
     *
     * @param ReflectionClass<object>                  $class
     * @param callable(ReflectionClass<object>): bool $declares
     */
    private function traitWith(ReflectionClass $class, callable $declares): ?string
    {
        foreach ($class->getTraits() as $trait) {
            if ($declares($trait)) {
                return $this->traitWith($trait, $declares) ?? $trait->getName();
            }
        }

        return null;
    }

    /** Symfony's Command brings forty methods nobody came here for. */
    private function isFramework(string $class): bool
    {
        return str_starts_with($class, 'Semitexa\\');
    }

    private function summary(string|false $doc): string
    {
        if ($doc === false) {
            return '';
        }

        $deprecated = preg_match('/@deprecated\b/', $doc) === 1 ? 'Deprecated. ' : '';

        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $text = trim((string) preg_replace('~^\s*/?\*+/?|\*+/\s*$~', '', $line));
            if ($text === '') {
                continue;
            }

            return trim($deprecated . (str_starts_with($text, '@') ? '' : $text));
        }

        return trim($deprecated);
    }
}

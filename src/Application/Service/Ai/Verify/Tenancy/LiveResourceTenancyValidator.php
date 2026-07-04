<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Tenancy;

use Semitexa\Core\Discovery\ClassDiscovery;

/**
 * The live-tenancy guard (ai:verify target `live_tenancy`).
 *
 * Joins the two sides of the live-grid wire that nothing else joins:
 * watchers (`#[WatchScopes]` payloads, `ExposeAsGraphql(watchScopes:)`
 * operations) and publishers (resources, whose scope key is
 * `#[ResourceKey]` or the `#[FromTable]` name). Two defect shapes:
 *
 *  - a watched resource with neither `#[TenantScoped]` nor
 *    `#[TenantExempt]` — its re-run reads every tenant's rows
 *    (the cross-tenant leak from var/docs/live-grid-tenancy-leak-audit.md);
 *  - a watched scope no resource publishes — a dead live wire
 *    (grid claims to be live, never re-runs).
 *
 * Attribute names are matched as strings so this validator needs no
 * compile-time dependency on orm/graphql; reflection lists attributes by
 * name whether or not their classes are loadable.
 */
final class LiveResourceTenancyValidator
{
    private const ATTR_WATCH_SCOPES   = 'Semitexa\Core\Attribute\WatchScopes';
    private const ATTR_EXPOSE_GRAPHQL = 'Semitexa\Graphql\Attribute\ExposeAsGraphql';
    private const ATTR_FROM_TABLE     = 'Semitexa\Orm\Attribute\FromTable';
    private const ATTR_RESOURCE_KEY   = 'Semitexa\Orm\Attribute\ResourceKey';
    private const ATTR_TENANT_SCOPED  = 'Semitexa\Orm\Attribute\TenantScoped';
    private const ATTR_TENANT_EXEMPT  = 'Semitexa\Orm\Attribute\TenantExempt';

    /** @return list<LiveTenancyViolation> */
    public function validateProject(ClassDiscovery $discovery): array
    {
        $watcherClasses = array_unique(array_merge(
            $discovery->findClassesWithAttribute(self::ATTR_WATCH_SCOPES),
            $discovery->findClassesWithAttribute(self::ATTR_EXPOSE_GRAPHQL),
        ));

        return $this->validateClasses(
            $watcherClasses,
            $discovery->findClassesWithAttribute(self::ATTR_FROM_TABLE),
        );
    }

    /**
     * Pure core: join the given watcher and resource classes by scope key.
     *
     * @param iterable<class-string> $watcherClasses
     * @param iterable<class-string> $resourceClasses
     * @return list<LiveTenancyViolation>
     */
    public function validateClasses(iterable $watcherClasses, iterable $resourceClasses): array
    {
        /** @var array<string, list<class-string>> $watched scope key => watcher classes */
        $watched = [];
        foreach ($watcherClasses as $class) {
            foreach ($this->watchedScopesOf($class) as $scopeKey) {
                $watched[$scopeKey][] = $class;
            }
        }

        if ($watched === []) {
            return [];
        }

        /** @var array<string, class-string> $resourcesByKey */
        $resourcesByKey = [];
        /** @var array<class-string, bool> $tenancyDeclared */
        $tenancyDeclared = [];
        foreach ($resourceClasses as $class) {
            $reflection = new \ReflectionClass($class);
            $key = $this->scopeKeyOf($reflection);
            if ($key === null) {
                continue;
            }
            $resourcesByKey[$key] = $class;
            $tenancyDeclared[$class] = $this->hasAttribute($reflection, self::ATTR_TENANT_SCOPED)
                || $this->hasAttribute($reflection, self::ATTR_TENANT_EXEMPT);
        }

        $violations = [];
        foreach ($watched as $scopeKey => $watchers) {
            $watchers = array_values(array_unique($watchers));
            $resource = $resourcesByKey[$scopeKey] ?? null;

            if ($resource === null) {
                $violations[] = new LiveTenancyViolation(
                    code: LiveTenancyViolation::CODE_UNBACKED,
                    scopeKey: $scopeKey,
                    watchers: $watchers,
                );
                continue;
            }

            if (!$tenancyDeclared[$resource]) {
                $violations[] = new LiveTenancyViolation(
                    code: LiveTenancyViolation::CODE_UNTENANTED,
                    scopeKey: $scopeKey,
                    watchers: $watchers,
                    resourceClass: $resource,
                );
            }
        }

        return $violations;
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private function watchedScopesOf(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $scopes = [];

        foreach ($reflection->getAttributes() as $attribute) {
            if ($attribute->getName() === self::ATTR_WATCH_SCOPES) {
                // Variadic string constructor: every argument is a scope key.
                foreach ($attribute->getArguments() as $argument) {
                    if (is_string($argument)) {
                        $scopes[] = $argument;
                    }
                }
            }

            if ($attribute->getName() === self::ATTR_EXPOSE_GRAPHQL) {
                $watchScopes = $attribute->getArguments()['watchScopes'] ?? [];
                foreach (is_array($watchScopes) ? $watchScopes : [] as $scope) {
                    if (is_string($scope)) {
                        $scopes[] = $scope;
                    }
                }
            }
        }

        return $scopes;
    }

    /** The key this resource publishes under: #[ResourceKey] or the table name. */
    private function scopeKeyOf(\ReflectionClass $reflection): ?string
    {
        $table = null;
        foreach ($reflection->getAttributes() as $attribute) {
            if ($attribute->getName() === self::ATTR_RESOURCE_KEY) {
                $arguments = $attribute->getArguments();
                $key = $arguments['key'] ?? $arguments[0] ?? null;
                if (is_string($key) && $key !== '') {
                    return $key;
                }
            }
            if ($attribute->getName() === self::ATTR_FROM_TABLE) {
                $arguments = $attribute->getArguments();
                $name = $arguments['name'] ?? $arguments[0] ?? null;
                if (is_string($name) && $name !== '') {
                    $table = $name;
                }
            }
        }

        return $table;
    }

    private function hasAttribute(\ReflectionClass $reflection, string $attributeName): bool
    {
        return $reflection->getAttributes($attributeName) !== [];
    }
}

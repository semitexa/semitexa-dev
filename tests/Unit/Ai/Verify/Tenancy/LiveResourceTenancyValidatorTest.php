<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Tenancy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Tenancy\LiveResourceTenancyValidator;
use Semitexa\Dev\Application\Service\Ai\Verify\Tenancy\LiveTenancyViolation;

/*
 * Fixtures use fully-qualified attribute names on purpose: the validator
 * matches attributes AS STRINGS (never instantiates them), which is exactly
 * how it stays free of orm/graphql compile-time dependencies. The fixture
 * attributes therefore do not need to be loadable classes either.
 */

#[\Semitexa\Core\Attribute\WatchScopes('fixture_tenanted', 'fixture_exempt')]
final class FixtureWatchingFeedPayload
{
}

#[\Semitexa\Core\Attribute\WatchScopes('fixture_naked')]
final class FixtureNakedFeedPayload
{
}

#[\Semitexa\Core\Attribute\WatchScopes('fixture_ghost_scope')]
final class FixtureGhostFeedPayload
{
}

#[\Semitexa\Graphql\Attribute\ExposeAsGraphql(watchScopes: ['fixture_naked'])]
final class FixtureGraphqlQueryPayload
{
}

#[\Semitexa\Orm\Attribute\FromTable(name: 'fixture_tenanted')]
#[\Semitexa\Orm\Attribute\TenantScoped(strategy: 'same_storage', column: 'tenant_id')]
final class FixtureTenantedResource
{
}

#[\Semitexa\Orm\Attribute\FromTable(name: 'fixture_exempt_table')]
#[\Semitexa\Orm\Attribute\ResourceKey('fixture_exempt')]
#[\Semitexa\Orm\Attribute\TenantExempt(reason: 'fixture')]
final class FixtureExemptResource
{
}

#[\Semitexa\Orm\Attribute\FromTable(name: 'fixture_naked')]
final class FixtureNakedResource
{
}

#[\Semitexa\Orm\Attribute\FromTable(name: 'fixture_unwatched')]
final class FixtureUnwatchedResource
{
}

final class LiveResourceTenancyValidatorTest extends TestCase
{
    private LiveResourceTenancyValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new LiveResourceTenancyValidator();
    }

    #[Test]
    public function tenanted_and_exempt_live_resources_pass(): void
    {
        $violations = $this->validator->validateClasses(
            [FixtureWatchingFeedPayload::class],
            [FixtureTenantedResource::class, FixtureExemptResource::class],
        );

        self::assertSame([], $violations);
    }

    #[Test]
    public function resource_key_overrides_the_table_name_for_the_join(): void
    {
        // fixture_exempt is published under #[ResourceKey], not the table name;
        // if the join used the table, this would be a false "unbacked" hit.
        $violations = $this->validator->validateClasses(
            [FixtureWatchingFeedPayload::class],
            [FixtureTenantedResource::class, FixtureExemptResource::class],
        );

        self::assertSame([], $violations);
    }

    #[Test]
    public function live_bound_resource_without_tenancy_posture_is_reported(): void
    {
        $violations = $this->validator->validateClasses(
            [FixtureNakedFeedPayload::class],
            [FixtureNakedResource::class],
        );

        self::assertCount(1, $violations);
        self::assertSame(LiveTenancyViolation::CODE_UNTENANTED, $violations[0]->code);
        self::assertSame('fixture_naked', $violations[0]->scopeKey);
        self::assertSame(FixtureNakedResource::class, $violations[0]->resourceClass);
        self::assertSame([FixtureNakedFeedPayload::class], $violations[0]->watchers);
    }

    #[Test]
    public function graphql_watch_scopes_bind_the_same_guard(): void
    {
        $violations = $this->validator->validateClasses(
            [FixtureGraphqlQueryPayload::class],
            [FixtureNakedResource::class],
        );

        self::assertCount(1, $violations);
        self::assertSame(LiveTenancyViolation::CODE_UNTENANTED, $violations[0]->code);
        self::assertSame([FixtureGraphqlQueryPayload::class], $violations[0]->watchers);
    }

    #[Test]
    public function watched_scope_with_no_backing_resource_is_a_dead_wire(): void
    {
        $violations = $this->validator->validateClasses(
            [FixtureGhostFeedPayload::class],
            [FixtureTenantedResource::class],
        );

        self::assertCount(1, $violations);
        self::assertSame(LiveTenancyViolation::CODE_UNBACKED, $violations[0]->code);
        self::assertSame('fixture_ghost_scope', $violations[0]->scopeKey);
    }

    #[Test]
    public function unwatched_resources_are_out_of_scope(): void
    {
        $violations = $this->validator->validateClasses(
            [FixtureWatchingFeedPayload::class],
            [FixtureTenantedResource::class, FixtureExemptResource::class, FixtureUnwatchedResource::class],
        );

        self::assertSame([], $violations, 'A resource nobody watches needs no tenancy declaration.');
    }

    #[Test]
    public function duplicate_watchers_are_collapsed_in_the_report(): void
    {
        $violations = $this->validator->validateClasses(
            [FixtureNakedFeedPayload::class, FixtureNakedFeedPayload::class, FixtureGraphqlQueryPayload::class],
            [FixtureNakedResource::class],
        );

        self::assertCount(1, $violations);
        self::assertSame(
            [FixtureNakedFeedPayload::class, FixtureGraphqlQueryPayload::class],
            $violations[0]->watchers,
        );
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Quality\RequestCostProbe;

/**
 * A worker-local settings snapshot refills on whichever request comes after
 * its 2 s TTL; that read is a clock's cost, not a page's, and counting it made
 * the release quality gate fail on random routes.
 */
final class RequestCostProbeCacheRefillTest extends TestCase
{
    #[Test]
    public function the_settings_snapshot_read_is_a_cache_refill(): void
    {
        self::assertTrue(RequestCostProbe::isWorkerCacheRefill(
            'SELECT * FROM `platform_settings` WHERE `tenant_id` = :tenant_scope AND `module_key` = :w0 AND `user_id` IS NULL',
        ));
        self::assertTrue(RequestCostProbe::isWorkerCacheRefill('select * from platform_settings where module_key = ?'));
    }

    #[Test]
    public function a_page_s_own_queries_still_count(): void
    {
        self::assertFalse(RequestCostProbe::isWorkerCacheRefill('SELECT * FROM `demo_products` ORDER BY `name` ASC'));
        self::assertFalse(RequestCostProbe::isWorkerCacheRefill('SELECT * FROM `platform_settings_history` WHERE 1'));
        self::assertFalse(RequestCostProbe::isWorkerCacheRefill('UPDATE `platform_settings` SET `value` = ?'));
    }
}

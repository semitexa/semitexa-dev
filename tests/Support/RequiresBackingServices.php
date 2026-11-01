<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Support;

use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Environment;
use Semitexa\Orm\OrmManager;

/**
 * The real MySQL and Redis an integration test is about.
 *
 * Not configured (DB_HOST or REDIS_HOST unset, read as the application reads
 * it, process environment or .env: the suite run outside the stack) is a
 * skip. Configured and not answering is a failure: a skip there
 * left a stack that could not reach its database green over everything the
 * test exists to prove.
 */
trait RequiresBackingServices
{
    /**
     * The ORM, resolved before the probe: a missing binding is a wiring
     * failure of its own, not a database that does not answer.
     */
    private static function mysqlOrm(): OrmManager
    {
        // Environment, not getenv(): a DB_HOST only in .env is configured,
        // and getenv() alone took it for "not configured" and skipped.
        $host = Environment::getEnvValue('DB_HOST');
        if ($host === null || $host === '') {
            self::markTestSkipped('DB_HOST not configured — the test requires real MySQL');
        }
        $orm = ContainerFactory::get()->get(OrmManager::class);
        try {
            $orm->getAdapter()->execute('SELECT 1');
        } catch (\Throwable $e) {
            self::fail("DB_HOST={$host} is configured, but MySQL does not answer: " . $e->getMessage());
        }

        return $orm;
    }

    private static function redisHost(): string
    {
        $host = Environment::getEnvValue('REDIS_HOST');
        if ($host === null || $host === '') {
            self::markTestSkipped('REDIS_HOST not configured — the test requires real Redis');
        }

        return $host;
    }

    /** @param callable(): mixed $probe */
    private static function redisAnswers(string $host, callable $probe): void
    {
        try {
            $probe();
        } catch (\Throwable $e) {
            self::fail("REDIS_HOST={$host} is configured, but Redis does not answer: " . $e->getMessage());
        }
    }
}

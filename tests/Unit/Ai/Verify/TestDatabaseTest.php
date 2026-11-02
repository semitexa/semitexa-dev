<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\RecordingProcessRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\TestDatabase;
use Semitexa\Dev\Tests\Unit\Ai\Verify\Fixture\FixedProcessRunner;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

/**
 * ai:verify's PHPUnit targets run against the test database test:run uses,
 * never the developer's (bench-verify-uses-dev-db).
 */
final class TestDatabaseTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-testdb-' . uniqid('', true);
        mkdir($this->root . '/vendor/bin', 0777, true);
        file_put_contents($this->root . '/vendor/bin/semitexa', "<?php\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private static function withOrm(): Application
    {
        $app = new Application();
        $app->add(new Command('orm:sync'));

        return $app;
    }

    #[Test]
    public function a_phpunit_command_runs_against_the_test_database_after_one_schema_sync(): void
    {
        $runner = new RecordingProcessRunner(new FixedProcessRunner(['exit' => 0, 'output' => 'Schema in sync']));
        $db = new TestDatabase(self::withOrm(), $this->root, $runner, liveDatabase: 'semitexa', testDatabase: 'semitexa_test');

        self::assertSame(['env', 'DB_DATABASE=semitexa_test', 'APP_ENV=test', 'vendor/bin/phpunit', 'tests'], $db->wrap(['vendor/bin/phpunit', 'tests']));
        self::assertSame(['env', 'DB_DATABASE=semitexa_test', 'APP_ENV=test', 'X=1', 'vendor/bin/phpunit'], $db->wrap(['env', 'X=1', 'vendor/bin/phpunit']), 'an env prefix already there is kept');

        $calls = array_map(static fn (array $call): array => $call['argv'], $runner->calls());
        self::assertCount(1, $calls, 'the schema is synced once per run, not per target');
        self::assertSame(['env', 'DB_DATABASE=semitexa_test', 'APP_ENV=test', PHP_BINARY, $this->root . '/vendor/bin/semitexa', 'orm:sync'], $calls[0]);
    }

    #[Test]
    public function the_same_name_for_both_databases_is_refused_before_anything_runs(): void
    {
        $runner = new RecordingProcessRunner(new FixedProcessRunner(['exit' => 0, 'output' => '']));
        $refusal = (new TestDatabase(self::withOrm(), $this->root, $runner, liveDatabase: 'shop', testDatabase: 'shop'))->wrap(['vendor/bin/phpunit']);

        self::assertIsString($refusal);
        self::assertStringContainsString("both 'shop'", $refusal);
        self::assertSame([], $runner->calls());
    }

    #[Test]
    public function a_test_database_that_cannot_be_synced_stops_the_targets_and_says_how_to_create_it(): void
    {
        $runner = new RecordingProcessRunner(new FixedProcessRunner(['exit' => 1, 'output' => "SQLSTATE[HY000] [1044] Access denied for user to database 'semitexa_test'"]));
        $refusal = (new TestDatabase(self::withOrm(), $this->root, $runner, liveDatabase: 'semitexa', testDatabase: 'semitexa_test'))->wrap(['vendor/bin/phpunit']);

        self::assertIsString($refusal);
        self::assertStringContainsString("'semitexa_test' could not be synced", $refusal);
        self::assertStringContainsString('Access denied', $refusal);
        self::assertStringContainsString('bin/semitexa test:run', $refusal);
    }

    #[Test]
    public function an_installed_orm_without_its_console_stops_the_targets_instead_of_skipping_the_sync(): void
    {
        unlink($this->root . '/vendor/bin/semitexa');
        $runner = new RecordingProcessRunner(new FixedProcessRunner(['exit' => 0, 'output' => '']));
        $refusal = (new TestDatabase(self::withOrm(), $this->root, $runner, liveDatabase: 'semitexa', testDatabase: 'semitexa_test'))->wrap(['vendor/bin/phpunit']);

        self::assertIsString($refusal);
        self::assertStringContainsString('cannot be synced', $refusal);
        self::assertSame([], $runner->calls());
    }

    /** No ORM, no schema to sync: the database name still goes to the test one. */
    #[Test]
    public function without_the_orm_nothing_is_synced_and_the_test_database_is_still_named(): void
    {
        $runner = new RecordingProcessRunner(new FixedProcessRunner(['exit' => 0, 'output' => '']));
        $command = (new TestDatabase(new Application(), $this->root, $runner, liveDatabase: 'semitexa', testDatabase: 'semitexa_test'))->wrap(['vendor/bin/phpunit']);

        self::assertSame(['env', 'DB_DATABASE=semitexa_test', 'APP_ENV=test', 'vendor/bin/phpunit'], $command);
        self::assertSame([], $runner->calls());
    }
}

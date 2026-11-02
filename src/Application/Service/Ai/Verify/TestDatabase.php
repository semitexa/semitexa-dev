<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

use Semitexa\Core\Environment;
use Symfony\Component\Console\Application;

/**
 * The database ai:verify's PHPUnit targets run against: the test database
 * `bin/semitexa test:run` uses, never the one the developer works in.
 *
 * Found by the delivery benchmark (bench-verify-uses-dev-db): ai:verify ran
 * `vendor/bin/phpunit` with the app's .env, so its tests read and wrote
 * DB_DATABASE, while test:run runs them against DB_TEST_DATABASE (default
 * semitexa_test) after an orm:sync of that schema. After a schema change
 * ai:verify reported failures test:run did not have, until the agent ran
 * orm:sync on the dev database.
 *
 * So this does what test:run does, once per run: it refuses when the two
 * names are the same (tests create and drop tables), syncs the test schema
 * with safe operations only (when the ORM is installed), and hands every
 * PHPUnit command DB_DATABASE=<test database> and APP_ENV=test. A test
 * database that cannot be synced (not created yet, no grant) stops the
 * PHPUnit targets with the reason; they never fall back to the dev database.
 */
final class TestDatabase
{
    /** @var list<string>|string|null the env assignments once ready, the refusal, or not asked yet */
    private array|string|null $prepared = null;

    public function __construct(
        private readonly Application $application,
        private readonly string $projectRoot,
        private readonly ProcessRunner $processRunner,
        private readonly ?string $liveDatabase = null,
        private readonly ?string $testDatabase = null,
    ) {}

    /**
     * The PHPUnit command, run against the test database; or why it cannot be.
     *
     * @param list<string> $command
     * @return list<string>|string
     */
    public function wrap(array $command): array|string
    {
        $env = $this->prepare();
        if (is_string($env) || $env === []) {
            return $env === [] ? $command : $env;
        }

        return ($command[0] ?? null) === 'env' ? ['env', ...$env, ...array_slice($command, 1)] : ['env', ...$env, ...$command];
    }

    /** @return list<string>|string */
    private function prepare(): array|string
    {
        if ($this->prepared !== null) {
            return $this->prepared;
        }
        // Already the test process (test:run's phpunit service, or a PHPUnit
        // target of an outer ai:verify): its DB_DATABASE is the test database.
        if ($this->liveDatabase === null && Environment::getEnvValue('APP_ENV') === 'test') {
            return $this->prepared = [];
        }
        $live = $this->liveDatabase ?? (string) Environment::getEnvValue('DB_DATABASE', '');
        $test = $this->testDatabase ?? (string) Environment::getEnvValue('DB_TEST_DATABASE', 'semitexa_test');
        if ($test === '' || $test === $live) {
            return $this->prepared = sprintf(
                "DB_TEST_DATABASE and DB_DATABASE are both '%s': tests create and drop tables, so they do not run against the database you work in. Set DB_TEST_DATABASE to its own name (the default is semitexa_test).",
                $live,
            );
        }
        $env = ['DB_DATABASE=' . $test, 'APP_ENV=test'];

        $console = $this->projectRoot . '/vendor/bin/semitexa';
        if ($this->application->has('orm:sync') && is_file($console)) {
            $sync = $this->processRunner->run(['env', ...$env, PHP_BINARY, $console, 'orm:sync'], $this->projectRoot);
            if ($sync['exit'] !== 0 || isset($sync['failure'])) {
                return $this->prepared = sprintf(
                    "the test database '%s' could not be synced (orm:sync exit %d: %s). Run bin/semitexa test:run once: it creates the test database and its grant.",
                    $test,
                    $sync['exit'],
                    SignalText::lastLine($sync['output']),
                );
            }
        }

        return $this->prepared = $env;
    }
}

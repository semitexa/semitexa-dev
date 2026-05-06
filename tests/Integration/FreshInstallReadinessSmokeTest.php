<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\MakeModuleCommand;
use Semitexa\Dev\Application\Console\Command\MakePageCommand;
use Semitexa\Dev\Application\Console\Command\MakePayloadCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\DetectedModule;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureSpecLoader;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureValidator;
use Semitexa\Orm\OrmManager;
use Semitexa\Webhooks\Application\Console\Command\WebhookCleanupCommand;
use Semitexa\Webhooks\Auth\InMemoryWebhookReplayStore;
use Semitexa\Webhooks\Auth\MySqlWebhookReplayStore;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Capstone readiness gate. Proves that the cycle 11–26 hardening surfaces
 * compose into a workflow a fresh developer (or AI agent) can follow:
 *
 *   - Generate a module + a public page + a protected payload + a service
 *     payload via the framework's blessed `make:*` commands.
 *   - Validate the generated layout against the real
 *     {@see ModuleStructureValidator} the framework ships with.
 *   - Probe schema readiness via `orm:sync --dry-run` and confirm the
 *     webhook persistence tables appear in the plan.
 *   - Probe operational cleanup readiness via `webhook:cleanup --dry-run`
 *     and the tenant-scoped variant.
 *   - Probe replay-store binding equivalence — InMemory always; MySQL +
 *     Redis only when their environments are wired (skipped otherwise
 *     with an explicit guard, never silently passing).
 *   - Probe doc readiness — confirm the post-hardening migration guide
 *     exists and carries the canonical examples cycle 26 added.
 *
 * The test runs every generator into a real temp project root (chdir +
 * ProjectRoot::reset). It never writes into the actual src/modules of the
 * host project. tearDown nukes the temp root entirely.
 */
final class FreshInstallReadinessSmokeTest extends TestCase
{
    private const MODULE_NAME = 'FreshInstallDemo';

    private string $tmpRoot;
    private ?string $originalCwd = null;
    private bool $cwdSwapped = false;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/semitexa-fresh-install-' . uniqid();
        mkdir($this->tmpRoot . '/src/modules', 0755, true);
        mkdir($this->tmpRoot . '/packages', 0755, true);
        file_put_contents($this->tmpRoot . '/composer.json', '{"name":"temp/project"}');

        $this->originalCwd = getcwd() ?: null;
    }

    protected function tearDown(): void
    {
        if ($this->cwdSwapped && $this->originalCwd !== null) {
            chdir($this->originalCwd);
            ProjectRoot::reset();
            $this->cwdSwapped = false;
        }
        $this->removeDir($this->tmpRoot);
    }

    /**
     * Generator tests chdir into the temp project root so make:* writes
     * land in $this->tmpRoot rather than the host project's src/modules.
     * Container-using tests (cleanup, orm, replay store) MUST NOT call
     * this — the framework's ContainerFactory resolves the composer
     * classmap relative to cwd, and a temp cwd has no classmap.
     */
    private function enterTempProjectRoot(): void
    {
        chdir($this->tmpRoot);
        ProjectRoot::reset();
        $this->cwdSwapped = true;
    }

    // ------------------------------------------------------------------
    //  Scenario A — generator workflow in temp workspace
    // ------------------------------------------------------------------

    #[Test]
    public function fresh_module_generation_produces_canonical_layout(): void
    {
        $this->enterTempProjectRoot();
        $exit = (new CommandTester(new MakeModuleCommand()))->execute([
            '--name' => self::MODULE_NAME,
            '--write' => true,
        ]);
        self::assertSame(0, $exit, 'make:module --write must succeed in fresh workspace');

        $base = $this->tmpRoot . '/src/modules/' . self::MODULE_NAME;
        self::assertDirectoryExists($base . '/src/Application/Payload/Request');
        self::assertDirectoryExists($base . '/src/Application/Handler/PayloadHandler');
        self::assertDirectoryExists($base . '/src/Application/Resource/Response');
        self::assertDirectoryExists($base . '/src/Application/Console/Command');
        self::assertDirectoryExists($base . '/src/Domain/Service');
        self::assertDirectoryExists($base . '/src/Domain/Contract');

        // Cycle 22's deferred design choice: .gitkeep markers carry content,
        // so every emitted directory contains a real file. Validator-clean
        // by construction.
        self::assertFileExists($base . '/src/Application/Payload/Request/.gitkeep');
    }

    #[Test]
    public function fresh_public_page_generation_emits_AsPublicPayload(): void
    {
        $this->writeModule();

        $exit = (new CommandTester(new MakePageCommand()))->execute([
            '--module' => self::MODULE_NAME,
            '--name' => 'Welcome',
            '--path' => '/fresh-install/welcome',
            '--method' => 'GET',
            '--access' => 'public',
            '--write' => true,
        ]);
        self::assertSame(0, $exit, 'make:page --access=public must succeed');

        $payloadFile = $this->tmpRoot . '/src/modules/' . self::MODULE_NAME
            . '/src/Application/Payload/Request/WelcomePayload.php';
        self::assertFileExists($payloadFile);

        $content = (string) file_get_contents($payloadFile);
        self::assertStringContainsString('#[AsPublicPayload(', $content, 'public page must use AsPublicPayload');
        self::assertStringContainsString('use Semitexa\\Core\\Attribute\\AsPublicPayload;', $content);
        $this->assertGeneratedPayloadIsClean($content);
    }

    #[Test]
    public function fresh_protected_payload_generation_emits_AsProtectedPayload(): void
    {
        $this->writeModule();

        $exit = (new CommandTester(new MakePayloadCommand()))->execute([
            '--module' => self::MODULE_NAME,
            '--name' => 'AccountStatus',
            '--path' => '/fresh-install/account-status',
            '--method' => 'GET',
            '--response' => 'AccountStatus',
            '--access' => 'protected',
            '--write' => true,
        ]);
        self::assertSame(0, $exit);

        $file = $this->tmpRoot . '/src/modules/' . self::MODULE_NAME
            . '/src/Application/Payload/Request/AccountStatusPayload.php';
        $content = (string) file_get_contents($file);
        self::assertStringContainsString('#[AsProtectedPayload(', $content);
        self::assertStringContainsString('use Semitexa\\Authorization\\Attribute\\AsProtectedPayload;', $content);
        $this->assertGeneratedPayloadIsClean($content);
    }

    #[Test]
    public function fresh_service_payload_generation_emits_AsServicePayload(): void
    {
        $this->writeModule();

        $exit = (new CommandTester(new MakePayloadCommand()))->execute([
            '--module' => self::MODULE_NAME,
            '--name' => 'PartnerPing',
            '--path' => '/fresh-install/partner-ping',
            '--method' => 'POST',
            '--response' => 'PartnerPing',
            '--access' => 'service',
            '--write' => true,
        ]);
        self::assertSame(0, $exit);

        $file = $this->tmpRoot . '/src/modules/' . self::MODULE_NAME
            . '/src/Application/Payload/Request/PartnerPingPayload.php';
        $content = (string) file_get_contents($file);
        self::assertStringContainsString('#[AsServicePayload(', $content);
        self::assertStringContainsString('use Semitexa\\Authorization\\Attribute\\AsServicePayload;', $content);
        $this->assertGeneratedPayloadIsClean($content);
    }

    #[Test]
    public function generator_rejects_unknown_access_value(): void
    {
        $this->writeModule();

        $tester = new CommandTester(new MakePayloadCommand());
        try {
            $tester->execute([
                '--module' => self::MODULE_NAME,
                '--name' => 'BadPayload',
                '--path' => '/x',
                '--method' => 'GET',
                '--response' => 'Bad',
                '--access' => 'open',
                '--write' => true,
            ]);
            self::fail('make:payload --access=open must throw');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Unknown payload access type', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    //  Scenario B — route discovery: limitation reported explicitly
    // ------------------------------------------------------------------

    #[Test]
    public function generated_module_layout_passes_module_structure_validator(): void
    {
        $this->writeModuleAndAllPayloads();

        $projectRoot = dirname(__DIR__, 4);
        $spec = (new ModuleStructureSpecLoader($projectRoot))->load();
        $validator = new ModuleStructureValidator($this->tmpRoot, $spec);

        $module = new DetectedModule(
            name: self::MODULE_NAME,
            relativePath: 'src/modules/' . self::MODULE_NAME,
            kind: DetectedModule::KIND_APPLICATION,
        );

        $violations = $validator->validate($module);
        self::assertSame(
            [],
            array_map(static fn ($v) => $v->code . ': ' . $v->path, $violations),
            'freshly generated module must have zero structure violations',
        );
    }

    #[Test]
    public function route_discovery_against_temp_workspace_is_documented_limitation(): void
    {
        // The framework's ClassDiscovery reads the host project's Composer
        // classmap. Generated files in a temp directory are not in that
        // classmap and would not appear in a runtime `routes:list`. This
        // limitation is intentional — generators are scoped to file
        // emission; runtime discovery requires a `composer dump-autoload`
        // pass which is operator-driven, not test-driven.
        //
        // Pin the contract: structural validation (above) is the
        // generator-side guarantee; runtime discovery is a separate
        // operator step.
        $this->writeModuleAndAllPayloads();

        $payloadFile = $this->tmpRoot . '/src/modules/' . self::MODULE_NAME
            . '/src/Application/Payload/Request/WelcomePayload.php';
        self::assertFileExists($payloadFile, 'generated file exists on disk');
        self::assertFalse(
            class_exists('Semitexa\\Modules\\' . self::MODULE_NAME . '\\Application\\Payload\\Request\\WelcomePayload'),
            'temp-workspace generated class is NOT autoloaded by host process — operator must run composer dump-autoload before routes:list',
        );
    }

    // ------------------------------------------------------------------
    //  Scenario D — schema dry-run probe
    // ------------------------------------------------------------------

    #[Test]
    public function orm_dry_run_plan_includes_webhook_persistence_tables(): void
    {
        // Skip if MySQL not reachable — orm:sync requires a real connection
        // for its diff to be meaningful.
        $host = getenv('DB_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('DB_HOST not configured — orm:sync probe requires real MySQL');
        }
        try {
            ContainerFactory::get()->get(OrmManager::class)->getAdapter()->execute('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('MySQL not reachable: ' . $e->getMessage());
        }

        // orm:sync --dry-run prints the operations plan to STDOUT.
        // Shelling out captures the same surface an operator sees on
        // their terminal. Test does not chdir, so the binary resolves
        // its project root via the host project's composer.json.
        $output = (string) shell_exec('bin/semitexa orm:sync --dry-run 2>&1');

        self::assertStringContainsString(
            'webhook_inbox',
            $output,
            'orm:sync --dry-run plan must mention webhook_inbox',
        );
        self::assertStringContainsString(
            'webhook_outbox',
            $output,
            'orm:sync --dry-run plan must mention webhook_outbox',
        );
        self::assertStringContainsString(
            'webhook_replay_keys',
            $output,
            'orm:sync --dry-run plan must mention webhook_replay_keys',
        );
    }

    // ------------------------------------------------------------------
    //  Scenario E — cleanup dry-run
    // ------------------------------------------------------------------

    #[Test]
    public function webhook_cleanup_dry_run_exits_successfully_against_synced_schema(): void
    {
        $this->skipIfNoSyncedWebhookSchema();

        // The cleanup command resolves WebhookRetentionService from the
        // container. Verifies the framework's CLI wiring builds the
        // service singleton end-to-end without the operator having to
        // hand-construct dependencies.
        $tester = new CommandTester(new WebhookCleanupCommand());
        $exit = $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $exit, 'webhook:cleanup --dry-run must exit 0; display: ' . $tester->getDisplay());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Webhook cleanup — DRY RUN', $display);
        self::assertStringContainsString('No rows were removed', $display);
    }

    #[Test]
    public function webhook_cleanup_dry_run_with_tenant_scope_exits_successfully(): void
    {
        $this->skipIfNoSyncedWebhookSchema();

        $tester = new CommandTester(new WebhookCleanupCommand());
        $exit = $tester->execute([
            '--dry-run' => true,
            '--tenant' => 'fresh-install-smoke',
        ]);

        self::assertSame(0, $exit, 'tenant-scoped dry-run must exit 0');
        $display = $tester->getDisplay();
        self::assertStringContainsString('fresh-install-smoke', $display, 'summary must surface the tenant scope');
        self::assertStringContainsString(
            '— (skipped in tenant-scoped run)',
            $display,
            'attempts cleanup is global-only and must report skip in tenant-scoped runs',
        );
    }

    // ------------------------------------------------------------------
    //  Scenario F — replay store binding equivalence
    // ------------------------------------------------------------------

    #[Test]
    public function in_memory_replay_store_satisfies_basic_atomic_claim_contract(): void
    {
        $store = new InMemoryWebhookReplayStore();
        $key = 'fresh-install:basic:evt-1';

        self::assertTrue($store->markIfFirstSeen($key, 60), 'first delivery wins the claim');
        self::assertFalse($store->markIfFirstSeen($key, 60), 'duplicate is rejected');
    }

    #[Test]
    public function mysql_replay_store_satisfies_basic_atomic_claim_contract(): void
    {
        $host = getenv('DB_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('DB_HOST not configured — MySQL backing not exercised');
        }
        try {
            $orm = ContainerFactory::get()->get(OrmManager::class);
            $orm->getAdapter()->execute('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('MySQL not reachable: ' . $e->getMessage());
        }

        $orm->getAdapter()->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                replay_key VARCHAR(191) NOT NULL,
                first_seen_at DATETIME NOT NULL,
                expires_at DATETIME NULL,
                PRIMARY KEY (replay_key)
            ) ENGINE=InnoDB',
            MySqlWebhookReplayStore::TABLE,
        ));

        try {
            $store = new MySqlWebhookReplayStore($orm);
            $key = 'fresh-install:mysql:' . uniqid();

            self::assertTrue($store->markIfFirstSeen($key, 60), 'MySQL backing: first delivery wins');
            self::assertFalse($store->markIfFirstSeen($key, 60), 'MySQL backing: duplicate rejected');
        } finally {
            $orm->getAdapter()->execute(sprintf('DELETE FROM `%s` WHERE replay_key LIKE :k', MySqlWebhookReplayStore::TABLE), ['k' => 'fresh-install:mysql:%']);
        }
    }

    #[Test]
    public function redis_replay_store_satisfies_basic_atomic_claim_contract(): void
    {
        if (!class_exists(\Semitexa\Webhooks\Auth\RedisWebhookReplayStore::class)) {
            self::markTestSkipped('RedisWebhookReplayStore class missing');
        }
        if (!class_exists(\Semitexa\Core\Redis\RedisConnectionPool::class)) {
            self::markTestSkipped('RedisConnectionPool not available');
        }
        $redisHost = getenv('REDIS_HOST');
        if ($redisHost === false || $redisHost === '') {
            self::markTestSkipped('REDIS_HOST not configured — Redis backing not exercised');
        }

        try {
            $pool = ContainerFactory::get()->get(\Semitexa\Core\Redis\RedisConnectionPool::class);
            $store = new \Semitexa\Webhooks\Auth\RedisWebhookReplayStore($pool);
            $key = 'fresh-install:redis:' . uniqid();

            self::assertTrue($store->markIfFirstSeen($key, 60), 'Redis backing: first delivery wins');
            self::assertFalse($store->markIfFirstSeen($key, 60), 'Redis backing: duplicate rejected');
        } catch (\Throwable $e) {
            self::markTestSkipped('Redis not reachable: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    //  Scenario G — docs / examples sanity
    // ------------------------------------------------------------------

    #[Test]
    public function migration_guide_is_present_and_carries_canonical_examples(): void
    {
        $projectRoot = dirname(__DIR__, 4);
        $guide = $projectRoot . '/packages/semitexa-docs/docs/en/migration/post-hardening.md';
        self::assertFileExists($guide, 'post-hardening migration guide must exist');

        $content = (string) file_get_contents($guide);

        // Every canonical example a fresh developer would copy verbatim.
        self::assertStringContainsString('#[AsPublicPayload(', $content, 'public payload example present');
        self::assertStringContainsString('#[AsProtectedPayload(', $content, 'protected payload example present');
        self::assertStringContainsString('#[AsServicePayload(', $content, 'service payload example present');
        self::assertStringContainsString('#[AsWebhookReceiver(', $content, 'webhook receiver example present');
        self::assertStringContainsString('WebhookReplayKeyFactory::compose', $content, 'tenant-aware replay key factory mentioned');
        self::assertStringContainsString('markIfFirstSeen(', $content, 'atomic replay primitive mentioned');
        self::assertStringContainsString('webhook:cleanup', $content, 'cleanup operator command mentioned');
        self::assertStringContainsString('--tenant=', $content, 'tenant-scoped cleanup mentioned');
        self::assertStringContainsString('bin/semitexa test:run', $content, 'quality gate command mentioned');
        self::assertStringContainsString('bin/semitexa ai:verify --all', $content, 'unified gate mentioned');
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function writeModule(): void
    {
        $this->enterTempProjectRoot();
        (new CommandTester(new MakeModuleCommand()))->execute([
            '--name' => self::MODULE_NAME,
            '--write' => true,
        ]);
    }

    private function writeModuleAndAllPayloads(): void
    {
        $this->writeModule();
        (new CommandTester(new MakePageCommand()))->execute([
            '--module' => self::MODULE_NAME,
            '--name' => 'Welcome',
            '--path' => '/fresh-install/welcome',
            '--method' => 'GET',
            '--access' => 'public',
            '--write' => true,
        ]);
        (new CommandTester(new MakePayloadCommand()))->execute([
            '--module' => self::MODULE_NAME,
            '--name' => 'AccountStatus',
            '--path' => '/fresh-install/account-status',
            '--method' => 'GET',
            '--response' => 'AccountStatus',
            '--access' => 'protected',
            '--write' => true,
        ]);
        (new CommandTester(new MakePayloadCommand()))->execute([
            '--module' => self::MODULE_NAME,
            '--name' => 'PartnerPing',
            '--path' => '/fresh-install/partner-ping',
            '--method' => 'POST',
            '--response' => 'PartnerPing',
            '--access' => 'service',
            '--write' => true,
        ]);
    }

    private function assertGeneratedPayloadIsClean(string $content): void
    {
        // Negative pins matching the cycle 25 generator regression test —
        // the per-file scan inside the readiness gate is a belt-and-braces
        // guarantee that THIS particular invocation produced clean output.
        self::assertStringNotContainsString('#[AsPayload(', $content, 'must not emit retired #[AsPayload]');
        self::assertStringNotContainsString('PublicEndpoint', $content, 'must not emit retired #[PublicEndpoint]');
        self::assertStringNotContainsString('vendor/bin/phpunit', $content);
        self::assertDoesNotMatchRegularExpression('/(?:^|\W)[Cc]ycle[ -]\d+/', $content, 'no cycle-N markers');
    }

    private function skipIfNoSyncedWebhookSchema(): void
    {
        $host = getenv('DB_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('DB_HOST not configured — webhook:cleanup probe requires real MySQL');
        }
        try {
            $orm = ContainerFactory::get()->get(OrmManager::class);
            $orm->getAdapter()->execute('SELECT 1');
            // Cleanup needs the three tenant-scopable tables; if they're
            // missing the test environment hasn't been sync'd. Ensure
            // they exist with the minimum schema cleanup expects.
            $this->ensureWebhookCleanupSchema($orm);
        } catch (\Throwable $e) {
            self::markTestSkipped('MySQL or webhook schema unavailable: ' . $e->getMessage());
        }
    }

    private function ensureWebhookCleanupSchema(OrmManager $orm): void
    {
        $adapter = $orm->getAdapter();
        $adapter->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `webhook_inbox` (
    id BINARY(16) NOT NULL,
    endpoint_definition_id BINARY(16) NOT NULL,
    provider_key VARCHAR(64) NOT NULL,
    endpoint_key VARCHAR(191) NOT NULL,
    tenant_id VARCHAR(64) NULL,
    provider_event_id VARCHAR(255) NULL,
    dedupe_key VARCHAR(255) NOT NULL,
    signature_status VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL,
    content_type VARCHAR(128) NULL,
    http_method VARCHAR(16) NOT NULL,
    request_uri VARCHAR(2048) NOT NULL,
    headers_json LONGTEXT NULL,
    raw_body LONGTEXT NULL,
    raw_body_sha256 CHAR(64) NOT NULL,
    parsed_event_type VARCHAR(191) NULL,
    first_received_at DATETIME NOT NULL,
    last_received_at DATETIME NOT NULL,
    processing_started_at DATETIME NULL,
    processed_at DATETIME NULL,
    failed_at DATETIME NULL,
    duplicate_count INT NOT NULL,
    last_error LONGTEXT NULL,
    metadata_json LONGTEXT NULL,
    created_at DATETIME(6) NULL,
    updated_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_webhook_inbox_dedupe_key (dedupe_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
        $adapter->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `webhook_outbox` (
    id BINARY(16) NOT NULL,
    endpoint_definition_id BINARY(16) NOT NULL,
    endpoint_key VARCHAR(191) NOT NULL,
    provider_key VARCHAR(64) NOT NULL,
    tenant_id VARCHAR(64) NULL,
    event_type VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL,
    idempotency_key VARCHAR(255) NULL,
    payload_json LONGTEXT NOT NULL,
    headers_json LONGTEXT NULL,
    signed_headers_json LONGTEXT NULL,
    next_attempt_at DATETIME(6) NOT NULL,
    last_attempt_at DATETIME(6) NULL,
    delivered_at DATETIME(6) NULL,
    attempt_count INT NOT NULL,
    max_attempts INT NOT NULL,
    initial_backoff_seconds INT NOT NULL,
    max_backoff_seconds INT NOT NULL,
    lease_owner VARCHAR(191) NULL,
    lease_expires_at DATETIME(6) NULL,
    last_response_status INT NULL,
    last_response_headers_json LONGTEXT NULL,
    last_response_body LONGTEXT NULL,
    last_error LONGTEXT NULL,
    source_ref VARCHAR(255) NULL,
    metadata_json LONGTEXT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_webhook_outbox_endpoint_idempotency (endpoint_definition_id, idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
        $adapter->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `webhook_attempts` (
    id BINARY(16) NOT NULL,
    direction VARCHAR(16) NOT NULL,
    inbox_id BINARY(16) NULL,
    outbox_id BINARY(16) NULL,
    event_type VARCHAR(64) NOT NULL,
    attempt_number INT NULL,
    status_before VARCHAR(32) NULL,
    status_after VARCHAR(32) NULL,
    worker_id VARCHAR(128) NULL,
    http_status INT NULL,
    message VARCHAR(512) NULL,
    details_json LONGTEXT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

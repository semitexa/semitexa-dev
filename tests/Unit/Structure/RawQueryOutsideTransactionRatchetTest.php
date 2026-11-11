<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Raw SQL through getAdapter() outside the ORM, counted across the repository
 * (tk-getadapter-writes-escape-transactions).
 *
 * getAdapter() takes a pooled connection of its own. Inside a caller's
 * transaction, a write through it either commits on its own, so the caller's
 * rollback leaves it behind, or waits on the transaction's own row locks until
 * the MySQL lock timeout. Measured 2026-11-11: the Explorer sandbox POST hung
 * 50 s on exactly that. getQueryAdapter() joins the open transaction and
 * behaves the same outside one.
 *
 * A ratchet: a new site fails the build. The sites below are each to be moved
 * or justified. A site that goes away needs no edit here, so a fix in another
 * repository lands on its own: making every such fix also edit this file is
 * the cross-repository stranding that stopped the 2026-11-11 preflight. The
 * price is that a file that lost calls keeps its old ceiling until its entry
 * is lowered, so drop an entry once its fix is merged (review, dev#233).
 */
final class RawQueryOutsideTransactionRatchetTest extends TestCase
{
    /** Why this guard exists; ai:verify prints it when the guard fails (PhpunitFailureHeadline). */
    public const RATIONALE = 'Why: getAdapter() runs on a connection of its own, so inside a transaction a raw write commits alone or waits on the transaction\'s own locks. Learned 2026-11-11: the Explorer sandbox POST hung 50 s until the MySQL lock timeout.';

    /**
     * path => [calls, status]. Every getAdapter() call counts, however its
     * adapter is used next: chained, kept in a variable or a property, or
     * returned. A narrower pattern let a stored adapter through (review, dev#233).
     * Measured 2026-11-11.
     */
    private const KNOWN = [
        'packages/semitexa-api/src/Application/Db/MySQL/Repository/MachineCredentialRepository.php' => [1, 'recordUsage(): called only from MachineAuthHandler, before any handler transaction; to move'],
        'packages/semitexa-core/src/Container/ContainerFactory.php' => [1, 'container bootstrap, no transaction'],
        'packages/semitexa-crud/src/Application/Handler/PayloadHandler/CollectionFeedHandler.php' => [2, 'reads; inside a transaction they miss its uncommitted rows; to check'],
        'packages/semitexa-crud/src/Application/Service/Relation/RelationOptions.php' => [1, 'read; as above; to check'],
        'packages/semitexa-dev/src/Application/Service/Trace/TraceGraphReader.php' => [2, 'reads the trace database, never in a transaction'],
        'packages/semitexa-ledger/src/Application/Console/Command/LedgerProbeCommand.php' => [1, 'console probe, no request transaction'],
        'packages/semitexa-ledger/src/Application/Service/LedgerBootstrap.php' => [2, 'ownership adapter taken at bootstrap'],
        'packages/semitexa-media/src/Application/Db/MySQL/Repository/MediaVariantRepository.php' => [1, 'to check'],
        'packages/semitexa-os/src/Application/Service/UpdatesReport.php' => [1, 'reads the update report on the default connection; to check'],
        'packages/semitexa-project-graph/src/Application/Service/Graph/EphemeralGraph.php' => [2, 'its own sqlite graph connection'],
        'packages/semitexa-project-graph/src/Application/Service/Support/UsesProjectGraphConnection.php' => [1, 'its own graph connection'],
        'packages/semitexa-scheduler/src/Application/Db/MySQL/Repository/ScheduledRunRepository.php' => [1, 'to check'],
        'packages/semitexa-scheduler/src/Application/Db/MySQL/Repository/SchedulerLockRepository.php' => [1, 'a lock that must outlive the caller; to confirm'],
        'packages/semitexa-tasks/src/Application/Service/TaskStore.php' => [1, 'claimComplete() guarded UPDATE; to move or justify'],
        'packages/semitexa-testing/src/Traits/RefreshesTables.php' => [1, 'test support: empties tables between tests'],
        'packages/semitexa-update/src/Application/Service/UpdateRunnerFactory.php' => [3, 'the update runner owns its connection'],
        'packages/semitexa-webhooks/src/Application/Db/MySQL/Repository/OutboundDeliveryRepository.php' => [1, 'to check'],
        'packages/semitexa-webhooks/src/Auth/MySqlWebhookReplayStore.php' => [7, 'replay keys: whether a consumed key should survive the handler\'s rollback is to decide'],
        'packages/semitexa-workflow/src/Application/Console/Command/WorkflowBackfillTenantKeyCommand.php' => [1, 'console backfill, no request transaction'],
        'packages/semitexa-workflow/src/Application/Db/MySQL/Repository/WorkflowInstanceRepository.php' => [1, 'to check'],
        'packages/semitexa-workflow/src/Application/Db/MySQL/Repository/WorkflowTransitionHistoryRepository.php' => [1, 'to check'],
        'src/modules/ReplicationDemo/src/Application/Service/DemoNoteStore.php' => [1, 'read in the replication demo; to check'],
        'src/modules/UiPlayground/src/Application/Console/Command/SeedLeadsCommand.php' => [1, 'console seeder'],
        'src/modules/UiPlayground/src/Application/Db/MySQL/Repository/UiPlaygroundLeadDbRepository.php' => [2, 'to check'],
    ];

    /** Any call, whitespace allowed wherever PHP allows it. */
    private const PATTERN = '/getAdapter\s*\(\s*\)/';

    private static function sites(string $source): int
    {
        return (int) preg_match_all(self::PATTERN, $source);
    }

    #[Test]
    public function no_new_raw_query_runs_outside_the_callers_transaction(): void
    {
        $grown = [];
        foreach ($this->productionFiles() as $path => $source) {
            $sites = self::sites($source);
            if ($sites > (self::KNOWN[$path][0] ?? 0)) {
                $grown[] = sprintf('%s: %d (known %d)', $path, $sites, self::KNOWN[$path][0] ?? 0);
            }
        }

        self::assertSame(
            [],
            $grown,
            "Raw SQL through getAdapter() grew.\n"
            . "Use \$orm->getQueryAdapter()->execute(...): it joins the open transaction and behaves the same outside one.\n"
            . "If the statement must commit on its own whatever the caller does, record it in KNOWN with that reason.",
        );
    }

    /** An empty scan is what a clean repository looks like, so the scan has to prove it still sees the shape. */
    #[Test]
    public function the_scan_still_sees_the_repository_and_the_shape_it_counts(): void
    {
        $files = $this->productionFiles();
        self::assertGreaterThan(1000, count($files), 'the production scan found almost nothing');

        $seen = 0;
        foreach (array_keys(self::KNOWN) as $path) {
            self::assertArrayHasKey($path, $files, "a known site is no longer scanned: {$path}");
            $seen += self::sites($files[$path]);
        }
        self::assertGreaterThan(0, $seen, 'no known site matches, so the pattern is not matching');
    }

    /** @return array<string, string> relative path => source, production code outside the ORM */
    private function productionFiles(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        // __DIR__ is packages/semitexa-dev/tests/Unit/Structure: four levels up is packages/.
        $packages = dirname(__DIR__, 4);
        $projectRoot = dirname($packages);
        $out = [];
        foreach ([$packages, $projectRoot . '/src'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = str_replace($projectRoot . '/', '', $file->getPathname());
                // The ORM is where getAdapter() is defined and used on purpose.
                if (!preg_match('#(^packages/[a-z0-9-]+/src/|^src/modules/[A-Za-z0-9]+/src/)#', $path) || str_starts_with($path, 'packages/semitexa-orm/')) {
                    continue;
                }
                // Unreadable is a failure: read as empty, it would count as clean (review, dev#233).
                $source = file_get_contents($file->getPathname());
                self::assertIsString($source, "cannot read {$path}");
                $out[$path] = $source;
            }
        }

        return $cache = $out;
    }
}

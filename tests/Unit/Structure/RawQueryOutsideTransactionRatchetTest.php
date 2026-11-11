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
 * or justified; a site that goes away needs no edit here, so a fix in another
 * repository does not have to land together with this file.
 */
final class RawQueryOutsideTransactionRatchetTest extends TestCase
{
    /** Why this guard exists; ai:verify prints it when the guard fails (PhpunitFailureHeadline). */
    public const RATIONALE = 'Why: getAdapter() runs on a connection of its own, so inside a transaction a raw write commits alone or waits on the transaction\'s own locks. Learned 2026-11-11: the Explorer sandbox POST hung 50 s until the MySQL lock timeout.';

    /** path => [sites, reason] */
    private const KNOWN = [
        'packages/semitexa-api/src/Application/Db/MySQL/Repository/MachineCredentialRepository.php' => [1, 'recordUsage(): called only from MachineAuthHandler, before any handler transaction; to move'],
        'packages/semitexa-crud/src/Application/Handler/PayloadHandler/CollectionFeedHandler.php' => [2, 'reads; inside a transaction they miss its uncommitted rows; to check'],
        'packages/semitexa-crud/src/Application/Service/Relation/RelationOptions.php' => [1, 'read; as above; to check'],
        'packages/semitexa-dev/src/Application/Service/Trace/TraceGraphReader.php' => [1, 'sqlite_master read on the trace database, never in a transaction'],
        'packages/semitexa-os/src/Application/Service/ProcessRegistry.php' => [1, 'retention DELETE; to move'],
        'packages/semitexa-os/src/Application/Service/ConversationSummaryStore.php' => [2, 'save/clear writes; to move'],
        'packages/semitexa-tasks/src/Application/Service/TaskStore.php' => [1, 'claimComplete() guarded UPDATE; to move or justify'],
        'packages/semitexa-webhooks/src/Auth/MySqlWebhookReplayStore.php' => [7, 'replay keys: whether a consumed key should survive the handler\'s rollback is to decide'],
        'src/modules/ReplicationDemo/src/Application/Service/DemoNoteStore.php' => [1, 'read in the replication demo; to check'],
        // An adapter kept in a variable, used later (review, dev#233). None of these runs inside a request's transaction.
        'packages/semitexa-ledger/src/Application/Service/LedgerBootstrap.php' => [1, 'ownership adapter taken at bootstrap'],
        'packages/semitexa-os/src/Application/Service/UpdatesReport.php' => [1, 'reads the update report on the default connection; to check'],
        'packages/semitexa-testing/src/Traits/RefreshesTables.php' => [1, 'test support: empties tables between tests'],
        'packages/semitexa-update/src/Application/Service/UpdateRunnerFactory.php' => [2, 'the update runner owns its connection'],
        'packages/semitexa-workflow/src/Application/Console/Command/WorkflowBackfillTenantKeyCommand.php' => [1, 'console backfill, no request transaction'],
        'src/modules/UiPlayground/src/Application/Console/Command/SeedLeadsCommand.php' => [1, 'console seeder'],
    ];

    /**
     * The chained call, whitespace allowed wherever PHP allows it, and an
     * adapter kept in a variable for later (review, dev#233).
     */
    private const PATTERNS = [
        '/getAdapter\s*\(\s*\)\s*->\s*(?:execute|query)\s*\(/',
        '/\$\w+\s*=\s*[^;=]*getAdapter\s*\(\s*\)\s*;/',
    ];

    private static function sites(string $source): int
    {
        $sites = 0;
        foreach (self::PATTERNS as $pattern) {
            $sites += (int) preg_match_all($pattern, $source);
        }

        return $sites;
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

        // Each pattern on its own: one blind pattern would hide behind the other (review, dev#233).
        foreach (self::PATTERNS as $pattern) {
            $seen = 0;
            foreach (array_keys(self::KNOWN) as $path) {
                self::assertArrayHasKey($path, $files, "a known site is no longer scanned: {$path}");
                $seen += (int) preg_match_all($pattern, $files[$path]);
            }
            self::assertGreaterThan(0, $seen, "no known site matches {$pattern}, so it is not matching");
        }
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

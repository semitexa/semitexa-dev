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
        'packages/semitexa-platform-settings/src/Application/Service/SettingsStore.php' => [3, 'moved to getQueryAdapter() in platform-settings#22; listed until a checkout at master has it'],
        'packages/semitexa-tasks/src/Application/Service/TaskStore.php' => [1, 'claimComplete() guarded UPDATE; to move or justify'],
        'packages/semitexa-webhooks/src/Auth/MySqlWebhookReplayStore.php' => [7, 'replay keys: whether a consumed key should survive the handler\'s rollback is to decide'],
        'src/modules/ReplicationDemo/src/Application/Service/DemoNoteStore.php' => [1, 'read in the replication demo; to check'],
    ];

    private const PATTERN = '/getAdapter\(\)\s*->\s*(?:execute|query)\(/';

    #[Test]
    public function no_new_raw_query_runs_outside_the_callers_transaction(): void
    {
        $grown = [];
        foreach ($this->productionFiles() as $path => $source) {
            $sites = preg_match_all(self::PATTERN, $source);
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
            $seen += preg_match_all(self::PATTERN, $files[$path] ?? '');
        }
        self::assertGreaterThan(0, $seen, 'none of the known sites matched, so the pattern is not matching');
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
                $out[$path] = (string) file_get_contents($file->getPathname());
            }
        }

        return $cache = $out;
    }
}

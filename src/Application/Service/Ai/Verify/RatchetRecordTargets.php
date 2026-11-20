<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * The test that reads a ratchet record, planned when that record changes.
 *
 * The release gate reads its bars from JSON records in this package's
 * resources/phpstan: the phpstan ceiling and the per-package semantic-rule
 * counts. A record is not PHP, so the planner selected no test for it, no
 * target named it in triggeredBy, and {@see CoverageGap} reported it unread:
 * a commit lowering one count verified `incomplete` and the push gate found
 * no receipt covering it (2026-11-19, semitexa-graphql 3 -> 1).
 *
 * Each record names the one test that reads its content and fails when it is
 * malformed. A record with no reader named here stays unread, and so does one
 * whose test is not installed (a project without this package's tests): the
 * coverage report then says so, which is the honest answer.
 */
final class RatchetRecordTargets
{
    /** Record, inside the semitexa-dev package => the test, inside it, that reads the record. */
    public const READERS = [
        'resources/phpstan/phpstan-ceiling.json' => 'tests/Unit/Structure/PhpstanCeilingIsVersionedTest.php',
        'resources/phpstan/semantic-rule-counts.json' => 'tests/Unit/Structure/SemanticRuleCountsRecordTest.php',
    ];

    /**
     * A package's share of the quality ledger, which `ai:quality` writes into the
     * package it counts, whichever package the change was in: an accept recorded
     * for semitexa-dev wrote semitexa-update's share too (20.11), and a commit of
     * that share alone could not be pushed. The ledger gate reads every share and
     * compares it with the counts it records.
     */
    private const LEDGER_SHARE = '#^packages/semitexa-[a-z0-9-]+/resources/quality\.json$#';

    /** The test that reads the ledger shares: the workspace has it; a project does not edit a package's share. */
    public const LEDGER_READER = 'packages/semitexa-dev/tests/Unit/Structure/QualityLedgerGateTest.php';

    /** The project-relative path of the test that reads this record, or null when none does or it is not there. */
    public static function readerOf(string $path, string $projectRoot): ?string
    {
        if (preg_match(self::LEDGER_SHARE, $path) === 1) {
            return is_file(rtrim($projectRoot, '/') . '/' . self::LEDGER_READER) ? self::LEDGER_READER : null;
        }
        if (preg_match('#^(packages/semitexa-dev|vendor/semitexa/dev)/(.+)$#', $path, $m) !== 1 || !isset(self::READERS[$m[2]])) {
            return null;
        }
        $reader = $m[1] . '/' . self::READERS[$m[2]];

        return is_file(rtrim($projectRoot, '/') . '/' . $reader) ? $reader : null;
    }

    /**
     * The phpunit targets, with the changed record's reader among them.
     *
     * Keyed by the test class, as the planner keys a changed test: a record
     * and its own test changed together are one target naming both.
     *
     * @param array<string, VerificationTarget> $phpunitTargets
     *
     * @return array<string, VerificationTarget>
     */
    public static function with(ChangedFile $file, array $phpunitTargets, string $projectRoot): array
    {
        $reader = $file->status === ChangedFile::STATUS_DELETED ? null : self::readerOf($file->path, $projectRoot);
        if ($reader === null) {
            return $phpunitTargets;
        }
        $class = basename($reader, '.php');
        $planned = $phpunitTargets[$class] ?? null;
        $phpunitTargets[$class] = new VerificationTarget(
            type: VerificationTarget::TYPE_PHPUNIT,
            id: "phpunit:{$class}",
            reason: $planned !== null ? $planned->reason : 'a ratchet record changed: the test that reads it and fails when it is malformed',
            triggeredBy: array_values(array_unique([...($planned !== null ? $planned->triggeredBy : []), $file->path])),
            filePath: $planned !== null && $planned->filePath !== null ? $planned->filePath : $reader,
            testFilter: $class,
        );

        return $phpunitTargets;
    }
}

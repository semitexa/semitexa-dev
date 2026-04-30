<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Verify;

/**
 * One entry in the set of files to verify. `kind` is resolved by
 * {@see ChangedFileClassifier} from the path alone — lint decisions should
 * never require reading the file.
 *
 * `status` mirrors `git diff --name-status` letters: A(added), M(modified),
 * D(deleted), R(renamed). Deleted files can't be linted or syntax-checked;
 * the planner filters them from execution but keeps them in the plan so the
 * caller sees them in the NDJSON report.
 */
final readonly class ChangedFile
{
    public const STATUS_ADDED    = 'A';
    public const STATUS_MODIFIED = 'M';
    public const STATUS_DELETED  = 'D';
    public const STATUS_RENAMED  = 'R';

    public const KIND_HANDLER      = 'handler';
    public const KIND_LISTENER     = 'listener';
    public const KIND_PAYLOAD      = 'payload';
    public const KIND_RESOURCE     = 'resource';
    public const KIND_SERVICE      = 'service';
    public const KIND_CONTRACT     = 'contract';
    public const KIND_TEMPLATE     = 'template';
    public const KIND_TEST         = 'test';
    /**
     * Phase 6f.5: support classes that live under a `tests/` tree but
     * are not themselves PHPUnit test cases — fixtures, stubs,
     * helpers, traits, builders. Distinguishing them from
     * {@see self::KIND_TEST} prevents the planner from invoking
     * `phpunit` on a class that does not extend `TestCase`. Changes
     * to fixtures still flow through the related-tests path so the
     * suites that actually load the fixture get re-run.
     */
    public const KIND_TEST_FIXTURE = 'test_fixture';
    public const KIND_PHP_OTHER    = 'php_other';
    public const KIND_NON_PHP      = 'non_php';

    public function __construct(
        public string $path,
        public string $kind,
        public string $status = self::STATUS_MODIFIED,
    ) {}
}

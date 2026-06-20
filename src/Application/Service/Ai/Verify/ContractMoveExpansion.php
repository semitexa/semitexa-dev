<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * One contract that was removed or renamed in the change set, and the list
 * of files that still reference it (queried from the project graph at plan
 * time). Carried through the {@see VerificationPlan} so the NDJSON report
 * explains why otherwise-unchanged files were pulled into the `phpstan_di`
 * scan — closing the blast-radius gap that lets DDD renames smuggle in
 * silent time-bombs (`ep-ai-verify-broken-fqcn-guard`).
 */
final readonly class ContractMoveExpansion
{
    /**
     * @param list<string> $dependentFiles repo-relative paths
     */
    public function __construct(
        public string $contractFqcn,
        public string $contractFile,
        public string $changeStatus,
        public array  $dependentFiles,
    ) {}

    public function reason(): string
    {
        $verb = match ($this->changeStatus) {
            ChangedFile::STATUS_DELETED  => 'removed',
            ChangedFile::STATUS_RENAMED  => 'renamed',
            default                      => 'moved',
        };
        $count = count($this->dependentFiles);
        return sprintf(
            'contract %s %s (%s) — adding %d dependent file(s) to phpstan_di scan',
            $this->contractFqcn,
            $verb,
            $this->contractFile,
            $count,
        );
    }
}

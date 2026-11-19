<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * The phpunit target of a changed test file.
 *
 * Targets are keyed by the test's class name, its file's basename. A target
 * already planned under that name may run this very file (the reader of a
 * changed ratchet record, or a class and its test changed together): the
 * changed test then joins its triggeredBy, so the coverage report counts it as
 * read. A `??=` used to leave that target alone, and in the order the planner
 * gets paths (sorted, resources/ before tests/) the changed test was named by
 * no target (semitexa-dev#286).
 *
 * A same-named test of another package (RetryPolicyTest in core and in
 * scheduler) is another file. Merged into the first target it would replace
 * that target's file, so only one of them ran while both counted as read. It
 * gets a target of its own, keyed by its path, and each names only itself.
 */
final class ChangedTestTargets
{
    private const REASON = 'test file changed — running it directly';

    /**
     * @param array<string, VerificationTarget> $phpunitTargets
     *
     * @return array<string, VerificationTarget>
     */
    public static function with(ChangedFile $file, string $className, array $phpunitTargets): array
    {
        $planned = $phpunitTargets[$className] ?? null;
        if ($planned !== null && $planned->filePath !== null && $planned->filePath !== $file->path) {
            $phpunitTargets[$file->path] ??= self::target("phpunit:{$file->path}", self::REASON, [$file->path], $file->path, $className);

            return $phpunitTargets;
        }
        $phpunitTargets[$className] = self::target(
            "phpunit:{$className}",
            $planned !== null ? $planned->reason : self::REASON,
            array_values(array_unique([...($planned !== null ? $planned->triggeredBy : []), $file->path])),
            $file->path,
            $className,
        );

        return $phpunitTargets;
    }

    /** @param list<string> $triggeredBy */
    private static function target(string $id, string $reason, array $triggeredBy, string $filePath, string $className): VerificationTarget
    {
        return new VerificationTarget(
            type: VerificationTarget::TYPE_PHPUNIT,
            id: $id,
            reason: $reason,
            triggeredBy: $triggeredBy,
            filePath: $filePath,
            testFilter: $className,
        );
    }
}

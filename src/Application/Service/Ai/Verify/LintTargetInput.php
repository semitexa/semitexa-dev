<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * What a planned lint is run with. Most lints read the whole tree and take
 * nothing; the two here are given the files that scheduled them.
 */
final class LintTargetInput
{
    /**
     * @param list<string> $triggeredBy the changed files that scheduled the lint
     * @return array<string, string|bool|list<string>>
     */
    public static function of(string $commandName, array $triggeredBy, bool $broad): array
    {
        if ($triggeredBy === []) {
            return [];
        }

        return match ($commandName) {
            // Narrowed to the changed files; a broad run reads every application file.
            'lint:mechanisms' => $broad ? [] : ['--file' => $triggeredBy],
            // The lint walks the directories a project keeps scripts in, and a changed script may
            // lie in none of them (a root-level deploy.sh): unnamed, it was a target that read
            // nothing and passed (review, dev#282). A broad run reads the project and these.
            'lint:shell-fallbacks' => ['--file' => $triggeredBy] + ($broad ? ['--all' => true] : []),
            default => [],
        };
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Verify;

/**
 * One thing to execute during `ai:verify`. Deliberately simple: each target
 * names a Symfony command (or a shell-level syntax check) plus the reason it
 * was selected. The reason is surfaced in NDJSON so agents can see *why* a
 * given lint fired — a core audit requirement of Phase 4.
 *
 * `type`:
 *   - `lint`     → dispatch via Application::find()->run()
 *   - `syntax`   → `php -l` on the file
 *   - `phpunit`  → invoke project phpunit with `--filter` on the test class
 */
final readonly class VerificationTarget
{
    public const TYPE_LINT    = 'lint';
    public const TYPE_SYNTAX  = 'syntax';
    public const TYPE_PHPUNIT = 'phpunit';

    /**
     * @param list<string> $triggeredBy relative paths of changed files that caused this target to be scheduled
     */
    public function __construct(
        public string $type,
        public string $id,
        public string $reason,
        public array $triggeredBy,
        public ?string $commandName = null,
        public ?string $filePath = null,
        public ?string $testFilter = null,
    ) {}
}

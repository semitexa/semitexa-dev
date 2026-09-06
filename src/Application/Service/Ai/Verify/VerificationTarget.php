<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * One thing to execute during `ai:verify`. Deliberately simple: each target
 * names a Symfony command (or a shell-level syntax check) plus the reason it
 * was selected. The reason is surfaced in NDJSON so agents can see *why* a
 * given lint fired — a core audit requirement of Phase 4.
 *
 * `type`:
 *   - `lint`             → dispatch via Application::find()->run()
 *   - `syntax`           → `php -l` on the file
 *   - `phpunit`          → invoke project phpunit with `--filter` on the test class
 *   - `module_structure` → walk the named module dir and check it against
 *                          the rule set in MODULE_STRUCTURE.md
 *   - `phpstan_di`       → invoke PHPStan with the focused
 *                          `phpstan-ai-verify.neon` config (loads only the
 *                          Semitexa-owned DI rules) and re-emit each
 *                          diagnostic verbatim, preserving the PHPStan
 *                          identifier — single source of truth lives in
 *                          `packages/semitexa-core/src/PHPStan/Rules/`
 */
final readonly class VerificationTarget
{
    public const TYPE_LINT             = 'lint';
    public const TYPE_SYNTAX           = 'syntax';
    public const TYPE_PHPUNIT          = 'phpunit';
    public const TYPE_MODULE_STRUCTURE = 'module_structure';
    public const TYPE_PHPSTAN_DI       = 'phpstan_di';
    public const TYPE_LIVE_TENANCY     = 'live_tenancy';
    public const TYPE_CAPABILITY_INDEX = 'capability_index';
    public const TYPE_CAPABILITY_COVERAGE = 'capability_coverage';
    public const TYPE_SKILL_COPIES     = 'skill_copies';
    public const TYPE_SCAFFOLD_DRIFT   = 'scaffold_drift';
    public const TYPE_DOCS             = 'docs';

    /**
     * @param list<string> $triggeredBy relative paths of changed files that caused this target to be scheduled
     */
    public function __construct(
        public string $type,
        public string $id,
        public string $reason,
        public array $triggeredBy,
        public ?string $commandName = null,
        /**
         * Extra console input for the target's command. TYPE_LINT runs a bare
         * command; a docs gate needs its baseline and its --check.
         *
         * @var array<string, string|bool>
         */
        public array $commandInput = [],
        public ?string $filePath = null,
        public ?string $testFilter = null,
    ) {}
}

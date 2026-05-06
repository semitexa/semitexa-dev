<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Trace;

/**
 * High-value event kinds that warrant persisting to a trace.
 *
 * Keep this list small on purpose: traces exist to give future sessions a
 * compact "what happened" readout, not a firehose. If a new kind is added,
 * think twice — can it be represented as a summary on an existing kind?
 *
 * Each constant value is the literal string stored in NDJSON, so renames
 * break existing traces. Treat additions as a schema-forward change only.
 */
final class TraceEventKind
{
    public const TASK_RESULT       = 'task_result';
    public const CONTEXT_SUMMARY   = 'context_summary';
    public const PLAN_DECISION     = 'plan_decision';
    public const SCAFFOLD_ACTION   = 'scaffold_action';
    public const VERIFY_RESULT     = 'verify_result';
    public const NEXT_STEP         = 'next_step';
    public const NOTE              = 'note';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::TASK_RESULT,
            self::CONTEXT_SUMMARY,
            self::PLAN_DECISION,
            self::SCAFFOLD_ACTION,
            self::VERIFY_RESULT,
            self::NEXT_STEP,
            self::NOTE,
        ];
    }

    public static function isKnown(string $kind): bool
    {
        return in_array($kind, self::all(), true);
    }

    private function __construct() {}
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

/**
 * Which method a reader most likely wants to see first, for a class the trace
 * named without naming a method.
 *
 * Spans today record `handler => FQCN` and nothing more — the method is the
 * framework's convention, not the recording's. The convention is stable enough
 * to write down: a handler is entered through `handle()`, a pre-hydration gate
 * through `gate()`, a listener through `handle()` or `__invoke()`. When a span
 * starts carrying its own method this table stops being consulted for it, and
 * loses nothing.
 *
 * Returns candidates, not a verdict: the reader tries them in order against
 * the real class and shows the first that exists, or the class when none does.
 */
final class EntryMethodCatalog
{
    /** Graph node type → candidate methods, most conventional first. */
    private const BY_TYPE = [
        'handler' => ['handle', '__invoke'],
        'auth_handler' => ['gate', 'handle', '__invoke'],
        'event_listener' => ['handle', '__invoke'],
        'command' => ['execute', '__invoke'],
        'slot_handler' => ['handle', '__invoke'],
        'service' => ['__invoke'],
        // A payload's shape IS the class — setters and validation together.
        // validate() is offered because a payload that has one usually keeps
        // the interesting rule there; without one the class view is right.
        'payload' => ['validate'],
        // A resource is a DTO; there is no entry method worth preferring.
        'resource' => [],
    ];

    /** Class-name suffix → candidates, for a class the graph does not know. */
    private const BY_SUFFIX = [
        'Handler' => ['handle', '__invoke'],
        'Gate' => ['gate', 'handle', '__invoke'],
        'Listener' => ['handle', '__invoke'],
        'Command' => ['execute', '__invoke'],
        'Payload' => ['validate'],
        'Middleware' => ['process', 'handle', '__invoke'],
    ];

    /**
     * @param  string      $fqcn     the class in question
     * @param  string|null $nodeType its project-graph node type, when known
     * @return list<string>
     */
    public function candidates(string $fqcn, ?string $nodeType): array
    {
        if ($nodeType !== null && isset(self::BY_TYPE[$nodeType])) {
            return self::BY_TYPE[$nodeType];
        }

        foreach (self::BY_SUFFIX as $suffix => $methods) {
            if (str_ends_with($fqcn, $suffix)) {
                return $methods;
            }
        }

        return [];
    }
}

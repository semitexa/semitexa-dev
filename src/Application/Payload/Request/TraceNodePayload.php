<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * A class as the project graph knows it, at `/__trace/node`.
 *
 * Reached by clicking a step in a trace: the trace says a handler ran, this says
 * what that handler is wired to — and, since the source view, what it actually
 * does: the method the step entered, or the whole class on request. Public and
 * dev-gated for the same reason as {@see TracePayload}.
 */
#[AsPublicPayload(
    path: '/__trace/node',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class TraceNodePayload
{
    public const SCOPE_METHOD = 'method';
    public const SCOPE_CLASS = 'class';

    /** Fully-qualified class name to look up. */
    public string $class = '';

    /** Trace to return to, so the back link goes where the reader came from. */
    public string $from = '';

    /**
     * Method to show. Empty means "the one this kind of class is entered
     * through" — resolved by convention on the server, not guessed by the link.
     */
    public string $method = '';

    /** `method` (default) narrows the source view; `class` shows the whole class. */
    public string $scope = self::SCOPE_METHOD;

    /**
     * The hydrator fills payloads through set{CamelCase}() rather than by writing
     * properties, so a public property alone is never populated.
     */
    public function setClass(mixed $value): void
    {
        $this->class = is_string($value) ? $value : '';
    }

    public function setFrom(mixed $value): void
    {
        $this->from = is_string($value) ? $value : '';
    }

    public function setMethod(mixed $value): void
    {
        $this->method = is_string($value) ? $value : '';
    }

    public function setScope(mixed $value): void
    {
        // Anything that is not the class scope is the default. An unknown value
        // must not open a third, undefined view.
        $this->scope = $value === self::SCOPE_CLASS ? self::SCOPE_CLASS : self::SCOPE_METHOD;
    }

    public function wantsClass(): bool
    {
        return $this->scope === self::SCOPE_CLASS;
    }
}

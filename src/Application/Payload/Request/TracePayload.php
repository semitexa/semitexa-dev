<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The trace viewer, at `/__trace`.
 *
 * Public because it is only reachable where semitexa/dev is installed, and the
 * handler refuses outright unless APP_ENV is dev — an auth-protected route would
 * imply this is something to secure rather than something that should not exist
 * in production at all.
 *
 * The double underscore matches the framework's other internal routes
 * (`/__ui/dispatch`, `/__ui/event`), which is what keeps it clear of application
 * paths.
 */
#[AsPublicPayload(
    path: '/__trace',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class TracePayload
{
    /** Which trace file to open. Empty shows the list. */
    public string $file = '';

    /**
     * The hydrator fills payloads through set{CamelCase}() rather than by writing
     * properties, so a public property alone is never populated.
     */
    public function setFile(mixed $value): void
    {
        $this->file = is_string($value) ? $value : '';
    }
}

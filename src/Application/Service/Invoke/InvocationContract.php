<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Invoke;

use Semitexa\Core\Environment;

/**
 * The terms on which `ai:invoke` runs a handler.
 *
 * That command is not a dry run. It calls `handle()` for real: whatever the
 * handler writes, sends or charges, it writes, sends and charges. What it skips
 * is everything the request pipeline would have done FIRST — which is the whole
 * point as a feedback loop, and precisely what makes it dangerous anywhere the
 * data is real.
 *
 * ## Dev only, and nowhere else
 *
 * "Skip authentication and run this handler with input I choose" is a feedback
 * loop on a dev box and a remote-execution surface anywhere else. Monitor mode
 * deliberately does NOT buy execution: it opens read-only observability outside
 * dev, and running a handler is not reading.
 *
 * ## Saying what was skipped
 *
 * A result produced with no auth, no validation and no tenant looks exactly
 * like one produced with all three. A reader who cannot tell the difference
 * will trust it as if it were the real thing — so the envelope carries the
 * omissions next to the result, rather than leaving them in a docblock nobody
 * reads at 2am.
 */
final class InvocationContract
{
    public static function executionAllowed(): bool
    {
        return Environment::getEnvValue('APP_ENV') === 'dev';
    }

    public static function refusalReason(): string
    {
        return 'ai:invoke executes the handler for real while skipping authentication, '
            . 'validation and tenant resolution, so it runs only when APP_ENV=dev. '
            . 'Use --preview to resolve the target and see what would run, anywhere.';
    }

    /**
     * What this invocation did NOT do, grouped so a reader can tell a missing
     * gate from a missing context.
     *
     * @return array{pipeline: list<string>, context: list<string>}
     */
    public static function omissions(): array
    {
        return [
            'pipeline' => [
                'authentication and authorization: the handler runs as nobody',
                'pipeline listeners and middleware: nothing ran before or after handle()',
                'payload validation: the JSON was hydrated straight onto the DTO',
                'the response pipeline: the resource is serialized as-is, not rendered',
            ],
            'context' => [
                'tenant resolution: no tenant is active, and tenant-scoped reads see nothing',
                'session: an empty in-memory session is provided, not a real one',
                'cookies: none are sent, so anything reading them sees an empty jar',
                'route path parameters: the path is used verbatim and placeholders are not filled',
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The panel's stylesheet and script, each at its own address.
 *
 * They used to be inlined into the page as bare `<style>` and `<script>` tags,
 * which is one request and works on a stack where nothing else does — right up
 * until the consumer serves a Content-Security-Policy. Under a policy of the
 * usual shape (`script-src 'self' 'nonce-…'`) the browser refuses an inline
 * script with no nonce, and the panel paints its shell and then nothing: every
 * tile reads «–», no worker appears, and the console stays EMPTY, so the page
 * looks broken rather than blocked.
 *
 * Served from here, `'self'` already allows both and the panel stops caring
 * about nonces at all.
 *
 * Public for the same reason `/__observatory` is: the route only exists where
 * semitexa/dev is installed, and the handler answers 404 to anyone the
 * ObservatoryPanelGate refuses.
 */
#[AsPublicPayload(
    path: '/__observatory/asset/{name}',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ObservatoryAssetPayload
{
    private string $name = '';

    public function setName(string $name): void
    {
        $this->name = trim($name);
    }

    public function getName(): string
    {
        return $this->name;
    }
}

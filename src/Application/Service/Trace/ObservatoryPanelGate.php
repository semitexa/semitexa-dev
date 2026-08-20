<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Environment;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Request;

/**
 * Whether the CURRENT request may see the Observatory panel and its feed.
 *
 * In dev the panel is open, as it always was: it only exists where
 * semitexa/dev is installed and APP_ENV is dev. Monitor mode is the new case —
 * the journal runs on a production box, and "who may look" stops being a
 * theoretical question. The rules mirror core's /metrics endpoint, the one
 * production observability surface that already answered it:
 *
 * - SEMITEXA_OBSERVATORY_TOKEN set → the `X-Observatory-Token` header decides,
 *   exclusively. A configured token that is wrong or missing never falls
 *   through to the loopback rule — an operator who set a token expects it to
 *   be THE credential.
 * - No token → direct loopback only: remote_addr is 127.0.0.1/::1 AND no
 *   forwarding headers are present. Behind the usual reverse proxy every
 *   request arrives from loopback WITH forwarding headers, so the public
 *   internet stays out while an operator's SSH tunnel straight to the app
 *   port walks in.
 *
 * Denials render as the same 404 the off mode shows, upstream in the handlers:
 * a 403 would confirm to a stranger that the panel exists.
 */
#[AsService]
final class ObservatoryPanelGate
{
    private const TOKEN_HEADER = 'X-Observatory-Token';

    public function allows(): bool
    {
        $mode = ObservatoryMode::resolve();
        if ($mode === ObservatoryMode::DEV) {
            return true;
        }
        if ($mode !== ObservatoryMode::MONITOR) {
            return false;
        }

        $request = CurrentRequestStore::get();
        if ($request === null) {
            return false;
        }

        $configured = Environment::getEnvValue('SEMITEXA_OBSERVATORY_TOKEN');
        if (is_string($configured) && $configured !== '') {
            $provided = $request->getHeader(self::TOKEN_HEADER);

            return is_string($provided) && hash_equals($configured, $provided);
        }

        return $this->isDirectLoopback($request);
    }

    private function isDirectLoopback(Request $request): bool
    {
        $remote = strtolower(trim($request->getServer('remote_addr')));
        if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
            return false;
        }

        // Same list as core's MetricsHandler: any evidence of a proxy hop
        // means the loopback peer is a proxy, not the operator's tunnel.
        foreach (['X-Forwarded-For', 'Forwarded', 'X-Real-Ip', 'True-Client-Ip'] as $header) {
            $value = $request->getHeader($header);
            if (is_string($value) && $value !== '') {
                return false;
            }
        }

        return true;
    }
}

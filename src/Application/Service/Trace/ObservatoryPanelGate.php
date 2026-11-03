<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Environment;
use Semitexa\Core\Http\CrossSiteRead;
use Semitexa\Core\Http\ThisMachine;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Request;

/**
 * Whether the CURRENT request may see the Observatory panel and its feed.
 *
 * In dev the panel is open to THIS MACHINE (ThisMachine): it only exists
 * where semitexa/dev is installed and APP_ENV is dev, and the dev stack
 * publishes the app port on every interface — until 2026-11-03 anyone on the
 * same Wi-Fi read the graph and ran the Explorer sandbox. A dev stack used
 * from another machine on purpose sets SEMITEXA_OBSERVATORY_TOKEN, and a
 * request carrying it is let in from anywhere. Monitor mode is the new case —
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

    /**
     * The Explorer family: a full route map with handler and payload classes,
     * the OpenAPI document and a request sandbox. Those read the application's
     * internals, which monitor mode — the production mode — deliberately does
     * not expose (see ObservatoryMode::full()); the shared monitor token was
     * only ever meant to unlock the measurement panel. Dev mode only.
     */
    public function allowsDevTools(): bool
    {
        return ObservatoryMode::full() && $this->allows() && !CrossSiteRead::isCrossSiteRead(CurrentRequestStore::get());
    }


    public function allows(): bool
    {
        $mode = ObservatoryMode::resolve();
        $request = CurrentRequestStore::get();
        if ($mode === ObservatoryMode::DEV) {
            return ThisMachine::sent($request) || ($request !== null && $this->tokenMatches($request));
        }
        if ($mode !== ObservatoryMode::MONITOR || $request === null) {
            return false;
        }

        $configured = Environment::getEnvValue('SEMITEXA_OBSERVATORY_TOKEN');
        if (is_string($configured) && $configured !== '') {
            return $this->tokenMatches($request);
        }

        return $this->isDirectLoopback($request);
    }

    private function tokenMatches(Request $request): bool
    {
        $configured = Environment::getEnvValue('SEMITEXA_OBSERVATORY_TOKEN');
        $provided = $request->getHeader(self::TOKEN_HEADER);

        return is_string($configured) && $configured !== '' && is_string($provided) && hash_equals($configured, $provided);
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

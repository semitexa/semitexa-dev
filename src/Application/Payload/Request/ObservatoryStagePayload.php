<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Csrf\Attribute\CsrfExempt;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Stage mode switch for the live panel — GET reads it, POST sets it.
 *
 * CSRF-exempt because the panel has no session to bind a token to (it is a
 * self-contained page outside ssr), and because the switch is dev-only by
 * construction: the handler answers 404 wherever {@see ObservatoryPanelGate}
 * refuses, and {@see \Semitexa\Dev\Application\Service\Trace\ObservatoryStage}
 * ignores the flag outside APP_ENV=dev. The worst a forged POST can do is
 * record phase timings on a developer's own machine.
 */
#[CsrfExempt]
#[AsPublicPayload(
    path: '/__observatory/stage',
    methods: ['GET', 'POST'],
    responseWith: ResourceResponse::class,
)]
final class ObservatoryStagePayload
{
    /** `on=1` / `on=0` on POST; ignored on GET. */
    public ?bool $on = null;

    public function setOn(mixed $value): void
    {
        if ($value === null || $value === '') {
            $this->on = null;

            return;
        }

        $this->on = $value === true || $value === '1' || $value === 1 || $value === 'true' || $value === 'on';
    }
}

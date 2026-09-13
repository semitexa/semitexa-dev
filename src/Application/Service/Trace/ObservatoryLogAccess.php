<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;

/**
 * Whether log lines may be served through the Observatory at all.
 *
 * ## The decision: dev only, and nowhere else
 *
 * {@see ObservatoryPanelGate} already decides WHO may open the panel, and it
 * deliberately admits monitor mode on a production box behind a token or a
 * direct loopback connection. This is a second gate on top of that, for one
 * kind of content, and it is stricter on purpose.
 *
 * Everything else the panel serves — durations, phase shares, query counts,
 * worker occupancy — is measurement the FRAMEWORK produced about itself. Log
 * lines are the first thing it would serve that the APPLICATION produced:
 * whatever a caller happened to put in a context array. That is an open set.
 * It routinely holds identifiers, email addresses, request payload fragments
 * and, in the cases that matter most, the credential that was being validated
 * when the thing went wrong.
 *
 * ## Why not scrub and admit
 *
 * Because the scrubbing that exists does not do that job, and it would be easy
 * to believe it does. {@see RequestTracer::scrub()} truncates strings to 200
 * characters and collapses arrays and objects to type names — it is a SIZE
 * control, protecting the journal line's byte cap. A 200-character prefix of a
 * token is a token. Building a log reader on it would inherit a guarantee it
 * never made.
 *
 * A real redactor is a denylist against an open set: it catches the shapes it
 * knows and silently passes the ones it does not, on a surface reachable from
 * the public internet with one shared secret. The failure is invisible — the
 * page renders, and nobody can tell from looking at it that something leaked.
 *
 * ## What an operator does instead
 *
 * Reads the logs where they already are. Monitor mode exists so that someone
 * can see the system is alive and where it is slow, which is what the rest of
 * the panel answers. An operator who needs the lines themselves has the box:
 * `ai:ask logs --grep=<term>`, or the file. That path already has the
 * machine's own access control in front of it, which is the control this
 * content needs.
 *
 * Should this ever be relaxed, it must be relaxed with a real redactor and an
 * explicit opt-in, never by default — an exposure added by default is not
 * something a later change removes.
 */
#[AsService]
final class ObservatoryLogAccess
{
    public function allows(): bool
    {
        return ObservatoryMode::resolve() === ObservatoryMode::DEV;
    }

    /**
     * Why a refusal happened, for the panel to show instead of an empty list.
     *
     * An empty log list and a withheld log list look identical, and the first
     * reads as "nothing went wrong here" — the most misleading thing this
     * surface could say.
     */
    public function refusalReason(): string
    {
        return ObservatoryMode::resolve() === ObservatoryMode::MONITOR
            ? 'Log lines are not served in monitor mode: they carry application context, '
                . 'and this panel is reachable with a single shared token. Read them on the '
                . 'box with ai:ask logs.'
            : 'Log lines are served only when APP_ENV=dev.';
    }
}

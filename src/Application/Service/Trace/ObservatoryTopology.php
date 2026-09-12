<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Environment;
use Semitexa\Core\Exception\ConfigurationException;
use Semitexa\Core\Queue\QueueConfig;

/**
 * Which infrastructure THIS project actually runs, for the live picture.
 *
 * The panel draws a system, and a drawing states things. Naming a product
 * that is not in use is not decoration — it is a false claim, made by the one
 * surface an operator opens precisely because they do not yet know how the
 * system is wired. So every named box has to be earned, and this is where it
 * is earned.
 *
 * Neither value is re-derived here, because a third place that has to agree
 * with two others is the defect, not the fix:
 *
 * - The queue transport comes from {@see QueueConfig::defaultTransport()},
 *   which owns the whole decision — EVENTS_TRANSPORT overrides, EVENTS_ASYNC
 *   gates, and the registry decides whether NATS is even installed. It THROWS
 *   when async is demanded and nothing can carry it; that is a real state of
 *   the system and the panel says so rather than inventing a transport.
 * - The cache driver is read as an explicit opt-in and nothing more. This
 *   package does not depend on semitexa/cache and must not restate its
 *   default: an unset CACHE_DRIVER answers `null` here, and the picture draws
 *   no cache box. That is the same answer copying the default 'array' would
 *   have produced, arrived at without owning a value we would then have to
 *   keep in step.
 *
 * Read per render, not memoised: the panel is a dev surface opened for
 * minutes at a time, and a reload after an .env change should tell the truth
 * rather than the truth as of worker start.
 */
#[AsService]
final class ObservatoryTopology
{
    /**
     * The transport async events actually cross, or `unavailable` when one is
     * demanded and none can be built.
     *
     * `in-memory` is the default answer and means there is no asynchronous
     * boundary at all: events never leave the request.
     */
    public function queueTransport(): string
    {
        try {
            return QueueConfig::defaultTransport();
        } catch (ConfigurationException) {
            return 'unavailable';
        }
    }

    /**
     * The cache driver this project asked for, or null when it never asked.
     */
    public function cacheDriver(): ?string
    {
        $driver = Environment::getEnvValue('CACHE_DRIVER');

        return $driver === null || trim($driver) === '' ? null : trim($driver);
    }
}

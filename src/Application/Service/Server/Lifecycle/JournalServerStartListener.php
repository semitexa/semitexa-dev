<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Dev\Application\Service\Trace\ObservatoryJournal;
use Semitexa\Dev\Application\Service\Trace\ObservatoryMode;

/**
 * Tells the Observatory journal that the server on this host is starting: every
 * process it showed as running for this host belonged to the previous run, and
 * none of them will ever write its end. Runs in the master, before any fork.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::PreStart->value,
    requiresContainer: false,
)]
final class JournalServerStartListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        if (!ObservatoryMode::journals()) {
            return;
        }
        ObservatoryJournal::writeDetached([
            'ts' => date('c'),
            'event' => ObservatoryJournal::EVENT_SERVER_START,
            'host' => ObservatoryJournal::host(),
        ]);
    }
}

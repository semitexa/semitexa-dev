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
 * Tells the Observatory journal this worker is stopping (a reload, a shutdown):
 * a session it still holds is cut with it, whether or not its end gets written.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStop->value,
    requiresContainer: false,
)]
final class JournalWorkerStopListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        if (!ObservatoryMode::journals()) {
            return;
        }
        ObservatoryJournal::write([
            'ts' => date('c'),
            'event' => ObservatoryJournal::EVENT_WORKER_STOP,
            'host' => ObservatoryJournal::host(),
            'worker' => getmypid(),
        ]);
    }
}

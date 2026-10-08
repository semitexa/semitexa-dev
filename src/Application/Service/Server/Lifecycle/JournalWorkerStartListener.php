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
 * Tells the Observatory journal a worker started under this pid: whatever an
 * earlier process with the same pid left open on this host cannot be running.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartBeforeContainer->value,
    requiresContainer: false,
)]
final class JournalWorkerStartListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        if (!ObservatoryMode::journals()) {
            return;
        }
        ObservatoryJournal::write([
            'ts' => date('c'),
            'event' => ObservatoryJournal::EVENT_WORKER_START,
            'host' => ObservatoryJournal::host(),
            'worker' => getmypid(),
        ]);
    }
}

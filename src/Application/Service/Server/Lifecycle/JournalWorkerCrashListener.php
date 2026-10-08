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
 * A worker that crashed wrote nothing on its way out; the manager, which saw it
 * die, says so on its behalf. Detached write: the manager forks the next worker.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerError->value,
    requiresContainer: false,
)]
final class JournalWorkerCrashListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        if (!ObservatoryMode::journals() || $context->workerPid === null) {
            return;
        }
        ObservatoryJournal::writeDetached([
            'ts' => date('c'),
            'event' => ObservatoryJournal::EVENT_WORKER_STOP,
            'host' => ObservatoryJournal::host(),
            'worker' => $context->workerPid,
            'crashed' => true,
        ]);
    }
}

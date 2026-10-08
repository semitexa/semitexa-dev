<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Core\Server\PageTimeline;
use Semitexa\Dev\Application\Service\Trace\ObservatoryMode;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPageTimeline;

/**
 * A scheduled job and a queue consumer run as console processes, and a signal
 * they publish is as real as one from a request — but the timeline sink was
 * only installed in Swoole workers, so the Observatory never heard them and
 * every signal looked as if a request had sent it.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::ConsoleStartAfterContainer->value,
    requiresContainer: false,
)]
final class InstallConsolePageTimelineListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        if (PageTimeline::isOn() || !ObservatoryMode::full()) {
            return;
        }
        PageTimeline::use(ObservatoryPageTimeline::forConsole());
    }
}

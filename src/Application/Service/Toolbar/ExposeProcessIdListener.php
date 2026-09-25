<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Toolbar;

use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\PipelineListenerInterface;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Dev\Application\Service\Trace\ObservatoryContext;

/**
 * Tells the dev toolbar which Observatory process served the page — in a
 * `Server-Timing` header, which a page's script reads off its own navigation
 * entry, rather than in the HTML.
 *
 * It was a data attribute first, and that made every page body unique per
 * request: two loads of `/` stopped being byte-identical, which is exactly
 * what a compression and caching check compares. The header carries the one
 * per-request fact; the document stays deterministic.
 */
// Before the handler (AuthCheck, first): the resource is the one the handler
// is given, so the header survives into a normal response.
#[AsPipelineListener(phase: AuthCheck::class, priority: -1000)]
final class ExposeProcessIdListener implements PipelineListenerInterface
{
    public const TIMING_NAME = 'sx-process';

    public function handle(RequestPipelineContext $context): void
    {
        if (!DevToolbarContributor::enabled() || !$context->resourceDto instanceof ResourceResponse) {
            return;
        }
        $id = ObservatoryContext::currentId();
        if ($id !== null) {
            $context->resourceDto->setHeader('Server-Timing', self::TIMING_NAME . ';desc="' . $id . '"');
        }
    }
}

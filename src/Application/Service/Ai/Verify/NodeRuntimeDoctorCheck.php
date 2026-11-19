<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Support\DoctorResult;

/**
 * Says before any task is open that this runtime cannot check JavaScript.
 *
 * A project that keeps its own Dockerfile from before 2026-09-02 has no node
 * (update does not replace an edited Dockerfile). There ai:verify leaves every
 * changed .js file unread, the run is incomplete, and the task that touched it
 * cannot close. Apartspace met that at the close, 19.11; this check is where it
 * is met first. A warning, not a failure: the application itself runs without
 * node, only its verification does not.
 */
#[AsDoctorCheck(name: 'dev.node-runtime', package: 'semitexa/dev')]
final class NodeRuntimeDoctorCheck implements DoctorCheckInterface
{
    public function run(): DoctorResult
    {
        return $this->evaluate(JavaScriptSyntaxCheck::findNode());
    }

    public function evaluate(?string $node): DoctorResult
    {
        return $node !== null
            ? DoctorResult::pass("node is in this runtime ({$node}): ai:verify can check changed JavaScript.")
            : DoctorResult::warn(
                'node is not in this runtime: ai:verify cannot check a changed .js file, its run stays incomplete, and a task that touched one does not close.',
                JavaScriptSyntaxCheck::NODE_FIX,
            );
    }
}

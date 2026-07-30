<?php

declare(strict_types=1);

namespace Semitexa\Dev;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * The package ships no attributes of its own, so there is nothing for a
 * mechanism-level declaration to hang on — and without this the package is
 * invisible to anyone whose project has not installed it, which is precisely
 * the audience worth telling. The convention is one `Capabilities` class per
 * package: a definite place to look, and a definite place for a guard to check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'dev.agent-tooling',
    summary: 'Generators, the project graph, and the ai:* surface an agent works through — classify, plan, verify, trace.',
    useWhen: 'Someone is building on Semitexa and the framework should answer structural questions itself instead of being grepped.',
    avoidWhen: 'A production image. These commands assume a writable working copy and belong in require-dev.',
    replaces: [
        'copying an existing module by hand and renaming its classes',
        'grepping for a class name to work out what a change would break',
        'a bespoke shell script chaining lint and test over a diff',
    ],
)]
final class Capabilities
{
}

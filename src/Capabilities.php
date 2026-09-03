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
#[Capability(
    id: 'dev.observatory',
    summary: 'Live observation of every process (requests, SSE, scheduler runs, queue messages) with deep-dive traces and sandbox replay: the /__observatory panel for humans, ai:observe ps|tail|show|replay for agents. Every traced step links to the source of the method that ran it (/__trace/node, ai:observe show --source) — read live from the working copy, never copied into the recording. SEMITEXA_OBSERVATORY_MODE=monitor gives production the journal alone — sampled lifecycles, panel behind a token or loopback tunnel, no traces, no replay.',
    useWhen: 'Debugging a Semitexa app: what is running right now, what just happened, where the milliseconds or the N+1 went, or re-running a recorded request with mutated inputs — writes rolled back, queue handoffs captured. On production, monitor mode answers the load questions: what runs, how often, how long.',
    avoidWhen: 'Deep-dive on production (traces, deep context and replay stay dev-only — monitor mode is journal lifecycles only) or profiling for microbenchmarks — spans are semantic framework steps, not a sampling profiler.',
    replaces: [
        'grepping logs to reconstruct what a request did',
        'var_dump-and-restart cycles to see a payload or a query',
        'hand-built scripts that re-send a request to reproduce a case',
    ],
)]
final class Capabilities
{
}

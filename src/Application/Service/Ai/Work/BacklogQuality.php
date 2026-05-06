<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

/**
 * Derived quality classification for an epic or task. Not persisted —
 * computed by {@see BacklogHygiene} from the current metadata on every
 * listing.
 *
 *   CLEAN — meets the promotion bar for the human-facing backlog.
 *   DRAFT — missing metadata (no goal, no next-step, empty scope) but still
 *           a plausible work item; surfaced on `--scope=drafts`.
 *   JUNK  — placeholder id, placeholder title, orphan — hidden from the
 *           active view by default, surfaced on `--scope=junk` so the
 *           operator can triage via `ai:backlog clean`.
 */
enum BacklogQuality: string
{
    case CLEAN = 'clean';
    case DRAFT = 'draft';
    case JUNK  = 'junk';
}

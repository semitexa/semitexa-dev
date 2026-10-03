<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Evidence;

/**
 * What the evidence was made from. Declared by whoever records it — there is
 * no default, because "I did not say" must not read as "synthetic".
 */
enum EvidenceData: string
{
    /** Seeded, invented data: a fake tenant, fixture rows, a demo account. */
    case Synthetic = 'synthetic';
    /** Anything else: a real database, a real inbox, this codebase itself. */
    case Real = 'real';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $d): string => $d->value, self::cases());
    }
}

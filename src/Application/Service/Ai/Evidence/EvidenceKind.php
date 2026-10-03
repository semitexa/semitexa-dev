<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Evidence;

/** What a piece of review evidence is: it decides what a publication must prove. */
enum EvidenceKind: string
{
    case Screenshot = 'screenshot';
    case Recording = 'recording';
    case Trace = 'trace';
    case Log = 'log';
    case GraphExport = 'graph-export';
    case Report = 'report';

    /** Pixels show whatever was on screen; no pattern can read them. */
    public function isVisual(): bool
    {
        return $this === self::Screenshot || $this === self::Recording;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}

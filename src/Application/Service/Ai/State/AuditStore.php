<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Audits at `<projectRoot>/var/ai-work/audits.ndjson`, one per line, never
 * rewritten: what was looked at is a record, like the backlog beside it.
 */
#[AsService]
final class AuditStore
{
    public const PATH = 'var/ai-work/audits.ndjson';

    public function record(AuditRecord $audit): void
    {
        $file = ProjectRoot::get() . '/' . self::PATH;
        if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) {
            throw new \RuntimeException('cannot create ' . dirname(self::PATH));
        }
        $line = json_encode($audit->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('cannot write ' . self::PATH);
        }
    }

    /** @return list<AuditRecord> oldest first */
    public function all(): array
    {
        $file = ProjectRoot::get() . '/' . self::PATH;
        if (!is_file($file)) {
            return [];
        }
        $audits = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $number => $line) {
            $audit = AuditRecord::fromStored(json_decode($line, true));
            if ($audit === null) {
                StaticLoggerBridge::warning('dev', 'Skipping unreadable audit record', ['file' => self::PATH, 'line' => $number + 1]);
                continue;
            }
            $audits[] = $audit;
        }

        return $audits;
    }
}

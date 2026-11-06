<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

use Semitexa\Core\Attribute\AsService;
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
        // A hand-edit can leave no last newline, and this line would glue onto it.
        $tail = is_file($file) && filesize($file) > 0 ? (string) @file_get_contents($file, false, null, (int) filesize($file) - 1) : "\n";
        if (@file_put_contents($file, ($tail === "\n" ? '' : "\n") . $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('cannot write ' . self::PATH);
        }
        // Open to everyone: the container may run as root and the host as its user.
        @chmod($file, 0666);
    }

    /** @return list<AuditRecord> oldest first */
    public function all(): array
    {
        $file = ProjectRoot::get() . '/' . self::PATH;
        if (!file_exists($file) && !is_link($file)) {
            return [];
        }
        if (!is_file($file)) {
            throw new \RuntimeException(self::PATH . ' is not a file');
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException('cannot read ' . self::PATH);
        }
        $audits = [];
        foreach ($lines as $number => $line) {
            // Skipped, a damaged line could leave an older audit saying nothing is open.
            $audit = AuditRecord::fromStored(json_decode($line, true));
            if ($audit === null) {
                throw new \RuntimeException(self::PATH . ' line ' . ($number + 1) . ' does not read: repair or remove it');
            }
            $audits[] = $audit;
        }

        return $audits;
    }
}

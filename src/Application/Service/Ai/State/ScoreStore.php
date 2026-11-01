<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Work\JsonFile;

/**
 * The scores at `<projectRoot>/var/ai-work/state/scores.json`, one per
 * criterion, versioned with the project next to its charter and backlog. A
 * file that does not read as scores fails: a state read from a broken file
 * would show "unknown" for everything and look like nothing was ever measured.
 */
#[AsService]
final class ScoreStore
{
    public const PATH = 'var/ai-work/state/scores.json';
    public const SCHEMA_VERSION = 'semitexa.ai-state-scores/v1';

    /** @return array<string, ScoreRecord> by criterion id */
    public function all(): array
    {
        $path = $this->path();
        // A link to nothing is a broken file, not "never measured".
        if (!file_exists($path) && !is_link($path)) {
            return [];
        }
        try {
            $data = JsonFile::read($path);
            if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION || !is_array($data['scores'] ?? null)) {
                throw new \RuntimeException('not a ' . self::SCHEMA_VERSION . ' file');
            }
            $records = [];
            foreach ($data['scores'] as $id => $row) {
                if (!is_array($row)) {
                    throw new \RuntimeException("score for {$id} must be an object");
                }
                /** @var array<string, mixed> $row */
                $record = ScoreRecord::fromArray($row);
                if ($record->criterionId !== $id) {
                    throw new \RuntimeException("the score filed under {$id} is for {$record->criterionId}");
                }
                $records[$id] = $record;
            }
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(self::PATH . ': ' . $e->getMessage() . ' (repair or remove that file by hand; no score is recorded over it)', 0, $e);
        }

        return $records;
    }

    /**
     * Records one score under a lock, checked against the rubric first: an
     * unknown criterion or points outside its weight are never written.
     */
    public function record(ScoreRecord $record, Rubric $rubric): void
    {
        $criterion = $rubric->criterion($record->criterionId)
            ?? throw new \InvalidArgumentException("\"{$record->criterionId}\" is no criterion of this rubric");
        if ($record->points < 0 || $record->points > $criterion->weight) {
            throw new \InvalidArgumentException("\"{$record->criterionId}\" scores 0 to {$criterion->weight}, not {$record->points}");
        }
        ScoreRecord::fromArray($record->toArray());

        $dir = dirname($this->path());
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create ' . dirname(self::PATH));
        }
        $lock = @fopen($this->path() . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('cannot lock ' . self::PATH);
        }
        try {
            // Checked again under the lock: an expiry that passed while this waited is refused.
            if (!$record->isCurrent(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
                throw new \InvalidArgumentException("the score for \"{$record->criterionId}\" expired before it could be recorded");
            }
            $records = $this->all();
            $records[$record->criterionId] = $record;
            ksort($records);
            JsonFile::writeAtomic($this->path(), [
                'schema_version' => self::SCHEMA_VERSION,
                'scores' => array_map(static fn (ScoreRecord $r): array => $r->toArray(), $records),
            ]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function path(): string
    {
        return ProjectRoot::get() . '/' . self::PATH;
    }
}

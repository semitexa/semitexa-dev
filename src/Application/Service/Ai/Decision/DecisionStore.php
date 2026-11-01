<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Decision;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Work\JsonFile;
use Semitexa\Dev\Application\Service\Ai\Work\WorkId;

/**
 * The owner's decisions under `<projectRoot>/var/ai-work/decisions/`, one
 * `<id>.json` each, versioned with the project next to its charter and
 * backlog. A file that does not read as a decision fails the listing: a
 * decision skipped silently is an approval nobody can see was given.
 */
#[AsService]
final class DecisionStore
{
    public const SUBDIR = 'var/ai-work/decisions';

    public function exists(string $id): bool
    {
        WorkId::assertValid($id, 'decision id');

        return is_file($this->pathFor($id));
    }

    public function get(string $id): Decision
    {
        WorkId::assertValid($id, 'decision id');
        $path = $this->pathFor($id);
        if (!is_file($path)) {
            throw new \RuntimeException("decision '{$id}' does not exist");
        }

        return self::read($path);
    }

    public function save(Decision $decision): void
    {
        WorkId::assertValid($decision->id, 'decision id');
        JsonFile::writeAtomic($this->pathFor($decision->id), $decision->toFileArray());
    }

    /**
     * Runs a read-modify-write under one lock for the whole directory, so two
     * agents answering at once cannot both spend a one-time approval, nor
     * pick the same new id and overwrite each other.
     *
     * @template T
     * @param callable(): T $change
     * @return T
     */
    public function locked(callable $change): mixed
    {
        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create ' . self::SUBDIR);
        }
        $lock = @fopen($dir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('cannot lock ' . self::SUBDIR);
        }
        try {
            return $change();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<Decision> oldest first */
    public function list(): array
    {
        $files = glob($this->dir() . '/*.json');
        if ($files === false) {
            throw new \RuntimeException('cannot list ' . self::SUBDIR);
        }
        $decisions = array_map(self::read(...), $files);
        usort($decisions, static fn (Decision $a, Decision $b): int => [$a->decidedAt, $a->id] <=> [$b->decidedAt, $b->id]);

        return $decisions;
    }

    public function pathFor(string $id): string
    {
        return $this->dir() . '/' . $id . '.json';
    }

    private function dir(): string
    {
        return ProjectRoot::get() . '/' . self::SUBDIR;
    }

    private static function read(string $path): Decision
    {
        try {
            $decision = Decision::fromArray(JsonFile::read($path));
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(self::SUBDIR . '/' . basename($path) . ': ' . $e->getMessage(), 0, $e);
        }
        // Saved under its id: one named otherwise would be written to a file
        // nobody reads, and the one listed would never change.
        if ($decision->id . '.json' !== basename($path)) {
            throw new \RuntimeException(self::SUBDIR . '/' . basename($path) . ": holds decision '{$decision->id}'; the file is named after its id");
        }

        return $decision;
    }
}

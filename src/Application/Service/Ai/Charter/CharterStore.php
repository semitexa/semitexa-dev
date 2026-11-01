<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Charter;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Work\JsonFile;

/**
 * The charter at `<projectRoot>/var/ai-work/charter.json`, next to the
 * backlog and versioned with the project like it: the owner's direction
 * travels with the code it directs.
 */
#[AsService]
final class CharterStore
{
    public const PATH = 'var/ai-work/charter.json';

    /** No file is a charter nobody was asked about yet; a broken one is an error. */
    public function get(): Charter
    {
        $path = $this->path();
        if (!file_exists($path)) {
            return new Charter();
        }
        if (!is_file($path)) {
            // A directory there is not "nobody was asked": it would restart the interview.
            throw new \RuntimeException(self::PATH . ': not a file');
        }

        try {
            return Charter::fromArray(JsonFile::read($path));
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(self::PATH . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Runs a read-modify-write under a lock beside the charter, so two agents
     * recording answers at once do not lose one.
     *
     * @template T
     * @param callable(): T $change
     * @return T
     */
    public function locked(callable $change): mixed
    {
        $dir = dirname($this->path());
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create ' . dirname(self::PATH));
        }
        $lock = @fopen($this->path() . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('cannot lock ' . self::PATH);
        }
        try {
            return $change();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function save(Charter $charter): void
    {
        JsonFile::writeAtomic($this->path(), $charter->toArray());
    }

    public function path(): string
    {
        return ProjectRoot::get() . '/' . self::PATH;
    }
}

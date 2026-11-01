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
        if (!is_file($path)) {
            return new Charter();
        }

        try {
            return Charter::fromArray(JsonFile::read($path));
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(self::PATH . ': ' . $e->getMessage(), 0, $e);
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

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Contract;

use Semitexa\Dev\Generation\Data\GenerationResult;
use Semitexa\Dev\Generation\Data\PlannedFile;

interface FileWriterInterface
{
    /**
     * @param list<PlannedFile> $files
     */
    public function write(array $files, bool $force = false): GenerationResult;
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Contract;

use Semitexa\Dev\Application\Service\Generation\Data\GenerationResult;
use Semitexa\Dev\Application\Service\Generation\Data\PlannedFile;

interface FileWriterInterface
{
    /**
     * @param list<PlannedFile> $files
     */
    public function write(array $files, bool $force = false): GenerationResult;
}

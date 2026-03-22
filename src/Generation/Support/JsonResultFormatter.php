<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Support;

use Semitexa\Dev\Generation\Data\GenerationResult;

final class JsonResultFormatter
{
    public function format(GenerationResult $result): string
    {
        return json_encode([
            'artifact' => 'semitexa-dev.generation-result/v1',
            'generated_at' => date('c'),
            'result' => $result->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

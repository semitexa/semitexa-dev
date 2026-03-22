<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Support;

use Semitexa\Dev\Generation\Data\GenerationPlan;
use Semitexa\Dev\Generation\Data\GenerationResult;

final class LlmHintsFormatter
{
    /**
     * @param string $hintType e.g. payload_scaffold, resource_scaffold, page_scaffold
     * @param GenerationResult $result
     * @param array<string, mixed> $metadata Additional hint metadata
     */
    public function format(
        string $hintType,
        GenerationResult $result,
        array $metadata = [],
    ): string {
        $hints = [
            'artifact' => 'semitexa-dev.llm-hints/v1',
            'generated_at' => date('c'),
            'hint_type' => $hintType,
            'result' => $result->toArray(),
        ];

        if (isset($metadata['fill_targets'])) {
            $hints['fill_targets'] = $metadata['fill_targets'];
        }

        if (isset($metadata['facts'])) {
            $hints['facts'] = $metadata['facts'];
        }

        if (isset($metadata['constraints'])) {
            $hints['constraints'] = $metadata['constraints'];
        }

        if (isset($metadata['suggested_next_prompt'])) {
            $hints['suggested_next_prompt'] = $metadata['suggested_next_prompt'];
        }

        return json_encode($hints, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

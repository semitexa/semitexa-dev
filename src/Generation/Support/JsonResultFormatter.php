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
            'next_command' => $this->buildNextCommands($result),
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<array{cmd: string, args: list<string>, why: string}>
     */
    private function buildNextCommands(GenerationResult $result): array
    {
        $out = [];

        if ($result->status === 'dry_run') {
            $out[] = [
                'cmd'  => $result->command,
                'args' => ['--write', '--json'],
                'why'  => 'commit the planned files (dry-run succeeded)',
            ];
            return $out;
        }

        if ($result->conflicts !== []) {
            $out[] = [
                'cmd'  => $result->command,
                'args' => ['--force', '--json'],
                'why'  => 'overwrite existing files after you\'ve reviewed the conflicts',
            ];
            $out[] = [
                'cmd'  => 'ai:verify',
                'args' => ['--files=' . implode(',', $result->conflicts), '--json'],
                'why'  => 'check current state of conflicting files before --force',
            ];
            return $out;
        }

        if ($result->created !== []) {
            $out[] = [
                'cmd'  => 'ai:verify',
                'args' => ['--files=' . implode(',', $result->created), '--json'],
                'why'  => 'verify the newly generated files',
            ];

            $addsRoute = str_starts_with($result->command, 'make:page')
                || str_starts_with($result->command, 'make:payload');
            if ($addsRoute) {
                $out[] = [
                    'cmd'  => 'routes:list',
                    'args' => [],
                    'why'  => 'confirm the new route is discovered',
                ];
            }

            $addsContract = str_starts_with($result->command, 'make:contract');
            if ($addsContract) {
                $out[] = [
                    'cmd'  => 'contracts:list',
                    'args' => ['--json'],
                    'why'  => 'confirm the new contract is registered',
                ];
            }
        }

        return $out;
    }
}

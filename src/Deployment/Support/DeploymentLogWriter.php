<?php

declare(strict_types=1);

namespace Semitexa\Dev\Deployment\Support;

final class DeploymentLogWriter
{
    /**
     * @param array<string, mixed> $payload
     */
    public function write(string $projectRoot, array $payload): void
    {
        $dir = $projectRoot . '/var/log/deployments';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = sprintf('%s/%s.json', $dir, gmdate('Y-m-d\THis\Z'));
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
}

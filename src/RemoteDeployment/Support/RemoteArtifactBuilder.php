<?php

declare(strict_types=1);

namespace Semitexa\Dev\RemoteDeployment\Support;

use Semitexa\Dev\RemoteDeployment\Data\RemoteDeployArtifact;

final class RemoteArtifactBuilder
{
    /**
     * @var list<string>
     */
    private array $excludedPaths = [
        '.git',
        'node_modules',
        'vendor',
        '.phpunit.cache',
        'var/cache',
        'var/log',
    ];

    public function build(string $projectRoot): RemoteDeployArtifact
    {
        $artifactDir = sys_get_temp_dir() . '/semitexa-remote-artifacts';
        if (!is_dir($artifactDir) && !mkdir($artifactDir, 0777, true) && !is_dir($artifactDir)) {
            throw new \RuntimeException('Failed to create temporary artifact directory.');
        }

        $artifactPath = sprintf(
            '%s/%s-%s.tar.gz',
            $artifactDir,
            basename($projectRoot),
            gmdate('YmdHis'),
        );

        $command = array_merge(
            ['tar', '-czf', $artifactPath],
            $this->buildExcludeArgs(),
            ['-C', $projectRoot, '.'],
        );

        $this->run($command);

        if (!is_file($artifactPath)) {
            throw new \RuntimeException('Remote deployment artifact was not created.');
        }

        $sha256 = hash_file('sha256', $artifactPath);
        if ($sha256 === false) {
            throw new \RuntimeException('Failed to hash remote deployment artifact.');
        }

        return new RemoteDeployArtifact(
            path: $artifactPath,
            sizeBytes: filesize($artifactPath) ?: 0,
            sha256: $sha256,
        );
    }

    /**
     * @return list<string>
     */
    private function buildExcludeArgs(): array
    {
        $args = [];
        foreach ($this->excludedPaths as $path) {
            $args[] = '--exclude=' . $path;
        }

        return $args;
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command): void
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptor, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start tar process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException("Artifact build failed.\n" . trim($stderr . "\n" . $stdout));
        }
    }
}

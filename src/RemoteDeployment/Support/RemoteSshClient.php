<?php

declare(strict_types=1);

namespace Semitexa\Dev\RemoteDeployment\Support;

use Semitexa\Dev\RemoteDeployment\Data\RemoteDeployTarget;
use Semitexa\Dev\RemoteDeployment\Data\SshCommandResult;

final class RemoteSshClient
{
    public function probeBatchAuth(RemoteDeployTarget $target, int $port): SshCommandResult
    {
        return $this->execute(
            $this->buildSshCommand($target, $port, 'printf __SEMITEXA__', true),
            null,
        );
    }

    public function run(RemoteDeployTarget $target, int $port, string $command, ?string $password = null): SshCommandResult
    {
        return $this->execute(
            $this->buildSshCommand($target, $port, $command, $password === null),
            $password,
        );
    }

    public function requiresPassword(SshCommandResult $result): bool
    {
        $text = strtolower($result->stderr . "\n" . $result->stdout);
        return str_contains($text, 'permission denied')
            || str_contains($text, 'password')
            || str_contains($text, 'publickey');
    }

    /**
     * @param list<string> $command
     */
    private function execute(array $command, ?string $password): SshCommandResult
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = null;
        if ($password !== null) {
            array_unshift($command, 'sshpass', '-e');
            $env = array_merge($_ENV, $_SERVER, ['SSHPASS' => $password]);
        }

        $process = proc_open($command, $descriptor, $pipes, null, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start SSH process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return new SshCommandResult(
            exitCode: $exitCode,
            stdout: trim((string) $stdout),
            stderr: trim((string) $stderr),
        );
    }

    /**
     * @return list<string>
     */
    private function buildSshCommand(RemoteDeployTarget $target, int $port, string $command, bool $batchMode): array
    {
        return [
            'ssh',
            '-p',
            (string) $port,
            '-o',
            $batchMode ? 'BatchMode=yes' : 'BatchMode=no',
            '-o',
            'StrictHostKeyChecking=accept-new',
            $target->toConnectionString(),
            'sh -lc ' . escapeshellarg($command),
        ];
    }
}

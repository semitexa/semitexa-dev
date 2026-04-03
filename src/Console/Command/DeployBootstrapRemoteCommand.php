<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attributes\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\RemoteDeployment\Data\RemoteDeployTarget;
use Semitexa\Dev\RemoteDeployment\Support\LocalPrerequisiteChecker;
use Semitexa\Dev\RemoteDeployment\Support\RemoteArtifactBuilder;
use Semitexa\Dev\RemoteDeployment\Support\RemoteBootstrapLogWriter;
use Semitexa\Dev\RemoteDeployment\Support\RemoteDeployConfigLoader;
use Semitexa\Dev\RemoteDeployment\Support\RemoteDeployEnvWriter;
use Semitexa\Dev\RemoteDeployment\Support\RemoteDeployTargetParser;
use Semitexa\Dev\RemoteDeployment\Support\RemoteOsReleaseParser;
use Semitexa\Dev\RemoteDeployment\Support\RemotePathInspector;
use Semitexa\Dev\RemoteDeployment\Support\RemoteScenarioResolver;
use Semitexa\Dev\RemoteDeployment\Support\RemoteSshClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'deploy:bootstrap-remote', description: 'Validate and prepare a first remote Semitexa deployment target (Ubuntu 20.04+ only)')]
final class DeployBootstrapRemoteCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Explicit remote target in user@host format')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Remote deployment path override')
            ->addOption('force-reinitialize', null, InputOption::VALUE_NONE, 'Allow bootstrap validation against an already initialized remote path')
            ->addOption('remote-env-file', null, InputOption::VALUE_REQUIRED, 'Reserved for future remote production env file support')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output remote bootstrap preflight result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->getProjectRoot();
        $config = (new RemoteDeployConfigLoader())->load($projectRoot);
        $target = $this->resolveTarget($input, $io, $projectRoot, $config->targets);
        $path = trim((string) ($input->getOption('path') ?: $config->deployPath));

        try {
            $this->assertInteractive($input);
            $this->confirmDestructiveIntent($input, $io, $target, $path);

            $checker = new LocalPrerequisiteChecker();
            $missing = $checker->missingBaseTools();
            if ($missing !== []) {
                throw new \RuntimeException('Missing local prerequisites: ' . implode(', ', $missing));
            }

            $sshClient = new RemoteSshClient();
            $password = null;
            $probe = $sshClient->probeBatchAuth($target, $config->sshPort);
            $authMode = 'ssh-key';

            if (!$probe->isSuccess()) {
                if (!$sshClient->requiresPassword($probe)) {
                    throw new \RuntimeException('SSH connection failed: ' . $this->bestError($probe->stderr, $probe->stdout));
                }

                if (!$checker->hasSshpass()) {
                    throw new \RuntimeException('SSH password auth is required, but sshpass is not installed locally.');
                }

                $password = $io->askHidden('SSH password for ' . $target->toConnectionString());
                if ($password === null || $password === '') {
                    throw new \RuntimeException('SSH password entry was cancelled.');
                }

                $passwordProbe = $sshClient->run($target, $config->sshPort, 'printf __SEMITEXA__', $password);
                if (!$passwordProbe->isSuccess()) {
                    throw new \RuntimeException('SSH password authentication failed: ' . $this->bestError($passwordProbe->stderr, $passwordProbe->stdout));
                }

                $authMode = 'password';
            }

            $osResult = $sshClient->run($target, $config->sshPort, 'cat /etc/os-release', $password);
            if (!$osResult->isSuccess()) {
                throw new \RuntimeException('Failed to read remote OS information: ' . $this->bestError($osResult->stderr, $osResult->stdout));
            }

            $osInfo = (new RemoteOsReleaseParser())->parse($osResult->stdout);
            $scenarioPath = (new RemoteScenarioResolver())->resolve($osInfo, dirname(__DIR__, 3));
            $pathState = (new RemotePathInspector($sshClient))->inspect($target, $config->sshPort, $path, $password);

            if ($pathState->isInitialized() && !$input->getOption('force-reinitialize')) {
                throw new \RuntimeException(sprintf(
                    'Remote path "%s" already looks initialized. Re-run with --force-reinitialize only if you intend to destroy existing deployment state.',
                    $path,
                ));
            }

            if ($pathState->isInitialized()) {
                $confirmed = $io->confirm(
                    sprintf('Remote path "%s" already looks initialized. Continue with force-reinitialize intent?', $path),
                    false,
                );
                if (!$confirmed) {
                    throw new \RuntimeException('Remote reinitialization was cancelled by the operator.');
                }
            }

            $artifact = (new RemoteArtifactBuilder())->build($projectRoot);

            $result = [
                'status' => 'ready',
                'phase' => 'phase-2-artifact-ready',
                'target' => $target->toConnectionString(),
                'path' => $path,
                'auth_mode' => $authMode,
                'remote_os' => [
                    'id' => $osInfo->id,
                    'version_id' => $osInfo->versionId,
                    'pretty_name' => $osInfo->prettyName,
                ],
                'scenario_path' => $scenarioPath,
                'path_state' => [
                    'exists' => $pathState->exists,
                    'has_files' => $pathState->hasFiles,
                    'has_marker' => $pathState->hasMarker,
                    'has_compose_file' => $pathState->hasComposeFile,
                ],
                'artifact' => [
                    'path' => $artifact->path,
                    'size_bytes' => $artifact->sizeBytes,
                    'sha256' => $artifact->sha256,
                ],
                'reason' => 'Remote first-deployment preflight passed and a deploy artifact was built locally. Remote upload and bootstrap execution are not implemented yet.',
            ];
            $result['log_path'] = (new RemoteBootstrapLogWriter())->write($projectRoot, $result);

            if ($input->getOption('json')) {
                $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return Command::SUCCESS;
            }

            $io->title('Semitexa Remote First Deployment');
            $io->warning('This command is for first deployment only. It is not a safe in-place update mechanism.');
            $io->definitionList(
                ['Target' => $result['target']],
                ['Path' => $result['path']],
                ['Auth mode' => $result['auth_mode']],
                ['Remote OS' => ($osInfo->prettyName ?? ($osInfo->id . ' ' . $osInfo->versionId))],
                ['Scenario' => $result['scenario_path']],
                ['Phase' => $result['phase']],
                ['Artifact' => $artifact->path],
                ['Log' => $result['log_path']],
            );
            $io->text($result['reason']);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            if ($input->getOption('json')) {
                $output->writeln(json_encode([
                    'status' => 'blocked',
                    'phase' => 'phase-1-preflight',
                    'reason' => $e->getMessage(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return Command::FAILURE;
            }

            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * @param list<RemoteDeployTarget> $configuredTargets
     */
    private function resolveTarget(InputInterface $input, SymfonyStyle $io, string $projectRoot, array $configuredTargets): RemoteDeployTarget
    {
        $parser = new RemoteDeployTargetParser();
        $optionTarget = $input->getOption('target');
        if (is_string($optionTarget) && trim($optionTarget) !== '') {
            return $parser->parseOne($optionTarget);
        }

        if ($configuredTargets === []) {
            $this->assertInteractive($input);
            $raw = $io->ask('No remote deploy target is configured. Enter a target in user@host format');
            if (!is_string($raw) || trim($raw) === '') {
                throw new \RuntimeException('Remote deploy target was not provided.');
            }

            $target = $parser->parseOne($raw);
            if ($io->confirm('Append this target to .env.local for future use?', true)) {
                (new RemoteDeployEnvWriter())->appendTarget($projectRoot, $target->toConnectionString());
            }

            return $target;
        }

        if (count($configuredTargets) === 1) {
            return $configuredTargets[0];
        }

        $this->assertInteractive($input);
        $choices = array_map(
            static fn(RemoteDeployTarget $target): string => $target->toConnectionString(),
            $configuredTargets,
        );
        $selected = $io->choice('Select a remote target for first deployment', $choices);
        return $parser->parseOne($selected);
    }

    private function confirmDestructiveIntent(InputInterface $input, SymfonyStyle $io, RemoteDeployTarget $target, string $path): void
    {
        $io->warning([
            'Remote first deployment is destructive.',
            'This command is not an update workflow.',
            sprintf('Running it against an already-used target may overwrite files or destroy data under "%s".', $path),
            sprintf('Selected target: %s', $target->toConnectionString()),
        ]);

        $typed = $io->ask('Type "DEPLOY NEW SERVER" to continue');
        if ($typed !== 'DEPLOY NEW SERVER') {
            throw new \RuntimeException('Remote bootstrap confirmation failed.');
        }
    }

    private function assertInteractive(InputInterface $input): void
    {
        if (!$input->isInteractive()) {
            throw new \RuntimeException('deploy:bootstrap-remote requires an interactive TTY in phase 1.');
        }
    }

    private function bestError(string $stderr, string $stdout): string
    {
        return trim($stderr) !== '' ? trim($stderr) : trim($stdout);
    }
}

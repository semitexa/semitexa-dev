<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\ScaffoldSyncCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * scaffold:sync before and after step 2 of one source for bin/semitexa, when
 * semitexa/update stops shipping its own copy of the runtime files and reads
 * them, with their manifest, from semitexa/installer.
 *
 * The check used to diff every installer file against update's copy; after the
 * switch it would have called each one missing. It now asks update's rebuild
 * --check, and the installer's own tools answer for the manifest update reads.
 * The sibling commands and the tools are stand-ins that record how they ran.
 */
final class ScaffoldSyncCommandTest extends TestCase
{
    private const INFRA = ['bin/semitexa', 'Dockerfile', 'docker-compose.yml', 'docker-compose.ollama.yml', 'docker-compose.worker.yml'];

    private string $root = '';
    private ?string $originalCwd = null;

    /** @var list<string> how each sibling ran, e.g. "update:scaffold:rebuild --check" */
    private array $siblingRuns = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-scaffold-sync-' . bin2hex(random_bytes(4));
        // composer.json and src/modules are what ProjectRoot takes for a project:
        // without both it falls back to the workspace, and a write would sync that.
        mkdir($this->root . '/src/modules', 0755, true);
        file_put_contents($this->root . '/composer.json', '{"name":"temp/project"}');
        foreach (self::INFRA as $file) {
            foreach (['packages/semitexa-installer/scaffold/', 'packages/semitexa-ultimate/'] as $copy) {
                $this->write($copy . $file, "# {$file}\n");
            }
        }
        $this->write('bin/semitexa', "# bin/semitexa\n");
        $this->write('Dockerfile', "# Dockerfile\n");
        // After the switch: update's own copy holds only the agent docs.
        $this->write('packages/semitexa-update/resources/scaffold/AGENTS.md', "# agents\n");
        $this->updateManifest(['AGENTS.md']);
        $this->originalCwd = getcwd() ?: null;
        chdir($this->root);
        ProjectRoot::reset();
        self::assertSame(realpath($this->root), realpath(ProjectRoot::get()), 'the command would run on another project than the fixture');
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            chdir($this->originalCwd);
        }
        ProjectRoot::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function test_after_the_switch_an_update_copy_of_agent_docs_alone_is_in_sync(): void
    {
        $this->tool('check-manifest.php', 0, 'describes scaffold/');

        [$status, $display] = $this->sync(['--check' => true]);

        self::assertSame(0, $status, $display);
        self::assertStringNotContainsString('semitexa-update mirror', $display);
        self::assertContains('update:scaffold:rebuild --check', $this->siblingRuns, 'the check asks update whether its copy and manifest are current');
        self::assertStringContainsString('ok     installer manifest: check-manifest.php', $display);
        self::assertStringNotContainsString('derive-manifest', $display, 'nothing to derive once update lists none of the installer files');
    }

    public function test_a_manifest_the_installer_check_refuses_is_drift_with_its_findings(): void
    {
        $this->tool('check-manifest.php', 1, 'bin/semitexa: the file hashes to 1111, the manifest says 2222.');

        [$status, $display] = $this->sync(['--check' => true]);

        self::assertSame(1, $status, $display);
        self::assertStringContainsString('DRIFT installer manifest: check-manifest.php exited 1', $display);
        self::assertStringContainsString('bin/semitexa: the file hashes to 1111', $display);
    }

    public function test_an_update_copy_the_rebuild_check_refuses_is_drift(): void
    {
        $this->tool('check-manifest.php', 0, 'describes scaffold/');

        [$status, $display] = $this->sync(['--check' => true], rebuildExit: 1);

        self::assertSame(1, $status, $display);
        self::assertStringContainsString('1 scaffold copy is out of sync', $display);
    }

    public function test_while_update_ships_the_runtime_entries_the_derived_manifest_is_checked_and_written(): void
    {
        $this->updateManifest(['AGENTS.md', 'bin/semitexa']);
        $this->tool('check-manifest.php', 0, 'describes scaffold/');
        $this->tool('derive-manifest.php', 0, 'is current');

        [$status, $display] = $this->sync(['--check' => true]);
        self::assertSame(0, $status, $display);
        self::assertStringContainsString('ok     installer manifest: derive-manifest.php --check', $display);

        [$status, $display] = $this->sync([]);
        self::assertSame(0, $status, $display);
        self::assertSame(['scaffold:sync-docs', 'update:scaffold:rebuild'], array_slice($this->siblingRuns, -2), 'update rebuilds first, so derive copies its fresh entries');
        self::assertStringContainsString('synced installer manifest (derive-manifest.php)', $display);
        self::assertStringContainsString('ran derive-manifest.php' . "\n", file_get_contents($this->root . '/tool-runs.log') ?: '');
    }

    public function test_a_derive_that_fails_in_a_write_stops_the_sync(): void
    {
        $this->updateManifest(['AGENTS.md', 'bin/semitexa']);
        $this->tool('derive-manifest.php', 2, "Cannot read semitexa/update's manifest");

        [$status, $display] = $this->sync([]);

        self::assertSame(1, $status, $display);
        self::assertStringContainsString('installer manifest: derive-manifest.php exited 2', $display);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{int, string}
     */
    private function sync(array $input, int $rebuildExit = 0): array
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->add($this->sibling('scaffold:sync-docs', 0));
        $application->add($this->sibling('update:scaffold:rebuild', $rebuildExit));
        // Semitexa's #[AsCommand] is read by its own registry, not by Symfony's constructor.
        $application->add((new ScaffoldSyncCommand())->setName('scaffold:sync'));
        $tester = new CommandTester($application->find('scaffold:sync'));
        $status = $tester->execute($input, ['interactive' => false, 'decorated' => false]);

        return [$status, preg_replace('/\s+\n/', "\n", $tester->getDisplay()) ?? $tester->getDisplay()];
    }

    private function sibling(string $name, int $exit): Command
    {
        $record = function (string $run): void {
            $this->siblingRuns[] = $run;
        };

        return new class ($name, $exit, $record) extends Command {
            /** @param \Closure(string): void $record */
            public function __construct(string $name, private readonly int $exit, private readonly \Closure $record)
            {
                parent::__construct($name);
            }

            protected function configure(): void
            {
                $this->addOption('check', null, InputOption::VALUE_NONE);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                ($this->record)(trim((string) $this->getName() . ($input->getOption('check') ? ' --check' : '')));

                return $this->exit;
            }
        };
    }

    /** A stand-in installer tool: logs how it ran, then answers $message with $exit. */
    private function tool(string $name, int $exit, string $message): void
    {
        $log = var_export($this->root . '/tool-runs.log', true);
        $stream = $exit === 0 ? 'STDOUT' : 'STDERR';
        $this->write('packages/semitexa-installer/tools/' . $name, sprintf(
            "<?php\nfile_put_contents(%s, 'ran ' . trim(basename(__FILE__) . ' ' . implode(' ', array_slice(\$argv, 1))) . \"\\n\", FILE_APPEND);\nfwrite(%s, %s . \"\\n\");\nexit(%d);\n",
            $log,
            $stream,
            var_export($message, true),
            $exit,
        ));
    }

    /** @param list<string> $paths */
    private function updateManifest(array $paths): void
    {
        $this->write(
            'packages/semitexa-update/resources/scaffold-manifest.json',
            json_encode(['files' => array_map(static fn (string $path): array => ['path' => $path], $paths)], JSON_THROW_ON_ERROR),
        );
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $content);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\LogsAppCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Review finding on PR #41: the rotation-aware behaviour shipped untested.
 *
 * These use the documented ProjectRoot seam — chdir into a throwaway fixture root
 * carrying composer.json + src/modules, then reset the memoized root — so the
 * command resolves var/log inside the fixture instead of the real project.
 */
final class LogsAppRotatedFileTest extends TestCase
{
    private string $root;
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        $this->root = sys_get_temp_dir() . '/semitexa-logsapp-' . uniqid();
        mkdir($this->root . '/src/modules', 0775, true);
        mkdir($this->root . '/var/log', 0775, true);
        file_put_contents($this->root . '/composer.json', '{}');

        chdir($this->root);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        ProjectRoot::reset();

        foreach (glob($this->root . '/var/log/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root . '/var/log');
        @rmdir($this->root . '/var');
        @unlink($this->root . '/composer.json');
        @rmdir($this->root . '/src/modules');
        @rmdir($this->root . '/src');
        @rmdir($this->root);
    }

    private function invoke(array $input): array
    {
        // The name lives on Semitexa's #[AsCommand] attribute, which Symfony's
        // Application does not read, so it has to be set for the tester.
        $command = new LogsAppCommand();
        $command->setName('logs:app');

        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($app->find('logs:app'));
        $exit = $tester->execute($input);

        return [$exit, $tester->getDisplay()];
    }

    /**
     * Rotation on: Swoole writes to swoole.log.<date> and leaves swoole.log empty.
     * Reading the configured name would report the server as silent.
     */
    public function test_rotation_on_reads_the_dated_file_not_the_empty_placeholder(): void
    {
        touch($this->root . '/var/log/swoole.log');
        file_put_contents($this->root . '/var/log/swoole.log.20260727', "rotated line\n");

        [$exit, $display] = $this->invoke(['--file' => 'swoole', '--json' => true]);

        self::assertSame(0, $exit);
        $decoded = json_decode(trim($display), true);
        self::assertSame('swoole.log.20260727', $decoded['file']['name'], 'must report the file actually read');
        self::assertSame(1, $decoded['total']);
    }

    public function test_rotation_single_keeps_reading_the_configured_file(): void
    {
        file_put_contents($this->root . '/var/log/swoole.log', "plain line\n");

        [$exit, $display] = $this->invoke(['--file' => 'swoole', '--json' => true]);

        self::assertSame(0, $exit);
        $decoded = json_decode(trim($display), true);
        self::assertSame('swoole.log', $decoded['file']['name']);
    }

    public function test_list_includes_rotated_files_but_not_foreign_siblings(): void
    {
        touch($this->root . '/var/log/swoole.log');
        file_put_contents($this->root . '/var/log/swoole.log.20260727', "a\n");
        file_put_contents($this->root . '/var/log/swoole.log.1.gz', "not ours\n");

        [$exit, $display] = $this->invoke(['--list' => true, '--json' => true]);

        self::assertSame(0, $exit);
        $names = array_column(json_decode(trim($display), true)['files'], 'name');
        self::assertContains('swoole.log', $names);
        self::assertContains('swoole.log.20260727', $names, 'a rotating server must not look silent');
        self::assertNotContains('swoole.log.1.gz', $names, "another tool's output is not ours to list");
    }

    /**
     * estimateLines() reads from disk per file and only the JSON envelope carries it.
     */
    public function test_the_table_listing_does_not_pay_for_line_estimates(): void
    {
        file_put_contents($this->root . '/var/log/swoole.log', str_repeat("line\n", 100));

        [$exit, $display] = $this->invoke(['--list' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('swoole.log', $display);

        [, $jsonDisplay] = $this->invoke(['--list' => true, '--json' => true]);
        $files = json_decode(trim($jsonDisplay), true)['files'];
        self::assertNotNull($files[0]['lines_estimate'], 'JSON still reports the estimate');
    }
}

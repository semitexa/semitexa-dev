<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\LintGraphExtractorsCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\GraphExtractor\GraphExtractorScan;
use Semitexa\Dev\Application\Service\Ai\Verify\LintRunner;
use Symfony\Component\Console\Tester\CommandTester;

/** lint:graph-extractors as it is run: what it reads for which options, and what it prints. */
final class LintGraphExtractorsCommandTest extends TestCase
{
    private const BAD = "<?php\nnamespace App;\nfinal class Probing\n{\n    public function extract(string \$p): bool { return is_file(\$p); }\n}\n";

    private string $root;

    private string|false $cwd;

    /** What ProjectRoot held before this test: put back as it was, since another test may have left it there on purpose. */
    private ?string $rootBefore = null;

    protected function setUp(): void
    {
        $before = (new \ReflectionProperty(ProjectRoot::class, 'root'))->getValue();
        $this->rootBefore = is_string($before) ? $before : null;
        $this->root = sys_get_temp_dir() . '/semitexa-lint-graph-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src/modules', 0777, true);
        file_put_contents($this->root . '/composer.json', '{}');
        $this->cwd = getcwd();
        chdir($this->root);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        if ($this->cwd !== false) {
            chdir($this->cwd);
        }
        (new \ReflectionProperty(ProjectRoot::class, 'root'))->setValue(null, $this->rootBefore);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_project_without_the_extractors_passes_and_says_nothing_was_scanned(): void
    {
        $tester = new CommandTester(new LintGraphExtractorsCommand());

        self::assertSame(0, $tester->execute([]));
        self::assertSame('lint:graph-extractors → nothing to scan: ' . GraphExtractorScan::DIRECTORY . " is not in this project\n", $tester->getDisplay());

        $json = new CommandTester(new LintGraphExtractorsCommand());
        self::assertSame(0, $json->execute(['--json' => true]));
        self::assertSame(['ok' => true, 'problems' => [], 'scanned' => 'nothing: ' . GraphExtractorScan::DIRECTORY . ' is not in this project'], json_decode($json->getDisplay(), true));
    }

    #[Test]
    public function with_no_file_it_reads_the_extractor_directory(): void
    {
        $this->write(GraphExtractorScan::DIRECTORY . '/Ast/Probing.php', self::BAD);
        $this->write(GraphExtractorScan::DIRECTORY . '/Clean.php', "<?php\nfinal class Clean {}\n");
        $this->write('src/Elsewhere.php', self::BAD);

        $tester = new CommandTester(new LintGraphExtractorsCommand());

        self::assertSame(1, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('  ' . GraphExtractorScan::DIRECTORY . '/Ast/Probing.php line 5: `is_file` reads the filesystem', $display);
        self::assertStringContainsString('lint:graph-extractors → 1 file(s) with a problem, first ' . GraphExtractorScan::DIRECTORY . '/Ast/Probing.php line 5: `is_file`', $display);
        self::assertStringNotContainsString('Elsewhere', $display);
    }

    #[Test]
    public function clean_extractors_pass(): void
    {
        $this->write(GraphExtractorScan::DIRECTORY . '/Clean.php', "<?php\nfinal class Clean {}\n");
        $this->write(GraphExtractorScan::TESTS . '/ConvergenceTest.php', "<?php\nfinal class ConvergenceTest\n{\n    public function a_refresh_converges(): void {}\n}\n");
        $this->write(GraphExtractorScan::DIRECTORY . '/Accepted.php', "<?php\n// verify:accept-cross-file-read ConvergenceTest::a_refresh_converges read when the graph is read\nfinal class Accepted\n{\n    public function of(string \$p): bool { return is_file(\$p); }\n}\n");

        $tester = new CommandTester(new LintGraphExtractorsCommand());

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertSame("lint:graph-extractors → no project-graph extractor reads beyond the file it extracts without an accepted convergence test\n", $tester->getDisplay());
    }

    #[Test]
    public function a_given_file_is_read_alone_and_with_all_beside_the_directory(): void
    {
        $this->write(GraphExtractorScan::DIRECTORY . '/Probing.php', self::BAD);
        $this->write('src/Elsewhere.php', self::BAD);

        self::assertSame(0, (new CommandTester(new LintGraphExtractorsCommand()))->execute(['--file' => ['composer.json']]), 'a file that is no PHP has nothing to find');

        $alone = new CommandTester(new LintGraphExtractorsCommand());
        self::assertSame(1, $alone->execute(['--file' => ['src/Elsewhere.php'], '--json' => true]));
        self::assertSame(['src/Elsewhere.php'], array_keys(self::problems($alone)));

        $beside = new CommandTester(new LintGraphExtractorsCommand());
        self::assertSame(1, $beside->execute(['--file' => ['src/Elsewhere.php'], '--all' => true, '--json' => true]));
        self::assertSame([GraphExtractorScan::DIRECTORY . '/Probing.php', 'src/Elsewhere.php'], array_keys(self::problems($beside)));
    }

    #[Test]
    public function an_empty_file_value_fails_and_scans_nothing_else(): void
    {
        $this->write(GraphExtractorScan::DIRECTORY . '/Clean.php', "<?php\nfinal class Clean {}\n");

        $tester = new CommandTester(new LintGraphExtractorsCommand());

        self::assertSame(1, $tester->execute(['--file' => ['']]));
        self::assertStringContainsString('(empty --file) no path was given', $tester->getDisplay());
    }

    #[Test]
    public function ai_verify_can_print_why_the_lint_exists(): void
    {
        $why = LintRunner::rationaleOf(new LintGraphExtractorsCommand());

        self::assertStringStartsWith('Why: the probe makes an extracted fact depend on a file the incremental engine does not re-read', $why);
        self::assertStringContainsString('Learned 2026-11-18', $why);
    }

    private function write(string $path, string $content): void
    {
        @mkdir(dirname($this->root . '/' . $path), 0777, true);
        file_put_contents($this->root . '/' . $path, $content);
    }

    /** @return array<string, list<string>> */
    private static function problems(CommandTester $tester): array
    {
        $envelope = json_decode($tester->getDisplay(), true);
        self::assertIsArray($envelope, 'the command printed JSON: ' . $tester->getDisplay());
        self::assertFalse($envelope['ok']);
        self::assertIsArray($envelope['problems']);

        /** @var array<string, list<string>> */
        return $envelope['problems'];
    }
}

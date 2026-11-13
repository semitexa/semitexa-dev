<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Console\Command\LintMechanismsCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ai:verify asks lint:mechanisms about the files a change touched
 * (tk-verify-red-on-preexisting-mechanisms). It scanned the whole project, so 13 findings no
 * change of the day had made failed every task's ai:verify in a project after an update, and
 * not one task there could be proven.
 */
final class MechanismLintChangedFilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/semitexa-mechanisms-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o755, true);
        file_put_contents($this->dir . '/old.html.twig', "<button onclick=\"go()\">Old</button>\n");
        file_put_contents($this->dir . '/clean.html.twig', "<button data-ui-on=\"click\">New</button>\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[Test]
    public function only_the_files_named_are_scanned(): void
    {
        $tester = new CommandTester(new LintMechanismsCommand());

        $tester->execute(['--file' => [$this->dir . '/clean.html.twig'], '--json' => true]);
        $clean = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $clean['count'], 'the finding in old.html.twig is not this change\'s');

        // The control: the same lint over the whole directory finds it, so the check above can fail.
        $tester->execute(['--path' => $this->dir, '--json' => true]);
        $whole = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $whole['count']);
        self::assertStringEndsWith('old.html.twig', (string) $whole['findings'][0]['file']);

        $tester->execute(['--file' => [$this->dir . '/old.html.twig', $this->dir . '/clean.html.twig'], '--json' => true]);
        self::assertSame(1, json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['count'], 'a changed file with a finding is still found');
    }
}

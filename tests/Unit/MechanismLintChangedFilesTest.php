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

    /**
     * Review, dev#251: a file that is there and cannot be read fails the lint; one that is gone (a
     * deletion handed over as a plain path) has nothing to scan; --file with --path is refused.
     */
    #[Test]
    public function an_unreadable_file_fails_and_a_gone_one_is_nothing_to_scan(): void
    {
        $tester = new CommandTester(new LintMechanismsCommand());
        $locked = $this->dir . '/locked.html.twig';
        file_put_contents($locked, "<button onclick=\"go()\">x</button>\n");
        chmod($locked, 0o000);
        if (is_readable($locked)) {
            chmod($locked, 0o644);
            $locked = $this->dir; // a directory is there and is not a readable file either
        }

        self::assertSame(1, $tester->execute(['--file' => [$locked]]));
        self::assertStringContainsString('could not be read', $tester->getDisplay());
        @chmod($this->dir . '/locked.html.twig', 0o644);

        self::assertSame(0, $tester->execute(['--file' => [$this->dir . '/gone.html.twig', $this->dir . '/clean.html.twig']]));
        self::assertStringContainsString('gone.html.twig is not there; nothing to scan', $tester->getDisplay());

        self::assertSame(1, $tester->execute(['--file' => [$this->dir . '/clean.html.twig'], '--path' => $this->dir]));

        // Review, dev#251: the notice is in the envelope, so --json stays JSON.
        $tester->execute(['--file' => [$this->dir . '/gone.html.twig'], '--json' => true]);
        $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$this->dir . '/gone.html.twig'], $decoded['not_there']);
    }

    /** Review, dev#251: a changed payload brings its unchanged handler, which a detector reads it with. */
    #[Test]
    public function a_changed_payload_brings_its_handlers(): void
    {
        $module = $this->dir . '/src/modules/Foo/src/Application';
        mkdir($module . '/Payload/Request', 0o755, true);
        mkdir($module . '/Handler/PayloadHandler', 0o755, true);
        file_put_contents($module . '/Payload/Request/GreetPayload.php', "<?php\nnamespace Probe\\Foo;\n#[AsPublicPayload(path: '/greet')]\nfinal class GreetPayload {}\n");
        file_put_contents($module . '/Handler/PayloadHandler/GreetHandler.php', "<?php\nnamespace Probe\\Foo;\n#[AsPayloadHandler(payload: GreetPayload::class)]\nfinal class GreetHandler { public function h(\$auth) { return \$auth->isGuest(); } }\n");
        file_put_contents($module . '/Handler/PayloadHandler/OtherHandler.php', "<?php\n#[AsPayloadHandler(payload: OtherPayload::class)]\nfinal class OtherHandler { public function h(\$auth) { return \$auth->isGuest(); } }\n");
        $tester = new CommandTester(new LintMechanismsCommand());

        $tester->execute(['--file' => [$module . '/Payload/Request/GreetPayload.php'], '--json' => true]);
        $files = array_column(json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['findings'], 'file');
        self::assertNotSame([], $files, 'the handler of the changed payload is read');
        self::assertSame([], array_filter($files, static fn (string $f): bool => !str_ends_with($f, 'GreetHandler.php')), 'and only that one: ' . implode(', ', $files));
    }
}

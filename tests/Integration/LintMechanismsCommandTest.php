<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Dev\Application\Console\Command\LintMechanismsCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The lint as it is actually invoked, not just its detectors.
 *
 * Added after a refactor deleted the command's detector list while every unit
 * test stayed green: they exercise the detectors directly and never enter
 * `execute()`, so the command was fatal on its first real run and the suite had
 * nothing to say about it. A rule that cannot run is worse than one that reports
 * nothing, because it reports nothing *and* looks fine.
 */
final class LintMechanismsCommandTest extends TestCase
{
    private string $fixtureDir = '';

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/mechanism-lint-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->fixtureDir . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->fixtureDir);
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->fixtureDir . '/' . $name, $contents);
    }

    private function lint(bool $json = true): CommandTester
    {
        $command = new LintMechanismsCommand();

        $discovery = new ClassDiscovery();
        $discovery->initialize();
        $p = new ReflectionProperty(LintMechanismsCommand::class, 'classDiscovery');
        $p->setAccessible(true);
        $p->setValue($command, $discovery);

        $app = new Application();
        $app->add($command);

        $tester = new CommandTester($app->find('lint:mechanisms'));
        $tester->execute(array_filter([
            '--path' => $this->fixtureDir,
            '--json' => $json ?: null,
        ]));

        return $tester;
    }

    /** @return array<string, mixed> */
    private static function json(CommandTester $t): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($t->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    #[Test]
    public function a_clean_directory_passes(): void
    {
        $this->write('fine.js', "export const x = 1;\n");

        $tester = $this->lint();

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(0, self::json($tester)['count']);
    }

    #[Test]
    public function it_runs_every_detector_in_the_set(): void
    {
        // The regression this file exists for: a refactor once removed the
        // detector list and only a manual run noticed. Asserting on all three
        // capability ids means dropping any one of them fails here.
        $this->write('region.js', "const r = await fetch('/panel');\nbox.innerHTML = await r.text();\n");
        $this->write('live.js', "const es = new EventSource('/feed');\n");
        $this->write('page.html.twig', "<button onclick=\"go()\">go</button>\n");

        $tester = $this->lint();
        $data = self::json($tester);

        self::assertSame(1, $tester->getStatusCode(), 'findings must fail the lint');
        $ids = array_column((array) $data['findings'], 'capability');
        sort($ids);

        self::assertSame(['ssr.deferred', 'ssr.transport', 'ui.behavior'], $ids);
    }

    #[Test]
    public function a_finding_carries_the_wording_from_the_capability_catalog(): void
    {
        // The pairing this whole effort rests on: the rule stores an id, the
        // advice comes from the mechanism's own declaration. If the catalog
        // stopped resolving, findings would still appear but say nothing —
        // which is the silent failure worth pinning.
        $this->write('live.js', "const es = new EventSource('/feed');\n");

        $finding = ((array) self::json($this->lint())['findings'])[0];

        self::assertSame('ssr.transport', $finding['capability']);
        self::assertNotSame('', $finding['summary'], 'summary must come from #[Capability]');
        self::assertNotSame('', $finding['avoid_when'], 'the counter-advice must survive into the finding');
        self::assertSame('WithTransport', $finding['declared_by_short']);
        self::assertStringContainsString('ai:ask mechanisms --id=ssr.transport', $finding['details_command']);
    }

    #[Test]
    public function human_output_names_the_file_the_line_and_the_way_out(): void
    {
        $this->write('live.js', "const es = new EventSource('/feed');\n");

        $display = $this->lint(json: false)->getDisplay();

        self::assertStringContainsString('live.js:1', $display);
        self::assertStringContainsString('ssr.transport', $display);
        self::assertStringContainsString('ai:ask mechanisms', $display);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Console\Command\DevGraph\DevGraphRouteCommand;
use Semitexa\Dev\Application\Service\Console\LookupRefusal;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `ai:ask route|module|event` printed a Symfony error block even under --json,
 * so an agent parsing stdout had nothing to parse and never saw which names
 * did exist.
 */
final class LookupRefusalTest extends TestCase
{
    public function test_under_json_the_refusal_is_the_commands_own_artifact(): void
    {
        $output = new BufferedOutput();
        $exit = LookupRefusal::refuse(
            $this->input(json: true),
            $output,
            'semitexa-dev.module-description/v1',
            'Module not found: Bilin',
            'Known modules',
            ['Billing', 'Catalog'],
        );

        self::assertSame(1, $exit);
        $decoded = json_decode(trim($output->fetch()), true);
        self::assertSame('semitexa-dev.module-description/v1', $decoded['artifact']);
        self::assertSame('error', $decoded['status']);
        self::assertSame(['Billing', 'Catalog'], $decoded['candidates']);
    }

    public function test_without_json_it_is_written_for_a_human(): void
    {
        $output = new BufferedOutput();
        LookupRefusal::refuse($this->input(json: false), $output, 'x/v1', 'Module not found: Bilin', 'Known modules', ['Billing']);

        $text = $output->fetch();
        self::assertNull(json_decode(trim($text), true));
        self::assertStringContainsString('Billing', $text);
    }

    public function test_closest_puts_the_likely_typo_first(): void
    {
        self::assertSame(['/login', '/logout'], LookupRefusal::closest('/logn', ['/', '/logout', '/login', '/blog/{slug}'], 2));
    }

    public function test_a_path_containing_the_request_ranks_first(): void
    {
        self::assertSame('/os/login', LookupRefusal::closest('/login', ['/blog', '/os', '/os/login'])[0]);
    }

    public function test_the_route_lookup_refuses_in_json_before_touching_discovery(): void
    {
        $tester = new CommandTester(new DevGraphRouteCommand());
        $exit = $tester->execute(['--json' => true]);

        self::assertSame(1, $exit);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        self::assertSame('semitexa-dev.route-description/v1', $decoded['artifact']);
        self::assertStringContainsString('--path', $decoded['error']);
    }

    private function input(bool $json): ArrayInput
    {
        return new ArrayInput($json ? ['--json' => true] : [], new InputDefinition([
            new InputOption('json', null, InputOption::VALUE_NONE),
        ]));
    }
}

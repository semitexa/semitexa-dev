<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Console\Command\DevBenchCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** dev:bench refuses what it cannot measure as asked, instead of measuring something else. */
final class DevBenchCommandTest extends TestCase
{
    #[Test]
    public function input_it_would_silently_change_is_refused(): void
    {
        foreach ([
            [['--route' => ['/ok', 'users']], 'is no path'],
            [['--route' => ['/ok'], '--concurrency' => '1,2abc'], 'is no whole number'],
            [['--route' => ['/ok'], '--concurrency' => '1.5'], 'is no whole number'],
            [['--route' => ['/ok'], '--concurrency' => '0'], 'is no whole number'],
            [['--route' => ['/ok'], '--concurrency' => '1025'], 'is no whole number from 1 to 1024'],
            [['--route' => ['/ok'], '--concurrency' => '99999999999999999999'], 'is no whole number from 1 to 1024'],
            [['--route' => ['/ok'], '--host' => "shop\xff.test"], '--host is not printable UTF-8'],
            [['--route' => ['/ok'], '--accept' => "text/html\r\nX-Injected: 1"], '--accept is not printable UTF-8'],
            [['--route' => ["/ok\xff"]], '--route is not printable UTF-8'],
            [['--route' => ['/ok'], '--base-url' => "http://127.0.0.1:9501/#\xff"], '--base-url is not printable UTF-8'],
            [['--route' => ['/ok'], '--duration' => '1e309'], 'a number of seconds up to 300'],
            [['--route' => ['/ok'], '--duration' => '301'], 'a number of seconds up to 300'],
            [['--route' => ['/ok'], '--warmup' => 'soon'], 'a number of seconds up to 300'],
            [['--route' => ['/ok'], '--base-url' => 'http://127.0.0.1:9501/app'], 'carries none of its own'],
            [['--route' => ['/ok'], '--base-url' => 'https://127.0.0.1:9501'], 'carries none of its own'],
            [[], 'needs at least one --route'],
        ] as [$options, $message]) {
            $tester = new CommandTester(new DevBenchCommand());
            self::assertSame(Command::FAILURE, $tester->execute($options), json_encode($options) ?: '');
            self::assertStringContainsString($message, $tester->getDisplay(), json_encode($options) ?: '');
        }
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\ClientClassifier;

final class ClientClassifierTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function agents(): iterable
    {
        yield 'chrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'human', 'Chrome'];
        yield 'edge' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/150.0 Safari/537.36 Edg/150.0', 'human', 'Edge'];
        yield 'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'bot', 'Googlebot'];
        yield 'uptime' => ['UptimeRobot/2.0; http://www.uptimerobot.com/', 'bot', 'UptimeRobot'];
        yield 'curl' => ['curl/8.5.0', 'api', 'curl'];
        yield 'python' => ['python-requests/2.32', 'api', 'python-requests'];
        yield 'go' => ['Go-http-client/2.0', 'api', 'Go-http-client'];
        yield 'unknown product' => ['AcmeSync/1.4 (+https://acme.test)', 'api', 'AcmeSync'];
    }

    #[Test]
    #[DataProvider('agents')]
    public function classifies_by_user_agent(string $ua, string $client, string $agent): void
    {
        self::assertSame(['client' => $client, 'agent' => $agent], ClientClassifier::classify(['user-agent' => $ua]));
    }

    #[Test]
    public function no_user_agent_is_a_program_and_the_demo_header_is_internal(): void
    {
        self::assertSame('api', ClientClassifier::classify([])['client']);
        self::assertSame(['client' => 'internal', 'agent' => 'demo load'], ClientClassifier::classify(['user-agent' => 'Mozilla/5.0 Chrome/1', 'x-observatory-demo' => '1']));
    }
}

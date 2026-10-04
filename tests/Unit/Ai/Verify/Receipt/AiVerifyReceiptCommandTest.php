<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Receipt;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Console\Command\AiVerifyReceiptCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AiVerifyReceiptCommandTest extends TestCase
{
    #[Test]
    public function unread_lists_red_runs_first_and_refuses_a_bad_window(): void
    {
        $tester = new CommandTester(new AiVerifyReceiptCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['--unread' => true, '--hours' => '24', '--json' => true]));
        $data = json_decode($tester->getDisplay(), true);
        self::assertIsArray($data);
        self::assertSame('semitexa-dev.verify-receipts-unread/v1', $data['artifact'] ?? null);
        self::assertIsArray($data['unread'] ?? null);
        $verdicts = array_column($data['unread'], 'verdict');
        $firstPass = array_search('pass', $verdicts, true);
        self::assertSame([], $firstPass === false ? [] : array_values(array_filter(array_slice($verdicts, $firstPass), static fn ($v): bool => $v !== 'pass')), 'red runs come before green ones');

        self::assertSame(Command::INVALID, $tester->execute(['--unread' => true, '--hours' => 'a day']));
        self::assertStringContainsString('--hours takes a whole number', $tester->getDisplay());
    }
}

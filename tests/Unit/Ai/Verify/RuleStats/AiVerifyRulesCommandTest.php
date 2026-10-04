<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\RuleStats;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Console\Command\AiVerifyRulesCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** --dormant: omitted is every rule, 0 is every never-fired rule, junk is refused (review of dev#130). */
final class AiVerifyRulesCommandTest extends TestCase
{
    #[Test]
    public function dormant_distinguishes_omitted_from_zero_and_refuses_junk(): void
    {
        $all = $this->rules([]);
        $never = $this->rules(['--dormant' => '0']);

        self::assertNotSame([], $all);
        self::assertSame(
            array_values(array_filter($all, static fn (array $r): bool => $r['fires'] === 0)),
            $never,
        );

        $tester = new CommandTester(new AiVerifyRulesCommand());
        self::assertSame(Command::INVALID, $tester->execute(['--dormant' => 'abc']));
        self::assertStringContainsString('--dormant takes a whole number of chances', $tester->getDisplay());
    }

    /**
     * @param array<string, string> $options
     * @return list<array{rule: string, fires: int}>
     */
    private function rules(array $options): array
    {
        $tester = new CommandTester(new AiVerifyRulesCommand());
        self::assertSame(Command::SUCCESS, $tester->execute($options + ['--json' => true]));
        $data = json_decode($tester->getDisplay(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['rules'] ?? null);

        /** @var list<array{rule: string, fires: int}> */
        return $data['rules'];
    }
}

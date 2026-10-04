<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\RuleStats;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiVerifyRulesCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\RuleStats\RuleFireLedger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * --dormant: omitted is every rule, 0 every never-fired rule, junk refused; in
 * --json mode an error is JSON too (review of dev#130). Run against a project
 * whose ledger has a known firing, so the filter can actually be seen working.
 */
final class AiVerifyRulesCommandTest extends TestCase
{
    private string $root;
    private string|false $cwd;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/verify-rules-cmd-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/run', 0777, true);
        mkdir($this->root . '/src/modules', 0777, true);
        file_put_contents($this->root . '/composer.json', '{}');
        // The vendor directory this test runs from, wherever the checkout is
        // (workspace or a standalone package repository; review of dev#130).
        symlink(dirname((string) (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2), $this->root . '/vendor');
        file_put_contents($this->root . '/' . RuleFireLedger::FILE, json_encode([
            'at' => '2026-10-04T10:00:00+00:00', 'chances' => ['phpstan'], 'fired' => ['semitexa.disallowErrorLog'],
        ]) . "\n");
        $this->cwd = getcwd();
        chdir($this->root);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        if ($this->cwd !== false) {
            chdir($this->cwd);
        }
        ProjectRoot::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function dormant_keeps_a_rule_that_fired_out_of_the_never_fired_list(): void
    {
        $all = array_column($this->rules([]), 'fires', 'rule');
        $never = array_column($this->rules(['--dormant' => '0']), 'fires', 'rule');

        self::assertSame(1, $all['semitexa.disallowErrorLog'] ?? null);
        self::assertArrayNotHasKey('semitexa.disallowErrorLog', $never);
        self::assertArrayHasKey('semitexa.traitInjection', $never);
        self::assertSame([], array_filter($never));
    }

    #[Test]
    public function junk_is_refused_and_in_json_mode_the_refusal_is_json(): void
    {
        $tester = new CommandTester(new AiVerifyRulesCommand());
        self::assertSame(Command::INVALID, $tester->execute(['--dormant' => 'abc', '--json' => true]));
        self::assertSame(['artifact' => 'semitexa-dev.verify-rules/v1', 'error' => '--dormant takes a whole number of chances, e.g. --dormant=50'], json_decode($tester->getDisplay(), true));
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

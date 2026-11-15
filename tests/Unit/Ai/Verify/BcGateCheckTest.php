<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\BcGateCheck;
use Semitexa\Dev\Application\Service\Ai\Verify\BcGateTargets;
use Semitexa\Dev\Application\Service\Ai\Verify\ProcessRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationResult;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;

/**
 * How ai:verify runs the release's BC gate for one package
 * (tk-stab-bc-check-gate): the cut's own script, against `git stash create`,
 * roave from var/tools/roave; a gate that could not run is never a pass.
 */
final class BcGateCheckTest extends TestCase
{
    private string $root = '';

    /** @var list<list<string>> */
    private array $commands = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/bc-gate-check-' . bin2hex(random_bytes(4));
        mkdir(dirname($this->root . '/' . BcGateTargets::GATE_SCRIPT), 0777, true);
        file_put_contents($this->root . '/' . BcGateTargets::GATE_SCRIPT, '<?php');
        mkdir($this->root . '/' . BcGateCheck::TOOL_DIR . '/vendor/bin', 0777, true);
        touch($this->root . '/' . BcGateCheck::TOOL_DIR . '/vendor/bin/roave-backward-compatibility-check');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function the_cut_s_script_judges_the_working_tree_of_that_package(): void
    {
        $result = $this->check(['stash' => [0, "abc123\n"], 'gate' => [0, "[SAME] semitexa-orm: no source change\nNo break on the @api surface.\n"]]);

        self::assertSame(VerificationResult::STATUS_PASS, $result->status);
        self::assertSame('bc_gate:semitexa-core → No break on the @api surface.', $result->signal);
        self::assertSame(['git', '-C', $this->root . '/packages/semitexa-core', 'stash', 'create'], $this->commands[0]);
        // The working tree, as a commit that moves no ref.
        // verify:accept-test-change five assertContains on the argv became one assertSame of the whole argv (review, dev#265): stricter, fewer calls
        self::assertSame($this->gateCommand('abc123'), $this->commands[1]);
        self::assertCount(2, $this->commands);
    }

    #[Test]
    public function a_tree_with_nothing_modified_is_compared_at_head(): void
    {
        $this->check(['stash' => [0, ''], 'gate' => [0, "No break on the @api surface.\n"]]);

        self::assertSame($this->gateCommand('HEAD'), $this->commands[1]);
    }

    #[Test]
    public function a_refused_break_fails_with_the_gate_s_words(): void
    {
        $result = $this->check(['stash' => [0, "abc123\n"], 'gate' => [1, "[BREAK] semitexa-core (since 2026.11.13.0659): Parameter 0 of Semitexa\\Core\\Request#getHeader() changed name from name to headerName — @api and never deprecated\n"]]);

        self::assertSame(VerificationResult::STATUS_FAIL, $result->status);
        self::assertStringContainsString('[BREAK] semitexa-core (since 2026.11.13.0659): Parameter 0 of Semitexa\\Core\\Request#getHeader()', $result->signal);
        self::assertStringContainsString('mark it @deprecated', $result->signal);
    }

    #[Test]
    public function a_gate_that_could_not_run_is_not_a_pass(): void
    {
        $stashFailed = $this->check(['stash' => [128, 'fatal: not a git repository']]);
        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $stashFailed->status);
        self::assertStringContainsString('git stash create failed', $stashFailed->signal);
        self::assertCount(1, $this->commands, 'the gate did not run');

        unlink($this->root . '/' . BcGateCheck::TOOL_DIR . '/vendor/bin/roave-backward-compatibility-check');
        $this->commands = [];
        $noRoave = $this->check(['composer' => [1, 'Your requirements could not be resolved']]);
        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $noRoave->status);
        self::assertStringContainsString('composer could not install roave', $noRoave->signal);
        self::assertSame('composer', $this->commands[0][0]);
        self::assertCount(1, $this->commands);
    }

    /** Review, dev#265: a gate the runner timed out said nothing about the API. */
    #[Test]
    public function a_gate_that_timed_out_is_incomplete_not_a_break(): void
    {
        $result = $this->check(['stash' => [0, "abc123\n"], 'gate' => [124, 'partial output', 'timeout']]);

        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $result->status);
        self::assertStringContainsString('the gate did not finish (timeout)', $result->signal);
        self::assertStringNotContainsString('@deprecated', $result->signal, 'no API advice from a check that did not finish');
    }

    #[Test]
    public function a_project_without_the_gate_script_skips_without_failing(): void
    {
        unlink($this->root . '/' . BcGateTargets::GATE_SCRIPT);

        $result = $this->check([]);

        self::assertSame(VerificationResult::STATUS_SKIPPED, $result->status);
        self::assertFalse($result->required);
        self::assertSame([], $this->commands);
    }

    /** @return list<string> */
    private function gateCommand(string $to): array
    {
        return [
            'env',
            'RELEASE_ROOT=' . $this->root,
            'SEMITEXA_BC_TO=' . $to,
            'SEMITEXA_AUTOLOAD=' . $this->root . '/vendor/autoload.php',
            'SEMITEXA_BC_CHECK=' . escapeshellarg('/usr/bin/php') . ' ' . escapeshellarg($this->root . '/var/tools/roave/vendor/bin/roave-backward-compatibility-check'),
            '/usr/bin/php',
            $this->root . '/' . BcGateTargets::GATE_SCRIPT,
            '--package=semitexa-core',
        ];
    }

    /** @param array<string, array{0: int, 1: string, 2?: string}> $answers by the first word of what runs: git → stash, env → gate, composer */
    private function check(array $answers): VerificationResult
    {
        $commands = &$this->commands;
        $runner = new class ($answers, $commands) implements ProcessRunner {
            /**
             * @param array<string, array{0: int, 1: string, 2?: string}> $answers
             * @param list<list<string>> $commands
             */
            public function __construct(private readonly array $answers, private array &$commands) {}

            public function run(array $command, string $cwd): array
            {
                $this->commands[] = $command;
                $key = ['git' => 'stash', 'env' => 'gate', 'composer' => 'composer'][$command[0]] ?? $command[0];
                $answer = $this->answers[$key] ?? [1, "unexpected: {$command[0]}"];

                return ['exit' => $answer[0], 'output' => $answer[1]] + (isset($answer[2]) ? ['failure' => $answer[2]] : []);
            }
        };

        $target = new VerificationTarget(type: VerificationTarget::TYPE_BC_GATE, id: 'bc_gate:semitexa-core', reason: 'test', triggeredBy: ['packages/semitexa-core/src/Request.php'], filePath: 'packages/semitexa-core');

        return (new BcGateCheck($this->root, $runner, '/usr/bin/php'))->check($target);
    }
}

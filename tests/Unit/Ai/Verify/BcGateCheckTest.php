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
        $gate = $this->commands[1];
        self::assertSame('env', $gate[0]);
        self::assertContains('RELEASE_ROOT=' . $this->root, $gate);
        self::assertContains('SEMITEXA_BC_TO=abc123', $gate, 'the working tree, as a commit that moves no ref');
        self::assertContains($this->root . '/' . BcGateTargets::GATE_SCRIPT, $gate);
        self::assertSame('--package=semitexa-core', end($gate));
        self::assertContains('SEMITEXA_BC_CHECK=' . escapeshellarg('/usr/bin/php') . ' ' . escapeshellarg($this->root . '/var/tools/roave/vendor/bin/roave-backward-compatibility-check'), $gate);
    }

    #[Test]
    public function a_tree_with_nothing_modified_is_compared_at_head(): void
    {
        $this->check(['stash' => [0, ''], 'gate' => [0, "No break on the @api surface.\n"]]);

        self::assertContains('SEMITEXA_BC_TO=HEAD', $this->commands[1]);
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

    #[Test]
    public function a_project_without_the_gate_script_skips_without_failing(): void
    {
        unlink($this->root . '/' . BcGateTargets::GATE_SCRIPT);

        $result = $this->check([]);

        self::assertSame(VerificationResult::STATUS_SKIPPED, $result->status);
        self::assertFalse($result->required);
        self::assertSame([], $this->commands);
    }

    /** @param array<string, array{int, string}> $answers by the first word of what runs: git → stash, env → gate, composer */
    private function check(array $answers): VerificationResult
    {
        $commands = &$this->commands;
        $runner = new class ($answers, $commands) implements ProcessRunner {
            /**
             * @param array<string, array{int, string}> $answers
             * @param list<list<string>> $commands
             */
            public function __construct(private readonly array $answers, private array &$commands) {}

            public function run(array $command, string $cwd): array
            {
                $this->commands[] = $command;
                $key = ['git' => 'stash', 'env' => 'gate', 'composer' => 'composer'][$command[0]] ?? $command[0];
                [$exit, $output] = $this->answers[$key] ?? [1, "unexpected: {$command[0]}"];

                return ['exit' => $exit, 'output' => $output];
            }
        };

        $target = new VerificationTarget(type: VerificationTarget::TYPE_BC_GATE, id: 'bc_gate:semitexa-core', reason: 'test', triggeredBy: ['packages/semitexa-core/src/Request.php'], filePath: 'packages/semitexa-core');

        return (new BcGateCheck($this->root, $runner, '/usr/bin/php'))->check($target);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\LintRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationResult;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class LintRunnerTest extends TestCase
{
    private const WHY = 'Why: a refused inline script fails silently. Learned 2026-09-16: the deferred manifest shipped that way.';

    #[Test]
    public function a_failing_lint_names_itself_and_says_why_it_exists(): void
    {
        // A failing lint used to be a red result with no violations and only
        // its last output line: the agent saw THAT it failed, never why the
        // check exists.
        $result = $this->runLint(new ExplainedLint(exit: 1));

        self::assertSame(VerificationResult::STATUS_FAIL, $result->status);
        self::assertSame('2 inline scripts lack a nonce. — ' . self::WHY, $result->signal);
        self::assertSame([[
            'check'         => 'lint',
            'severity'      => 'error',
            'rule'          => 'lint:explained',
            'identifier'    => 'lint:explained',
            'path'          => '',
            'line'          => 0,
            'message'       => '2 inline scripts lack a nonce.',
            'rationale'     => self::WHY,
            'suggested_fix' => 'Run bin/semitexa lint:explained for every finding, fix them, and re-run ai:verify.',
        ]], $result->diagnostics);
    }

    #[Test]
    public function a_passing_lint_carries_no_violation(): void
    {
        $result = $this->runLint(new ExplainedLint(exit: 0));

        self::assertSame(VerificationResult::STATUS_PASS, $result->status);
        self::assertSame('2 inline scripts lack a nonce.', $result->signal);
        self::assertSame([], $result->diagnostics);
    }

    #[Test]
    public function the_rationale_is_read_through_a_lazy_command(): void
    {
        $lazy = new LazyCommand('lint:explained', [], '', false, static fn (): Command => new ExplainedLint(exit: 1));

        self::assertSame(self::WHY, LintRunner::rationaleOf($lazy));
    }

    #[Test]
    public function a_lint_without_a_rationale_still_fails_plainly(): void
    {
        $app = new Application();
        $app->add(new class extends Command {
            public function __construct() { parent::__construct('lint:plain'); }
            protected function execute(InputInterface $input, OutputInterface $output): int { $output->writeln('1 error.'); return 1; }
        });
        $result = (new LintRunner($app))->run(new VerificationTarget(VerificationTarget::TYPE_LINT, 'lint:plain', 'r', [], commandName: 'lint:plain'));

        self::assertSame('1 error.', $result->signal);
        self::assertSame('', $result->diagnostics[0]['rationale']);
    }

    private function runLint(Command $command): VerificationResult
    {
        $app = new Application();
        $app->add($command);

        return (new LintRunner($app))->run(new VerificationTarget(VerificationTarget::TYPE_LINT, 'lint:explained', 'r', [], commandName: 'lint:explained'));
    }
}

final class ExplainedLint extends Command
{
    public const RATIONALE = 'Why: a refused inline script fails silently. Learned 2026-09-16: the deferred manifest shipped that way.';

    public function __construct(private readonly int $exit)
    {
        parent::__construct('lint:explained');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('[ERROR] 2 inline scripts lack a nonce.');

        return $this->exit;
    }
}

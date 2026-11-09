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
    public function each_finding_a_lint_locates_is_a_violation_where_it_is(): void
    {
        // Guard run 2026-11-08: "20 claim(s) with nothing behind them" with path
        // '' and line 0. The agent could not see which page, and deleted its own
        // client source until the gate went quiet.
        $app = new Application();
        $app->add(new class extends Command {
            public function __construct() { parent::__construct('docs:located'); }
            protected function configure(): void { $this->addOption('path', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED | \Symfony\Component\Console\Input\InputOption::VALUE_IS_ARRAY); }
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $output->writeln(' ------------------- ----------- ----------------- ');
                $output->writeln('  where               kind        claimed          ');
                $output->writeln(' ------------------- ----------- ----------------- ');
                $output->writeln('  README.md:12        attribute   ExposeAsGraphql   —   ');
                $output->writeln('  client/GUIDE.md:3   command     guard:pair       ');
                $output->writeln(' [ERROR] 2 claim(s) with nothing behind them.');

                return 1;
            }
        });
        $result = (new LintRunner($app))->run(new VerificationTarget(
            VerificationTarget::TYPE_DOCS, 'docs:claims', 'r', ['README.md', 'client/GUIDE.md'],
            commandName: 'docs:located',
            commandInput: ['--path' => ['/app/README.md', '/app/client/GUIDE.md']],
        ));

        self::assertSame(
            [['README.md', 12, 'attribute ExposeAsGraphql'], ['client/GUIDE.md', 3, 'command guard:pair']],
            array_map(static fn (array $d): array => [$d['path'], $d['line'], $d['message']], $result->diagnostics),
        );
        self::assertSame('2 claim(s) with nothing behind them. First: README.md:12 attribute ExposeAsGraphql.', $result->signal);
        self::assertSame(
            'Run bin/semitexa docs:located --path=/app/README.md --path=/app/client/GUIDE.md for every finding, fix them, and re-run ai:verify.',
            $result->diagnostics[0]['suggested_fix'],
        );
    }

    #[Test]
    public function a_suggested_command_spells_out_three_values_of_an_option_and_counts_the_rest_outside_it(): void
    {
        $app = new Application();
        $app->add(new class extends Command {
            public function __construct() { parent::__construct('lint:many'); }
            protected function configure(): void { $this->addOption('path', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED | \Symfony\Component\Console\Input\InputOption::VALUE_IS_ARRAY); }
            protected function execute(InputInterface $input, OutputInterface $output): int { $output->writeln('[ERROR] 1 weakened test.'); return 1; }
        });
        $paths = array_map(static fn (int $i): string => "src/F{$i}.php", range(1, 200));
        $result = (new LintRunner($app))->run(new VerificationTarget(VerificationTarget::TYPE_LINT, 'lint:many', 'r', [], commandName: 'lint:many', commandInput: ['--path' => $paths]));

        self::assertSame(
            'Run bin/semitexa lint:many --path=src/F1.php --path=src/F2.php --path=src/F3.php (197 more --path value(s) not shown; ai:verify checks them all) for every finding, fix them, and re-run ai:verify.',
            $result->diagnostics[0]['suggested_fix'],
        );
    }

    #[Test]
    public function a_non_docs_lint_keeps_one_violation_whatever_its_rows_look_like(): void
    {
        // lint:mechanisms prints remarks ("not installed here, a remark, not a
        // failure") as located rows too; only the docs gates' rows are findings.
        $app = new Application();
        $app->add(new class extends Command {
            public function __construct() { parent::__construct('lint:remarks'); }
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $output->writeln('  src/A.php:5  not installed here, so this is a remark, not a failure');
                $output->writeln('  notes.md:2   a remark too');
                $output->writeln('[ERROR] 1 hand-rolled mechanism(s).');

                return 1;
            }
        });
        $result = (new LintRunner($app))->run(new VerificationTarget(VerificationTarget::TYPE_LINT, 'lint:remarks', 'r', [], commandName: 'lint:remarks'));

        self::assertCount(1, $result->diagnostics);
        self::assertSame(['', 0], [$result->diagnostics[0]['path'], $result->diagnostics[0]['line']]);
        self::assertSame('1 hand-rolled mechanism(s).', $result->signal);
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

    #[Test]
    public function missing_optional_tooling_skips_the_gate_without_failing_it(): void
    {
        $result = (new LintRunner(new Application()))->run(
            new VerificationTarget(VerificationTarget::TYPE_DOCS, 'docs:claims', 'r', [], commandName: 'docs:lint'),
            optional: true,
        );

        self::assertSame(
            [VerificationResult::STATUS_SKIPPED, 0, false, 'semitexa/docs command docs:lint unavailable; optional documentation tooling'],
            [$result->status, $result->exitCode, $result->required, $result->signal],
        );
    }

    #[Test]
    public function a_package_lint_is_skipped_when_its_package_is_not_installed(): void
    {
        $result = (new LintRunner(new Application(), static fn (string $package): bool => false))->run(
            new VerificationTarget(VerificationTarget::TYPE_LINT, 'lint:access', 'r', [], commandName: 'lint:access'),
        );

        self::assertSame(
            [VerificationResult::STATUS_SKIPPED, 0, false, 'lint:access unavailable; semitexa/authorization is not installed, so its rules do not apply'],
            [$result->status, $result->exitCode, $result->required, $result->signal],
        );
    }

    #[Test]
    public function a_package_lint_missing_while_its_package_is_installed_is_incomplete(): void
    {
        $result = (new LintRunner(new Application(), static fn (string $package): bool => $package === 'semitexa/authorization'))->run(
            new VerificationTarget(VerificationTarget::TYPE_LINT, 'lint:access', 'r', [], commandName: 'lint:access'),
        );

        self::assertSame([VerificationResult::STATUS_INCOMPLETE, 'command lint:access is not registered'], [$result->status, $result->signal]);
    }

    #[Test]
    public function a_missing_required_lint_is_incomplete_not_a_pass(): void
    {
        $result = (new LintRunner(new Application()))->run(new VerificationTarget(VerificationTarget::TYPE_LINT, 'lint:gone', 'r', [], commandName: 'lint:gone'));

        self::assertSame([VerificationResult::STATUS_INCOMPLETE, 'command lint:gone is not registered'], [$result->status, $result->signal]);
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

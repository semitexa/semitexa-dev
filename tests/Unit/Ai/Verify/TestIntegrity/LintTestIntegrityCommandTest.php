<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\TestIntegrity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\LintTestIntegrityCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\ProjectGuardTargets;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationExecutor;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationResult;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class LintTestIntegrityCommandTest extends TestCase
{
    private const TEST = <<<'PHP'
        <?php
        final class PriceTest extends TestCase
        {
            public function test_total(): void
            {
                self::assertSame(1999, $cart->total());
            }
        }
        PHP;

    #[Test]
    public function ai_verify_schedules_it_for_a_changed_test_with_every_path_of_the_change(): void
    {
        $guards = new ProjectGuardTargets(sys_get_temp_dir());
        self::assertNotContains('lint:test-integrity', array_column($guards->targets([new ChangedFile('src/Price.php', ChangedFile::KIND_PHP_OTHER)], 'minimal'), 'id'));

        $files = [
            new ChangedFile('packages/semitexa-x/src/Price.php', ChangedFile::KIND_PHP_OTHER),
            new ChangedFile('packages/semitexa-x/tests/PriceTest.php', ChangedFile::KIND_PHP_OTHER, ChangedFile::STATUS_DELETED),
        ];
        $lint = array_values(array_filter($guards->targets($files, 'minimal'), static fn ($t): bool => $t->id === 'lint:test-integrity'))[0] ?? null;
        self::assertNotNull($lint);
        self::assertSame(['packages/semitexa-x/tests/PriceTest.php'], $lint->triggeredBy);
        self::assertSame(['--path' => ['packages/semitexa-x/src/Price.php', 'packages/semitexa-x/tests/PriceTest.php']], $lint->commandInput);
    }

    #[Test]
    public function a_loosened_test_fails_ai_verify_until_the_change_says_why(): void
    {
        // The workspace root is not a repository; the package below it is.
        $root = sys_get_temp_dir() . '/semitexa-test-integrity-' . bin2hex(random_bytes(4));
        $repo = $root . '/packages/semitexa-x';
        mkdir($repo . '/tests', 0777, true);
        mkdir($root . '/src/modules', 0777, true);
        file_put_contents($root . '/composer.json', '{}');
        file_put_contents($repo . '/tests/PriceTest.php', self::TEST);
        $git = 'git -C ' . escapeshellarg($repo) . ' -c user.name=t -c user.email=t@t ';
        exec($git . 'init -q && ' . $git . 'add -A && ' . $git . 'commit -qm x 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        $cwd = getcwd();
        chdir($root);
        ProjectRoot::reset();

        try {
            $loosened = str_replace('self::assertSame(1999, $cart->total());', 'self::assertNotNull($cart->total());', self::TEST);
            file_put_contents($repo . '/tests/PriceTest.php', $loosened);
            $red = $this->verify($root);
            // Run on its own, it reads the uncommitted change itself.
            $alone = new CommandTester(new LintTestIntegrityCommand());
            $aloneExit = $alone->execute([]);

            file_put_contents($repo . '/tests/PriceTest.php', str_replace(
                'self::assertNotNull',
                "// verify:accept-test-change total is pinned in CartTotalTest now\n        self::assertNotNull",
                $loosened,
            ));
            $green = $this->verify($root);
        } finally {
            if ($cwd !== false) {
                chdir($cwd);
            }
            ProjectRoot::reset();
            exec('rm -rf ' . escapeshellarg($root));
        }

        self::assertSame(1, $aloneExit);
        self::assertStringContainsString('packages/semitexa-x/tests/PriceTest.php: test_total(): 1 value check(s) replaced by weaker ones', $alone->getDisplay());
        self::assertSame(VerificationResult::STATUS_FAIL, $red->status);
        // Cut at 240 characters by the signal, after the instruction.
        self::assertSame(
            'lint:test-integrity → 1 test change(s) check less than HEAD: restore the checks, or say why on an added line // verify:accept-test-change <reason>; first packages/semitexa-x/tests/PriceTest.php: test_total(): 1 value check(s) replaced ... — '
                . LintTestIntegrityCommand::RATIONALE,
            $red->signal,
        );
        self::assertSame(['lint:test-integrity', LintTestIntegrityCommand::RATIONALE], [$red->diagnostics[0]['rule'], $red->diagnostics[0]['rationale']]);
        self::assertSame([VerificationResult::STATUS_PASS, 'lint:test-integrity → 1 weakening(s) accepted with a reason in the diff: total is pinned in CartTotalTest now'], [$green->status, $green->signal]);
    }

    private function verify(string $root): VerificationResult
    {
        $files = [new ChangedFile('packages/semitexa-x/tests/PriceTest.php', ChangedFile::KIND_PHP_OTHER)];
        $targets = array_values(array_filter((new ProjectGuardTargets($root))->targets($files, 'minimal'), static fn ($t): bool => $t->id === 'lint:test-integrity'));
        $app = new Application();
        $app->add(new LintTestIntegrityCommand());

        return (new VerificationExecutor($app, $root))->execute(new VerificationPlan('minimal', 'minimal', $files, $targets))[0];
    }
}

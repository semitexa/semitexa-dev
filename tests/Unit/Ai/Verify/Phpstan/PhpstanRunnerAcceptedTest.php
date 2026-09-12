<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Phpstan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\PhpstanRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\PhpstanRunResult;
use Semitexa\Dev\Application\Service\Ai\Verify\ProcessRunner;

/**
 * The gate consults the accepted-violations registry, and the point of these
 * tests is that consulting it did not make the gate blind. An accepted site
 * stops failing the run and starts being REPORTED as accepted; everything
 * around it — one more occurrence than was accepted, a different rule, a
 * different file — still fails exactly as before.
 */
final class PhpstanRunnerAcceptedTest extends TestCase
{
    private const ACCEPTED_FILE = 'packages/semitexa-dev/src/Application/Console/Command/AiInvokeCommand.php';
    private const ACCEPTED_RULE = 'semitexa.staticContainerAccess';

    /** @param list<array{file: string, rule: string, line?: int}> $violations */
    private function runWith(array $violations): PhpstanRunResult
    {
        $files = [];
        foreach ($violations as $v) {
            $abs = '/root/' . $v['file'];
            $files[$abs] ??= ['errors' => 0, 'messages' => []];
            $files[$abs]['errors']++;
            $files[$abs]['messages'][] = [
                'message'    => 'Static ContainerFactory:: access is forbidden in application code.',
                'line'       => $v['line'] ?? 12,
                'identifier' => $v['rule'],
            ];
        }

        $json = (string) json_encode([
            'totals' => ['errors' => 0, 'file_errors' => count($violations)],
            'files'  => $files === [] ? new \stdClass() : $files,
            'errors' => [],
        ]);

        return $this->runner($violations === [] ? 0 : 1, $json)->run(['x.php']);
    }

    private function runner(int $exit, string $stdout): PhpstanRunner
    {
        $process = new class($exit, $stdout) implements ProcessRunner {
            public function __construct(private readonly int $exit, private readonly string $stdout) {}

            public function run(array $command, string $cwd): array
            {
                return ['exit' => $this->exit, 'output' => $this->stdout];
            }
        };

        return new PhpstanRunner(
            projectRoot: '/root',
            processRunner: $process,
            // Any existing file satisfies the binary/config existence checks;
            // this runner never shells out.
            phpstanBinary: __FILE__,
            configPath: __FILE__,
        );
    }

    #[Test]
    public function an_accepted_violation_no_longer_fails_the_run(): void
    {
        $result = $this->runWith([['file' => self::ACCEPTED_FILE, 'rule' => self::ACCEPTED_RULE]]);

        self::assertSame(PhpstanRunResult::STATUS_PASS, $result->status);
    }

    #[Test]
    public function an_accepted_violation_is_reported_rather_than_hidden(): void
    {
        $result = $this->runWith([['file' => self::ACCEPTED_FILE, 'rule' => self::ACCEPTED_RULE]]);

        self::assertCount(1, $result->diagnostics, 'the rule fired, so it must still be visible');
        self::assertSame('accepted', $result->diagnostics[0]['severity']);
        self::assertNotSame('', trim((string) $result->diagnostics[0]['accepted_reason']));
        self::assertStringContainsString('accepted', $result->rawSignal);
    }

    #[Test]
    public function one_more_than_was_accepted_still_fails(): void
    {
        $result = $this->runWith([
            ['file' => self::ACCEPTED_FILE, 'rule' => self::ACCEPTED_RULE, 'line' => 10],
            ['file' => self::ACCEPTED_FILE, 'rule' => self::ACCEPTED_RULE, 'line' => 99],
        ]);

        self::assertSame(PhpstanRunResult::STATUS_FAIL, $result->status, 'accepting one occurrence accepts one, not the file');

        $unresolved = array_values(array_filter(
            $result->diagnostics,
            static fn(array $d): bool => ($d['severity'] ?? '') !== 'accepted',
        ));
        self::assertCount(1, $unresolved);
    }

    #[Test]
    public function a_different_rule_in_an_accepted_file_still_fails(): void
    {
        $result = $this->runWith([
            ['file' => self::ACCEPTED_FILE, 'rule' => 'semitexa.injectionViaConstructor'],
        ]);

        self::assertSame(PhpstanRunResult::STATUS_FAIL, $result->status);
    }

    #[Test]
    public function an_unlisted_file_still_fails(): void
    {
        $result = $this->runWith([
            ['file' => 'packages/semitexa-dev/src/Application/Service/Something.php', 'rule' => self::ACCEPTED_RULE],
        ]);

        self::assertSame(PhpstanRunResult::STATUS_FAIL, $result->status);
    }

    #[Test]
    public function a_clean_run_is_unchanged(): void
    {
        $result = $this->runWith([]);

        self::assertSame(PhpstanRunResult::STATUS_PASS, $result->status);
        self::assertSame([], $result->diagnostics);
        self::assertStringNotContainsString('accepted', $result->rawSignal);
    }
}

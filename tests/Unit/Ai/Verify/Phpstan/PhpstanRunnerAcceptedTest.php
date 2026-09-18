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
 * different file, the same rule somewhere ELSE in the accepted file — still
 * fails exactly as before.
 *
 * Against the real repository root and the real accepted file, because an
 * allowance now names the METHOD it was granted in, and placing a diagnostic
 * means reading that file. A synthetic root proved only that nothing could be
 * placed.
 */
final class PhpstanRunnerAcceptedTest extends TestCase
{
    private const ACCEPTED_FILE = 'packages/semitexa-dev/src/Application/Console/Command/AiInvokeCommand.php';
    private const ACCEPTED_RULE = 'semitexa.staticContainerAccess';

    /** An entry whose site is a CLASS, because the rule reports on the declaration. */
    private const CLASS_LEVEL_FILE = 'packages/semitexa-webhooks/src/Application/Db/MySQL/Mapper/WebhookInboxMapper.php';
    private const CLASS_LEVEL_RULE = 'semitexa.domainModelEncapsulation';

    private function projectRoot(): string
    {
        return dirname(__DIR__, 7);
    }

    /** A line inside execute(), which is where the entry accepts this rule. */
    private function lineAtAcceptedSite(): int
    {
        $source = (string) file_get_contents($this->projectRoot() . '/' . self::ACCEPTED_FILE);
        foreach (explode("\n", $source) as $i => $line) {
            if (str_contains($line, 'ContainerFactory::createRequestScoped()')) {
                return $i + 1;
            }
        }

        self::fail('the accepted call is no longer in that file');
    }

    /** A line in a different method of the same file. */
    private function lineAwayFromAcceptedSite(): int
    {
        $source = (string) file_get_contents($this->projectRoot() . '/' . self::ACCEPTED_FILE);
        foreach (explode("\n", $source) as $i => $line) {
            if (str_contains($line, 'private function targetArgs(')) {
                return $i + 2;
            }
        }

        self::fail('the file no longer has the method this test uses as "somewhere else"');
    }

    /** @param list<array{file: string, rule: string, line?: int}> $violations */
    private function runWith(array $violations): PhpstanRunResult
    {
        $files = [];
        foreach ($violations as $v) {
            $abs = $this->projectRoot() . '/' . $v['file'];
            $files[$abs] ??= ['errors' => 0, 'messages' => []];
            $files[$abs]['errors']++;
            $files[$abs]['messages'][] = [
                'message'    => 'Static ContainerFactory:: access is forbidden in application code.',
                'line'       => $v['line'] ?? $this->lineAtAcceptedSite(),
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
            projectRoot: $this->projectRoot(),
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

        self::assertSame([], $result->diagnostics, 'an accepted entry is not a violation of the run');
        self::assertCount(1, $result->accepted, 'the rule fired, so it must still be visible');
        self::assertSame('accepted', $result->accepted[0]['severity']);
        self::assertNotSame('', trim((string) $result->accepted[0]['accepted_reason']));
        self::assertStringContainsString('accepted', $result->rawSignal);
    }

    #[Test]
    public function one_more_than_was_accepted_still_fails(): void
    {
        $result = $this->runWith([
            ['file' => self::ACCEPTED_FILE, 'rule' => self::ACCEPTED_RULE, 'line' => $this->lineAtAcceptedSite()],
            ['file' => self::ACCEPTED_FILE, 'rule' => self::ACCEPTED_RULE, 'line' => $this->lineAtAcceptedSite()],
        ]);

        self::assertSame(PhpstanRunResult::STATUS_FAIL, $result->status, 'accepting one occurrence accepts one, not the file');

        self::assertCount(1, $result->diagnostics, 'the extra occurrence is a plain violation');
        self::assertCount(1, $result->accepted, 'and the accepted one is still named');
    }

    /**
     * The hole the site closed: delete the blessed call, write a different one
     * elsewhere in the same class, and a file-and-rule key consumed the
     * allowance for it — green, with somebody else's reason attached, while the
     * textual ratchet saw an unchanged occurrence count either way. Raised in
     * review of dev#83.
     */
    #[Test]
    public function the_same_rule_in_a_different_method_of_that_file_still_fails(): void
    {
        $result = $this->runWith([
            ['file' => self::ACCEPTED_FILE, 'rule' => self::ACCEPTED_RULE, 'line' => $this->lineAwayFromAcceptedSite()],
        ]);

        self::assertSame(PhpstanRunResult::STATUS_FAIL, $result->status, 'the entry accepts a site, not a file');
        self::assertCount(1, $result->diagnostics);
        self::assertSame([], $result->accepted, 'and it must not be excused with the other call reason');
    }

    /**
     * A diagnostic that cannot be placed — no line, or a file this process
     * cannot read, as a consumer install analysing a path that is not on disk
     * here — is reported rather than absorbed. An allowance is a statement
     * about one place.
     */
    #[Test]
    public function a_diagnostic_that_cannot_be_placed_is_not_accepted(): void
    {
        $result = $this->runWith([
            ['file' => self::ACCEPTED_FILE, 'rule' => self::ACCEPTED_RULE, 'line' => 0],
        ]);

        self::assertSame(PhpstanRunResult::STATUS_FAIL, $result->status);
        self::assertSame([], $result->accepted);
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

    /**
     * THE SHAPE THAT COULD NOT BE ACCEPTED AT ALL until EnclosingSymbol learned
     * to fall back to the class. `domainModelEncapsulation` reports at the
     * mapper's declaration — its `#[AsMapper]` line, outside every method — so
     * the site never resolved, the entry did nothing, and the only options were
     * to obey a rule that made the model worse or to endure the violations.
     */
    #[Test]
    public function a_class_level_violation_is_accepted_at_its_declaration(): void
    {
        $result = $this->runWith([[
            'file' => self::CLASS_LEVEL_FILE,
            'rule' => self::CLASS_LEVEL_RULE,
            'line' => $this->lineAtClassDeclaration(),
        ]]);

        self::assertSame(PhpstanRunResult::STATUS_PASS, $result->status);
        self::assertCount(1, $result->accepted, 'the rule fired, so it must still be visible');
        self::assertSame('accepted', $result->accepted[0]['severity']);
        self::assertStringContainsString('markProcessing', (string) $result->accepted[0]['accepted_reason']);
    }

    /**
     * A class site is not the whole class. A method always wins over the class
     * holding it, so a violation inside toDomain() resolves to `toDomain` and is
     * reported as usual — otherwise accepting one class-level finding would
     * quietly bless every later violation of that rule anywhere in the file.
     */
    #[Test]
    public function the_same_rule_inside_a_method_of_that_class_still_fails(): void
    {
        $result = $this->runWith([[
            'file' => self::CLASS_LEVEL_FILE,
            'rule' => self::CLASS_LEVEL_RULE,
            'line' => $this->lineInsideAMethodOfTheMapper(),
        ]]);

        self::assertSame(PhpstanRunResult::STATUS_FAIL, $result->status);
        self::assertCount(1, $result->diagnostics);
        self::assertSame([], $result->accepted);
    }

    /**
     * The count still bounds a class site: nine reports where eight were
     * accepted is one violation, not a green run.
     */
    #[Test]
    public function one_more_class_level_report_than_was_accepted_still_fails(): void
    {
        $line = $this->lineAtClassDeclaration();
        $violations = array_fill(0, 9, [
            'file' => self::CLASS_LEVEL_FILE,
            'rule' => self::CLASS_LEVEL_RULE,
            'line' => $line,
        ]);

        $result = $this->runWith($violations);

        self::assertSame(PhpstanRunResult::STATUS_FAIL, $result->status);
        self::assertCount(8, $result->accepted, 'the entry accepts eight');
        self::assertCount(1, $result->diagnostics, 'the ninth is a plain violation');
    }

    /** The `#[AsMapper(...)]` line, which is where PHPStan reports the class error. */
    private function lineAtClassDeclaration(): int
    {
        $source = (string) file_get_contents($this->projectRoot() . '/' . self::CLASS_LEVEL_FILE);
        foreach (explode("\n", $source) as $i => $line) {
            if (str_starts_with(trim($line), '#[AsMapper(')) {
                return $i + 1;
            }
        }

        self::fail('the mapper no longer carries the attribute this test reports at');
    }

    private function lineInsideAMethodOfTheMapper(): int
    {
        $source = (string) file_get_contents($this->projectRoot() . '/' . self::CLASS_LEVEL_FILE);
        foreach (explode("\n", $source) as $i => $line) {
            if (str_contains($line, 'public function toDomain(')) {
                return $i + 3;
            }
        }

        self::fail('the mapper no longer has toDomain()');
    }

    #[Test]
    public function a_clean_run_is_unchanged(): void
    {
        $result = $this->runWith([]);

        self::assertSame(PhpstanRunResult::STATUS_PASS, $result->status);
        self::assertSame([], $result->diagnostics);
        self::assertSame([], $result->accepted);
        self::assertStringNotContainsString('accepted', $result->rawSignal);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiVerifyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `--dirty` on a clean checkout is an ANSWER, not a misuse.
 *
 * The first version fell through to the generic empty-paths error, which told
 * the caller to "pass --files, --git-ref, --diff-stdin or --dirty" — advice
 * they had just taken — and exited 1, which would make `ai:verify --dirty` red
 * on every clean tree.
 *
 * It is not a pass either: nothing was verified, and calling that green is the
 * false green this command exists to prevent. So it has a verdict of its own,
 * and carries the scan's reach so a reader can see what was asked.
 */
final class VerifyDirtyCleanTreeTest extends TestCase
{
    private string $root;
    private string $previousCwd = '';

    protected function setUp(): void
    {
        $this->previousCwd = (string) getcwd();
        $this->root = sys_get_temp_dir() . '/semitexa-clean-' . uniqid('', true);
        // What ProjectRoot::get() looks for — see its docblock on this seam.
        mkdir($this->root . '/src/modules', 0777, true);
        mkdir($this->root . '/packages', 0777, true);
        file_put_contents($this->root . '/composer.json', '{"name":"probe/fixture"}');
        chdir($this->root);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        if ($this->previousCwd !== '') {
            chdir($this->previousCwd);
        }
        ProjectRoot::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return array{exit: int, envelope: array<string, mixed>} */
    private function runDirty(): array
    {
        $command = new AiVerifyCommand();
        $command->setName('ai:verify');
        $output = new BufferedOutput();

        $exit = $command->run(new ArrayInput(['--dirty' => true, '--json' => true], $command->getDefinition()), $output);

        return ['exit' => $exit, 'envelope' => (array) json_decode(trim($output->fetch()), true)];
    }

    #[Test]
    public function a_clean_tree_is_reported_rather_than_failed(): void
    {
        $result = $this->runDirty();

        self::assertSame(0, $result['exit'], 'a clean checkout must not fail the run');
        self::assertSame('nothing_to_verify', $result['envelope']['verdict']);
        self::assertSame([], $result['envelope']['changed_files']);
    }

    /** And it still says what it could reach, so silence is never mistaken for cleanliness. */
    #[Test]
    public function the_scan_report_travels_with_the_empty_answer(): void
    {
        $envelope = $this->runDirty()['envelope'];

        self::assertArrayHasKey('dirty_scan', $envelope);
        self::assertContains(
            '(project root — not a git repository)',
            $envelope['dirty_scan']['unscannable'],
        );
    }

    /**
     * Default mode is NDJSON whose records are dispatched by `kind`, and every
     * ordinary run ends with a `verdict` record. The first version emitted the
     * single-envelope shape here regardless of mode, so a default-mode consumer
     * got a record it could not place. Raised in review of dev#84.
     */
    #[Test]
    public function the_default_mode_answer_is_an_ndjson_verdict_record(): void
    {
        $command = new AiVerifyCommand();
        $command->setName('ai:verify');
        $output = new BufferedOutput();

        $exit = $command->run(new ArrayInput(['--dirty' => true], $command->getDefinition()), $output);
        $record = (array) json_decode(trim($output->fetch()), true);

        self::assertSame(0, $exit);
        self::assertSame('verdict', $record['kind'] ?? null, 'NDJSON consumers dispatch on this');
        self::assertSame('nothing_to_verify', $record['verdict']);
        self::assertArrayHasKey('dirty_scan', $record, 'the reach travels in both shapes');
    }

    /** And --json still gets the single envelope, with its artifact id. */
    #[Test]
    public function the_json_mode_answer_is_still_one_envelope(): void
    {
        $envelope = $this->runDirty()['envelope'];

        self::assertSame('semitexa-dev.verify-report/v1', $envelope['artifact']);
        self::assertArrayNotHasKey('kind', $envelope);
    }

    /** Without the flag, no paths is still a misuse and still fails. */
    #[Test]
    public function the_generic_empty_case_still_fails(): void
    {
        $command = new AiVerifyCommand();
        $command->setName('ai:verify');
        $output = new BufferedOutput();

        $exit = $command->run(new ArrayInput(['--json' => true], $command->getDefinition()), $output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('no changed files supplied', $output->fetch());
    }
}

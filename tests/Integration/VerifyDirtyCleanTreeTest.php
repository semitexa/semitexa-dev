<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiVerifyCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
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
        $records = self::ndjson($output->fetch());

        self::assertSame(0, $exit);

        $verdict = self::recordOfKind($records, 'verdict');
        self::assertNotNull($verdict, 'NDJSON consumers dispatch on this');
        self::assertSame('nothing_to_verify', $verdict['verdict']);
        self::assertArrayHasKey('dirty_scan', $verdict, 'the reach travels in both shapes');

        self::assertNotNull(
            self::recordOfKind($records, 'dirty_scan'),
            'and in the same record a run WITH changes emits it in',
        );
    }

    /**
     * The case the empty one cannot cover: with changes to verify, the scan's
     * reach was added to the `--json` envelope ONLY. NDJSON is the default
     * mode, so the readers who never pass `--json` — every ordinary
     * `ai:verify --dirty` — were told nothing about the roots it could not ask,
     * which is the false green the report exists to prevent. Raised in review
     * of dev#84.
     *
     * At the emitter rather than through `run()`: the full path needs the
     * container to inject the trace appender, and what is in question here is
     * whether the emitter is given the scan at all.
     */
    #[Test]
    public function a_run_with_changes_emits_the_scan_as_its_own_ndjson_record(): void
    {
        $scan = ['scanned' => ['packages/semitexa-probe'], 'unscannable' => ['(project root — not a git repository)']];
        $plan = new VerificationPlan('standard', 'standard', [new ChangedFile('notes.md', ChangedFile::KIND_NON_PHP)], []);

        $records = self::ndjson($this->emitted($plan, $scan));

        $emitted = self::recordOfKind($records, 'dirty_scan');
        self::assertNotNull($emitted, 'the reach of the answer must reach the default mode too');
        self::assertSame($scan['scanned'], $emitted['scanned']);
        self::assertSame($scan['unscannable'], $emitted['unscannable']);

        self::assertNotNull(self::recordOfKind($records, 'verdict'), 'and the run still ends with its verdict');
        self::assertSame(
            'dirty_scan',
            $records[0]['kind'] ?? null,
            'before the answer, so it is read rather than scrolled past',
        );
    }

    /** Without the flag there is no scan, and no record claiming one. */
    #[Test]
    public function a_run_without_the_flag_emits_no_scan_record(): void
    {
        $plan = new VerificationPlan('standard', 'standard', [new ChangedFile('notes.md', ChangedFile::KIND_NON_PHP)], []);

        $records = self::ndjson($this->emitted($plan, null));

        self::assertNull(self::recordOfKind($records, 'dirty_scan'));
        self::assertSame('summary', $records[0]['kind'] ?? null);
    }

    /**
     * @param array{scanned: list<string>, unscannable: list<string>}|null $scan
     */
    private function emitted(VerificationPlan $plan, ?array $scan): string
    {
        $command = new AiVerifyCommand();
        $command->setName('ai:verify');
        $output = new BufferedOutput();

        $method = new \ReflectionMethod(AiVerifyCommand::class, 'emitNdjson');
        $method->invoke($command, $output, $plan, [], 'pass', null, $scan);

        return $output->fetch();
    }

    /** @return list<array<string, mixed>> */
    private static function ndjson(string $raw): array
    {
        return array_values(array_map(
            static fn(string $line): array => (array) json_decode($line, true),
            array_filter(explode("\n", trim($raw)), static fn(string $l): bool => trim($l) !== ''),
        ));
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return array<string, mixed>|null
     */
    private static function recordOfKind(array $records, string $kind): ?array
    {
        foreach ($records as $record) {
            if (($record['kind'] ?? null) === $kind) {
                return $record;
            }
        }

        return null;
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

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiStateCommand;
use Semitexa\Dev\Application\Service\Ai\State\ScoreStore;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Tester\CommandTester;

/** ai:state the way an agent uses it: score with evidence, read the line back. */
final class AiStateCommandTest extends TestCase
{
    private string $root;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-ai-state-' . uniqid('', true);
        mkdir($this->root . '/src/modules', 0755, true);
        file_put_contents($this->root . '/composer.json', '{"name":"temp/project"}');
        $this->originalCwd = getcwd() ?: null;
        chdir($this->root);
        ProjectRoot::reset();
        self::assertSame($this->root, ProjectRoot::get(), 'scores must never be written into the real project');
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            chdir($this->originalCwd);
        }
        ProjectRoot::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_score_with_its_evidence_moves_the_line_and_expires_in_thirty_days(): void
    {
        [$status, $out] = $this->state('show');
        self::assertSame([0, 'work ? · right ? · fast ?'], [$status, $out['line']]);

        [$status, $out] = $this->state('score', ['--criterion' => 'right.test-quality', '--points' => '7', '--evidence' => 'ai:quality check: tests.skip-calls 87']);
        self::assertSame([0, 'work ? · right 7% (5 unknown) · fast ?'], [$status, $out['line']]);
        self::assertIsArray($out['recorded']);
        self::assertSame(['right.test-quality', 7, 'agent'], [$out['recorded']['criterion'], $out['recorded']['points'], $out['recorded']['by']]);
        self::assertIsString($out['recorded']['expires_at']);
        self::assertIsString($out['recorded']['scored_at']);
        self::assertSame(30 * 86400, (new \DateTimeImmutable($out['recorded']['expires_at']))->getTimestamp() - (new \DateTimeImmutable($out['recorded']['scored_at']))->getTimestamp());

        $this->state('score', ['--criterion' => 'right.test-quality', '--points' => '08', '--evidence' => 'skip-calls 80', '--by' => 'operator', '--expires' => '2099-01-01']);
        $records = (new ScoreStore())->all();
        self::assertSame([8, 'operator', '2099-01-02T00:00:00+00:00'], [$records['right.test-quality']->points, $records['right.test-quality']->by, $records['right.test-quality']->expiresAt]);

        $this->state('score', ['--criterion' => 'right.test-quality', '--points' => '8', '--evidence' => 'e', '--expires' => '2099-01-01T10:00:00Z']);
        self::assertSame('2099-01-01T10:00:00+00:00', (new ScoreStore())->all()['right.test-quality']->expiresAt, 'Z is UTC');
    }

    #[Test]
    public function a_score_that_does_not_hold_is_refused_and_writes_nothing(): void
    {
        $refused = [
            [['--criterion' => 'right.test-quality', '--points' => '7'], 'score needs --criterion, --points (a whole number, 0 to its weight) and --evidence (what sets them)'],
            [['--criterion' => 'right.test-quality', '--points' => 'seven', '--evidence' => 'e'], 'score needs --criterion, --points (a whole number, 0 to its weight) and --evidence (what sets them)'],
            [['--criterion' => 'right.test-quality', '--points' => '-1', '--evidence' => 'e'], 'score needs --criterion, --points (a whole number, 0 to its weight) and --evidence (what sets them)'],
            [['--criterion' => 'right.test-quality', '--points' => '99999999999999999999', '--evidence' => 'e'], 'score needs --criterion, --points (a whole number, 0 to its weight) and --evidence (what sets them)'],
            [['--criterion' => 'right.test-quality', '--points' => '7', '--evidence' => '   '], 'score needs --criterion, --points (a whole number, 0 to its weight) and --evidence (what sets them)'],
            [['--criterion' => 'right.vibes', '--points' => '7', '--evidence' => 'e'], '"right.vibes" is no criterion of this rubric'],
            [['--criterion' => 'right.test-quality', '--points' => '11', '--evidence' => 'e'], '"right.test-quality" scores 0 to 10, not 11'],
            [['--criterion' => 'right.test-quality', '--points' => '7', '--evidence' => 'e', '--expires' => '2020-01-01'], '--expires 2020-01-01 is already past'],
            [['--criterion' => 'right.test-quality', '--points' => '7', '--evidence' => 'e', '--expires' => 'next week'], '--expires "next week" is not a date'],
        ];
        foreach ($refused as [$options, $message]) {
            [$status, $out] = $this->state('score', $options);
            self::assertSame([1, $message], [$status, $out['error']], json_encode($options) ?: '');
        }
        self::assertFileDoesNotExist($this->root . '/' . ScoreStore::PATH);
    }

    #[Test]
    public function a_broken_scores_file_is_an_error_not_an_unmeasured_project(): void
    {
        mkdir(dirname($this->root . '/' . ScoreStore::PATH), 0755, true);
        file_put_contents($this->root . '/' . ScoreStore::PATH, '{"schema_version": "semitexa.ai-state-scores/v1", "scores": {"work.installs": {"criterion": "work.release-tests", "points": 1, "evidence": "e", "by": "a", "scored_at": "2026-11-01T00:00:00+00:00", "expires_at": "2026-12-01T00:00:00+00:00"}}}');

        $message = 'var/ai-work/state/scores.json: the score filed under work.installs is for work.release-tests (repair or remove that file by hand; no score is recorded over it)';
        self::assertSame([1, $message], [$this->state('show')[0], $this->state('show')[1]['error']]);
        // Nor recorded over it: that would drop every row the file holds.
        self::assertSame([1, $message], [$this->state('score', ['--criterion' => 'work.installs', '--points' => '20', '--evidence' => 'e'])[0], $this->state('score', ['--criterion' => 'work.installs', '--points' => '20', '--evidence' => 'e'])[1]['error']]);
    }

    #[Test]
    public function evidence_prints_on_one_line_as_text_with_its_expiry(): void
    {
        $this->state('score', ['--criterion' => 'right.test-quality', '--points' => '7', '--evidence' => "check complete\nMake it right: 100% <error>x</error>", '--expires' => '2099-01-01']);
        self::assertSame('check complete Make it right: 100% <error>x</error>', (new ScoreStore())->all()['right.test-quality']->evidence, 'stored on one line');

        $command = new AiStateCommand();
        PropertyInjector::inject($command, new ArrayContainer([ScoreStore::class => new ScoreStore()]));
        $tester = new CommandTester($command);
        $tester->execute(['action' => 'show'], ['interactive' => true, 'decorated' => false]);
        $lines = array_values(array_filter(explode("\n", $tester->getDisplay()), static fn (string $l): bool => str_contains($l, 'Test quality')));

        self::assertCount(1, $lines);
        self::assertStringEndsWith('7/10  current  0d ago, until 2099-01-02 00:00 UTC: check complete Make it right: 100% <error>x</error>', rtrim($lines[0]));
        self::assertStringNotContainsString("\nMake it right: 100%", $tester->getDisplay());
    }

    #[Test]
    public function the_expiry_shows_in_utc_and_evidence_drops_control_bytes(): void
    {
        $this->state('score', ['--criterion' => 'right.test-quality', '--points' => '7', '--evidence' => "ok\e[2J\e[H done", '--expires' => '2099-01-01T00:30:00+14:00']);
        self::assertSame('ok[2J[H done', (new ScoreStore())->all()['right.test-quality']->evidence, 'ESC is gone, the text stays');

        $command = new AiStateCommand();
        PropertyInjector::inject($command, new ArrayContainer([ScoreStore::class => new ScoreStore()]));
        $tester = new CommandTester($command);
        $tester->execute(['action' => 'show'], ['interactive' => true, 'decorated' => false]);

        // 00:30 at +14:00 is 10:30 UTC the day before.
        self::assertStringContainsString('until 2098-12-31 10:30 UTC: ok[2J[H done', $tester->getDisplay());
        self::assertStringNotContainsString("\e", $tester->getDisplay());

        // A bidi override goes; a zero-width joiner (part of 👩‍💻) stays.
        $this->state('score', ['--criterion' => 'right.test-quality', '--points' => '7', '--evidence' => "by \u{202E}rotare\u{202C} 👩\u{200D}💻", '--expires' => '2099-01-01']);
        self::assertSame("by rotare 👩\u{200D}💻", (new ScoreStore())->all()['right.test-quality']->evidence);
        self::assertSame([1, 'score needs --criterion, --points (a whole number, 0 to its weight) and --evidence (what sets them)'], [$this->state('score', ['--criterion' => 'right.test-quality', '--points' => '7', '--evidence' => "\e\x07"])[0], $this->state('score', ['--criterion' => 'right.test-quality', '--points' => '7', '--evidence' => "\e\x07"])[1]['error']]);
    }

    #[Test]
    public function a_score_past_year_9999_or_already_expired_is_refused(): void
    {
        self::assertSame([1, '--expires "9999-12-31" is not a date'], [$this->state('score', ['--criterion' => 'right.test-quality', '--points' => '7', '--evidence' => 'e', '--expires' => '9999-12-31'])[0], $this->state('score', ['--criterion' => 'right.test-quality', '--points' => '7', '--evidence' => 'e', '--expires' => '9999-12-31'])[1]['error']]);

        $this->expectExceptionMessage('the score for "right.test-quality" expired before it could be recorded');
        (new ScoreStore())->record(new \Semitexa\Dev\Application\Service\Ai\State\ScoreRecord('right.test-quality', 7, 'e', 'agent', '2026-01-01T00:00:00+00:00', '2026-01-02T00:00:00+00:00'), \Semitexa\Dev\Application\Service\Ai\State\FrameworkRubric::rubric());
    }

    #[Test]
    public function a_link_to_nothing_at_the_scores_path_is_a_broken_file(): void
    {
        mkdir(dirname($this->root . '/' . ScoreStore::PATH), 0755, true);
        symlink($this->root . '/nowhere.json', $this->root . '/' . ScoreStore::PATH);

        [$status, $out] = $this->state('show');
        self::assertSame(1, $status);
        self::assertIsString($out['error']);
        self::assertStringStartsWith('var/ai-work/state/scores.json: cannot read file', $out['error']);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{int, array<string, mixed>}
     */
    private function state(string $action, array $options = []): array
    {
        $command = new AiStateCommand();
        PropertyInjector::inject($command, new ArrayContainer([ScoreStore::class => new ScoreStore()]));
        $tester = new CommandTester($command);
        $status = $tester->execute(['action' => $action] + $options, ['interactive' => false]);
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, $tester->getDisplay());

        /** @var array<string, mixed> $decoded */
        return [$status, $decoded];
    }
}

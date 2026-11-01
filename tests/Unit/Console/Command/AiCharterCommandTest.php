<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiCharterCommand;
use Semitexa\Dev\Application\Service\Ai\Charter\Charter;
use Semitexa\Dev\Application\Service\Ai\Charter\CharterStore;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The interview end to end, the way an agent drives it: next, answer, next,
 * until check passes; and every malformed answer refused without a write.
 */
final class AiCharterCommandTest extends TestCase
{
    private string $root;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-ai-charter-' . uniqid('', true);
        mkdir($this->root . '/src/modules', 0755, true);
        file_put_contents($this->root . '/composer.json', '{"name":"temp/project"}');
        $this->originalCwd = getcwd() ?: null;
        chdir($this->root);
        ProjectRoot::reset();
        self::assertSame($this->root, ProjectRoot::get(), 'the charter must never be written into the real project');
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
    public function the_owner_is_interviewed_question_by_question_until_the_charter_is_complete(): void
    {
        [$status, $out] = $this->charter('next');
        self::assertSame(0, $status);
        self::assertSame(['complete' => false, 'open' => 7, 'field' => 'goal'], ['complete' => $out['complete'], 'open' => $out['open'], 'field' => self::nextField($out)]);
        self::assertSame(\Semitexa\Dev\Application\Service\Ai\Charter\CharterInterview::HOW_TO_ASK, $out['how_to_ask']);
        self::assertSame([1, ['unanswered: goal', 'unanswered: audience', 'unanswered: problem', 'unanswered: signs', 'unanswered: constraints', 'unanswered: non_goals', 'unanswered: owner_decides']], [$this->charter('check')[0], $this->charter('check')[1]['problems']]);

        $answers = [
            ['--field' => 'goal', '--value' => ['Bakers sell bread online']],
            ['--field' => 'audience', '--value' => ['Local bakers and their customers']],
            ['--field' => 'problem', '--value' => ['Orders come by phone and get lost']],
            ['--field' => 'signs', '--value' => ['Ten orders a day'], '--source' => 'orders.per-day'],
            ['--field' => 'signs', '--value' => ['A baker publishes a menu alone']],
            ['--field' => 'constraints', '--value' => ['EU hosting', 'No card data stored']],
            ['--field' => 'non_goals', '--none' => true],
            ['--field' => 'owner_decides', '--keep' => true],
        ];
        $asked = [];
        foreach ($answers as $answer) {
            [$s, $o] = $this->charter('answer', $answer);
            self::assertSame(0, $s, json_encode($o) ?: '');
            $asked[] = self::nextField($o);
        }
        self::assertSame(['audience', 'problem', 'signs', 'constraints', 'constraints', 'non_goals', 'owner_decides', null], $asked);

        [$status, $out] = $this->charter('check');
        self::assertSame([0, true, []], [$status, $out['complete'], $out['problems']]);
        $charter = (new CharterStore())->get();
        self::assertSame('Bakers sell bread online', $charter->goal);
        self::assertSame([['id' => 's1', 'text' => 'Ten orders a day', 'kind' => 'computed', 'source' => 'orders.per-day'], ['id' => 's2', 'text' => 'A baker publishes a menu alone', 'kind' => 'judged', 'source' => null]], $charter->toArray()['signs']);
        self::assertSame(['EU hosting', 'No card data stored'], $charter->constraints);
        self::assertSame([], $charter->nonGoals);
        self::assertSame(Charter::DEFAULT_OWNER_DECIDES, $charter->ownerDecides);
        self::assertFileExists($this->root . '/var/ai-work/charter.json');
    }

    #[Test]
    public function a_list_answer_adds_unless_it_replaces(): void
    {
        $this->charter('answer', ['--field' => 'constraints', '--value' => ['EU hosting']]);
        $this->charter('answer', ['--field' => 'constraints', '--value' => ['EU hosting', 'PHP only']]);
        self::assertSame(['EU hosting', 'PHP only'], (new CharterStore())->get()->constraints);

        $this->charter('answer', ['--field' => 'constraints', '--value' => ['Budget 100 EUR a month'], '--replace' => true]);
        self::assertSame(['Budget 100 EUR a month'], (new CharterStore())->get()->constraints);
    }

    #[Test]
    public function what_the_owner_decides_starts_from_the_defaults(): void
    {
        $this->charter('answer', ['--field' => 'owner_decides', '--value' => ['changing the database schema']]);
        self::assertSame([...Charter::DEFAULT_OWNER_DECIDES, 'changing the database schema'], (new CharterStore())->get()->ownerDecides);

        $this->charter('answer', ['--field' => 'owner_decides', '--value' => ['releases only'], '--replace' => true]);
        self::assertSame(['releases only'], (new CharterStore())->get()->ownerDecides);

        $this->charter('answer', ['--field' => 'owner_decides', '--none' => true]);
        self::assertSame([], (new CharterStore())->get()->ownerDecides, 'none: the agent decides everything');
    }

    #[Test]
    public function the_same_sign_recorded_twice_is_one_sign(): void
    {
        $this->charter('answer', ['--field' => 'signs', '--value' => ['Ten orders a day']]);
        $this->charter('answer', ['--field' => 'signs', '--value' => ['ten orders a day', 'A menu in five minutes']]);

        self::assertSame([['s1', 'Ten orders a day'], ['s2', 'A menu in five minutes']], array_map(static fn ($s): array => [$s->id, $s->text], (new CharterStore())->get()->signs));
    }

    #[Test]
    public function a_refused_answer_leaves_a_charter_as_it_was(): void
    {
        $this->charter('answer', ['--field' => 'goal', '--value' => ['Bakers sell bread online']]);
        $before = (string) file_get_contents($this->root . '/var/ai-work/charter.json');

        foreach ([['--field' => 'goal', '--none' => true], ['--field' => 'goal', '--value' => ['  ']], ['--field' => 'signs', '--value' => ['x'], '--replace' => true], ['--field' => 'owner_decides', '--keep' => true, '--value' => ['x']]] as $options) {
            self::assertSame(1, $this->charter('answer', $options)[0], json_encode($options) ?: '');
        }
        self::assertSame($before, (string) file_get_contents($this->root . '/var/ai-work/charter.json'));
    }

    #[Test]
    public function an_answer_that_cannot_be_written_says_so(): void
    {
        mkdir($this->root . '/var', 0755, true);
        file_put_contents($this->root . '/var/ai-work', 'a file where the directory should be');

        [$status, $out] = @$this->charter('answer', ['--field' => 'goal', '--value' => ['x']]);
        self::assertSame([1, 'error'], [$status, $out['status']]);
        self::assertIsString($out['error']);
        self::assertStringStartsWith('the answer was not recorded: ', $out['error']);
    }

    #[Test]
    public function a_malformed_answer_is_refused_and_writes_nothing(): void
    {
        $refused = [
            [['--field' => 'mission', '--value' => ['x']], '--field is one of: goal, audience, problem, signs, constraints, non_goals, owner_decides'],
            [['--field' => 'goal'], '--field=goal takes exactly one --value'],
            [['--field' => 'goal', '--value' => ['a', 'b']], '--field=goal takes exactly one --value'],
            [['--field' => 'goal', '--value' => ['a'], '--source' => 'm'], '--source belongs to a sign: --field=signs'],
            [['--field' => 'signs', '--none' => true], 'a project needs at least one sign of success: --field=signs takes --value'],
            [['--field' => 'signs', '--value' => ['a', 'b'], '--source' => 'm'], '--source measures one sign: give it with a single --value'],
            [['--field' => 'constraints', '--keep' => true], '--keep belongs to --field=owner_decides'],
            [['--field' => 'constraints'], '--field=constraints takes --value (repeatable), or --none, one of them'],
            [['--field' => 'owner_decides', '--keep' => true, '--none' => true], '--field=owner_decides takes --value (repeatable), or --none, or --keep, one of them'],
        ];
        foreach ($refused as [$options, $error]) {
            [$status, $out] = $this->charter('answer', $options);
            self::assertSame([1, 'error', $error], [$status, $out['status'], $out['error']], json_encode($options) ?: '');
        }
        self::assertFileDoesNotExist($this->root . '/var/ai-work/charter.json');
    }

    #[Test]
    public function a_broken_charter_file_is_an_error_not_a_fresh_interview(): void
    {
        mkdir($this->root . '/var/ai-work', 0755, true);
        file_put_contents($this->root . '/var/ai-work/charter.json', '{"goal": "x"}');

        [$status, $out] = $this->charter('next');
        self::assertSame([1, 'var/ai-work/charter.json: not a semitexa.ai-charter/v1 charter'], [$status, $out['error']]);
    }

    /** @param array<string, mixed> $out */
    private static function nextField(array $out): ?string
    {
        $question = $out['next_question'] ?? null;
        if ($question === null) {
            return null;
        }
        self::assertIsArray($question);
        self::assertIsString($question['field'] ?? null);

        return $question['field'];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{int, array<string, mixed>}
     */
    private function charter(string $action, array $options = []): array
    {
        $command = new AiCharterCommand();
        PropertyInjector::inject($command, new ArrayContainer([CharterStore::class => new CharterStore()]));
        $tester = new CommandTester($command);
        $status = $tester->execute(['action' => $action] + $options, ['interactive' => false]);
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, $tester->getDisplay());

        /** @var array<string, mixed> $decoded */
        return [$status, $decoded];
    }
}

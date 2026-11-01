<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Charter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Charter\Charter;
use Semitexa\Dev\Application\Service\Ai\Charter\CharterInterview;
use Semitexa\Dev\Application\Service\Ai\Charter\CharterStore;
use Semitexa\Dev\Application\Service\Ai\Charter\OwnerQuestions;
use Semitexa\Dev\Application\Service\Ai\Charter\SuccessSign;

/** What ai:orient shows a cold-start agent of the owner's interview. */
final class OwnerQuestionsTest extends TestCase
{
    private string $root;
    private ?string $cwd = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-owner-questions-' . uniqid('', true);
        mkdir($this->root . '/var/ai-work', 0755, true);
        // src/modules is what makes ProjectRoot take this directory: without it
        // the store wrote into the real project (found 01.11, the first run).
        mkdir($this->root . '/src/modules', 0755, true);
        file_put_contents($this->root . '/composer.json', '{"name":"temp/project"}');
        $this->cwd = getcwd() ?: null;
        chdir($this->root);
        ProjectRoot::reset();
        self::assertSame($this->root, ProjectRoot::get());
    }

    protected function tearDown(): void
    {
        if ($this->cwd !== null) {
            chdir($this->cwd);
        }
        ProjectRoot::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function the_owner_s_next_question_leads_the_agent_s_next_step(): void
    {
        $questions = OwnerQuestions::of(new CharterStore());
        self::assertSame(['goal', 7, CharterInterview::HOW_TO_ASK, null], [$questions['next']['field'] ?? null, $questions['open'], $questions['how_to_ask'], $questions['error']]);

        $hints = OwnerQuestions::lead(['summary' => 'Resume task `tk-a`.', 'commands' => [['cmd' => 'ai:work', 'args' => ['resume'], 'why' => 'resume']]], $questions);
        self::assertSame('Semitexa has a question for the project\'s owner: "' . CharterInterview::questions()[0]->question . '" Resume task `tk-a`.', $hints['summary']);
        self::assertSame(['ai:charter', 'ai:work'], array_column($hints['commands'], 'cmd'));
        self::assertSame(['answer', '--field=goal', '--value="<the owner\'s answer>"', '--json'], $hints['commands'][0]['args']);
    }

    #[Test]
    public function a_list_question_also_offers_its_none_and_the_authority_one_its_defaults(): void
    {
        $store = new CharterStore();
        $store->save(new Charter('g', 'a', 'p', [new SuccessSign('s1', 'x')]));
        self::assertSame([['answer', '--field=constraints', '--value="<the owner\'s answer>"', '--json'], ['answer', '--field=constraints', '--none', '--json']], array_column(OwnerQuestions::lead(['summary' => '', 'commands' => []], OwnerQuestions::of($store))['commands'], 'args'));

        $store->save(new Charter('g', 'a', 'p', [new SuccessSign('s1', 'x')], [], []));
        self::assertSame(
            [['answer', '--field=owner_decides', '--value="<the owner\'s answer>"', '--json'], ['answer', '--field=owner_decides', '--keep', '--json'], ['answer', '--field=owner_decides', '--none', '--json']],
            array_column(OwnerQuestions::lead(['summary' => '', 'commands' => []], OwnerQuestions::of($store))['commands'], 'args'),
        );
    }

    #[Test]
    public function a_complete_charter_asks_nothing_and_a_broken_one_says_so(): void
    {
        (new CharterStore())->save(new Charter('g', 'a', 'p', [new SuccessSign('s1', 'x')], [], [], []));
        $questions = OwnerQuestions::of(new CharterStore());
        self::assertSame([null, 0, null, null], [$questions['next'], $questions['open'], $questions['how_to_ask'], $questions['error']]);
        $hints = ['summary' => 'Resume.', 'commands' => []];
        self::assertSame($hints, OwnerQuestions::lead($hints, $questions));

        file_put_contents($this->root . '/var/ai-work/charter.json', '[1]');
        $questions = OwnerQuestions::of(new CharterStore());
        self::assertSame([null, 'var/ai-work/charter.json: not a semitexa.ai-charter/v1 charter'], [$questions['next'], $questions['error']]);
        self::assertSame('The project charter cannot be read (var/ai-work/charter.json: not a semitexa.ai-charter/v1 charter): fix or remove it before asking the owner anything. Resume.', OwnerQuestions::lead($hints, $questions)['summary']);
    }
}

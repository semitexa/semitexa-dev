<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Classifier;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Classifier\TaskClassifier;

/**
 * Requests an operator actually types, and where they have to land.
 *
 * The confidence test proves a bad match says it is unsure. This one proves
 * the match is right: "fix the 500 error" used to land on fix_template_text,
 * and every Ukrainian request landed on unknown_task because the tokenizer
 * threw Cyrillic away.
 */
final class TaskClassifierRoutingTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function requests(): iterable
    {
        yield 'a bug is investigated, not re-worded' => ['fix the 500 error on /login', 'debug_investigate'];
        yield 'a template typo is still a template fix' => ['fix a typo in the login template', 'fix_template_text'];
        yield 'plural nouns still match' => ['add pages that list invoices', 'add_html_page'];
        yield 'uk: add a page' => ['додай сторінку зі списком рахунків', 'add_html_page'];
        yield 'uk: fix an error' => ['виправ помилку 500 на логіні', 'debug_investigate'];
        yield 'uk: console command' => ['створи консольну команду, що чистить старі сесії', 'add_cli_command'];
        yield 'uk: rename' => ['перейменуй сутність Invoice на Bill', 'rename_symbol'];
        yield 'uk: event listener' => ['додай слухача на подію UserRegistered', 'add_event_listener'];
    }

    #[Test]
    #[DataProvider('requests')]
    public function a_real_request_lands_on_its_recipe(string $request, string $recipe): void
    {
        self::assertSame($recipe, (new TaskClassifier())->classify($request)->recipe->id);
    }

    #[Test]
    public function many_is_not_a_bug_and_look_is_not_an_event(): void
    {
        $result = (new TaskClassifier())->classify('подивись багато');

        self::assertSame('unknown_task', $result->recipe->id);
    }
}

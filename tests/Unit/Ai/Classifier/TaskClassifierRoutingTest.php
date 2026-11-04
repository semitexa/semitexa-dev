<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Classifier;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Classifier\ClassificationResult;
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
        // ep-bench-rich-ui-findings, in the words of the benchmark's own tickets.
        yield 'a slow widget' => ['build a dashboard with four widgets, the revenue widget is expensive and fills in afterwards', 'add_deferred_region'];
        yield 'a live board' => ['live board that updates by itself', 'add_live_region'];
        yield 'watching without reload' => ['build a board so the team can watch orders move without touching the page', 'add_live_region'];
        yield 'live stock for viewers' => ['show live stock to 20 viewers without a page reload', 'add_live_region'];
        yield 'a grid' => ['build an order grid with sorting, filtering and paging over 100000 rows', 'add_data_grid'];
        yield 'a wizard' => ['build a multi-step order wizard with three steps', 'add_wizard_form'];
        yield 'uk: a grid' => ['таблиця замовлень із сортуванням і фільтром', 'add_data_grid'];
        yield 'uk: a wizard' => ['майстер замовлення на три кроки', 'add_wizard_form'];
        yield 'a grid in few words' => ['build an order grid with sorting and paging', 'add_data_grid'];
        yield 'a wizard in few words' => ['a multi-step order wizard', 'add_wizard_form'];
        yield 'a plain page stays a page' => ['add a page that shows the order', 'add_html_page'];
        yield 'a bug is investigated, not re-worded' => ['fix the 500 error on /login', 'debug_investigate'];
        yield 'a template typo is still a template fix' => ['fix a typo in the login template', 'fix_template_text'];
        yield 'a vague "wrong" does not steal a template fix' => ['fix wrong label in the header template', 'fix_template_text'];
        yield 'a vague "issue" does not steal a copy fix' => ['fix issue with the page title text', 'fix_template_text'];
        yield 'an error in a template is a template fix' => ['fix error in template', 'fix_template_text'];
        yield 'uk: an error in a template' => ['виправ помилку в шаблоні', 'fix_template_text'];
        yield 'a 404 page is still a page' => ['add a 404 page', 'add_html_page'];
        yield 'plural nouns still match' => ['add pages that list invoices', 'add_html_page'];
        yield 'uk: add a page' => ['додай сторінку зі списком рахунків', 'add_html_page'];
        yield 'uk: fix an error' => ['виправ помилку 500 на логіні', 'debug_investigate'];
        yield 'uk: console command' => ['створи консольну команду, що чистить старі сесії', 'add_cli_command'];
        yield 'uk: rename' => ['перейменуй сутність Invoice на Bill', 'rename_symbol'];
        yield 'uk: event listener' => ['додай слухача на подію UserRegistered', 'add_event_listener'];
        // bench-ai-task-misroutes-add-field: a stored form field is end to end, a DTO field is not.
        yield 'a field on a form is end to end' => ['add a phone field to the contact form', 'add_form_field'];
        yield 'storing what a form takes' => ['store the phone number entered on the form', 'add_form_field'];
        yield 'a column on an entity' => ['add a bio column to the user entity', 'add_form_field'];
        yield 'a payload field stays a payload field' => ['add a field to the payload', 'add_payload_field'];
    }

    #[Test]
    #[DataProvider('requests')]
    public function a_real_request_lands_on_its_recipe(string $request, string $recipe): void
    {
        self::assertSame($recipe, (new TaskClassifier())->classify($request)->recipe->id);
    }

    #[Test]
    public function a_short_ui_request_is_confident_enough_not_to_be_sent_back(): void
    {
        foreach (['build an order grid with sorting and paging', 'a multi-step order wizard', 'live board that updates by itself'] as $request) {
            self::assertSame(ClassificationResult::CONFIDENCE_HIGH, (new TaskClassifier())->classify($request)->confidence, $request);
        }
    }

    #[Test]
    public function many_is_not_a_bug_and_look_is_not_an_event(): void
    {
        $result = (new TaskClassifier())->classify('подивись багато');

        self::assertSame('unknown_task', $result->recipe->id);
    }
}

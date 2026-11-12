<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Classifier;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Classifier\TaskClassifier;
use Semitexa\Dev\Application\Service\Ai\Recipe\CapabilitySearch;
use Semitexa\Dev\Application\Service\Ai\Recipe\RecipeRegistry;

/**
 * One-line tasks an agent is handed every day reach a recipe, or what the
 * framework has for them (tk-task-misses-everyday-tasks). "Redirect /old to
 * /new" and "send a welcome mail" came back unknown_task, with advice to open
 * an epic for a one-file job.
 */
final class EverydayTasksTest extends TestCase
{
    /** @var array<string, string> task => recipe */
    private const RECIPES = [
        'redirect /old-pricing to /pricing' => 'add_redirect',
        '301 redirect the old blog url to the new one' => 'add_redirect',
        'send a welcome email after signup' => 'send_mail',
        'send a password reset email' => 'send_mail',
        'email the invoice to the customer' => 'send_mail',
        'add a JSON endpoint that returns the server time' => 'add_json_route',
        'add an endpoint that returns the app version as json' => 'add_json_route',
        'add a health check endpoint' => 'add_json_route',
        'add an authenticated JSON route for invoices' => 'add_authenticated_json_route',
        'add a JSON endpoint for authenticated users that returns their profile' => 'add_authenticated_json_route',
        // Review, dev#240: the response words once outweighed the one that asks for a guard.
        'add a private JSON endpoint that returns health status' => 'add_authenticated_json_route',
        'add a JSON endpoint behind a bearer token that returns the version' => 'add_authenticated_json_route',
        'add a page that lists products' => 'add_html_page',
        'add a console command that clears old sessions' => 'add_cli_command',
        'add a service that computes tax' => 'add_service',
        'listen for UserRegistered and log it' => 'add_event_listener',
        'add a field to the signup form' => 'add_form_field',
        'rename UserService to AccountService' => 'rename_symbol',
        'fix the typo on the about page' => 'fix_template_text',
        'add a new module called Billing' => 'add_module',
    ];

    /** @var array<string, string> task no recipe covers => the capability that does */
    private const CAPABILITIES = [
        'receive a webhook from Stripe' => 'webhooks.inbound',
        'schedule a cron job' => 'scheduler.jobs',
    ];

    #[Test]
    public function an_everyday_task_reaches_its_recipe(): void
    {
        $classifier = new TaskClassifier();
        foreach (self::RECIPES as $task => $recipe) {
            self::assertSame($recipe, $classifier->classify($task)->recipe->id, $task);
        }
        // The new recipes are not a guess: each reaches the bar on its plainest phrasing.
        foreach (['send a welcome email after signup', '301 redirect the old blog url to the new one', 'add a JSON endpoint that returns the server time'] as $task) {
            self::assertSame('high', $classifier->classify($task)->confidence, $task);
        }
    }

    /** Review, dev#240: a task that names a guard never gets an open endpoint without a question. */
    #[Test]
    public function a_guarded_endpoint_is_never_a_confident_public_route(): void
    {
        $classifier = new TaskClassifier();
        foreach ([
            'add a protected JSON endpoint that returns health status',
            'add a sign-in protected JSON endpoint that returns the version',
            'add an admin JSON endpoint that returns the status',
            'add a JSON endpoint that requires authentication and returns the status',
            'add an internal JSON endpoint for members only',
            'add a JSON endpoint behind a JWT that returns the version',
            // The repeated "in" once hid "sign in" from the exclusion (review, dev#240).
            'add a JSON endpoint in Billing behind sign-in that returns health status',
        ] as $task) {
            $result = $classifier->classify($task);
            self::assertSame('add_authenticated_json_route', $result->recipe->id, $task);
            self::assertSame('low', $result->confidence, $task);
        }
        // Ruling the open route out does not make the guarded one a sure thing: these are asked.
        foreach ([
            'add a private JSON endpoint that returns health status',
            'add a public JSON endpoint without auth that returns the version',
            'add a public JSON endpoint that returns the CSRF token',
        ] as $task) {
            self::assertSame('low', $classifier->classify($task)->confidence, $task);
        }
        self::assertSame('high', $classifier->classify('add an authenticated JSON route for invoices')->confidence);
    }

    #[Test]
    public function a_task_no_recipe_covers_is_answered_with_the_capability_that_does(): void
    {
        $catalog = CapabilitySearch::catalog(dirname(__DIR__, 7));
        foreach (self::CAPABILITIES as $task => $capability) {
            self::assertSame('unknown_task', (new TaskClassifier())->classify($task)->recipe->id, $task);
            self::assertSame($capability, CapabilitySearch::hits($task, $catalog)[0]['id'] ?? null, $task);
        }
    }

    #[Test]
    public function no_match_no_longer_sends_the_agent_to_open_an_epic(): void
    {
        $recipe = RecipeRegistry::find('unknown_task');
        self::assertNotNull($recipe);
        self::assertStringContainsString('ai:ask', $recipe->summary);
        self::assertStringContainsString('ai:verify', $recipe->summary);
        self::assertStringNotContainsString('ai:epic', $recipe->summary);
    }
}

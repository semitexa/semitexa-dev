<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Recipe;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Recipe\CapabilitySearch;

/**
 * A task no recipe matches is answered with what the framework already has
 * for its words (tk-task-misses-everyday-tasks): "send a welcome mail" came
 * back unknown_task with advice to open an epic, mail.outbound one search away.
 */
final class CapabilitySearchTest extends TestCase
{
    #[Test]
    public function the_capability_that_mentions_most_of_the_words_comes_first(): void
    {
        $catalog = [
            ['id' => 'notifications.send', 'summary' => 'Send a notification', 'use_when' => 'A user should hear about something'],
            ['id' => 'mail.outbound', 'summary' => 'Outbound email', 'use_when' => 'The application has to send mail a person will read'],
            ['id' => 'cache.store', 'summary' => 'A cache', 'use_when' => 'Something is slow'],
        ];

        $hits = CapabilitySearch::hits('send a welcome mail', $catalog);

        self::assertSame(['mail.outbound', 'notifications.send'], array_column($hits, 'id'));
        self::assertSame(['send', 'mail'], $hits[0]['words']);
    }

    #[Test]
    public function words_too_short_or_too_common_find_nothing(): void
    {
        self::assertSame([], CapabilitySearch::hits('add a new page for the user', [['id' => 'x.page', 'summary' => 'add a new page for the user']]));
    }

    /** The index this framework ships answers the everyday tasks that had no recipe. */
    #[Test]
    public function the_shipped_index_answers_everyday_tasks(): void
    {
        $catalog = CapabilitySearch::catalog(dirname(__DIR__, 7));
        self::assertNotSame([], $catalog, 'the shipped capability index is read');

        foreach ([
            'send a welcome mail when a user signs up' => 'mail.outbound',
            'receive a webhook from Stripe' => 'webhooks.inbound',
            'let users upload files' => 'files.manager',
            'cache the price list' => 'cache.store',
            'schedule a cron job' => 'scheduler.jobs',
        ] as $task => $expected) {
            // First, not merely among the hits: a capability whose id names the word outranks prose that mentions it.
            self::assertSame($expected, CapabilitySearch::hits($task, $catalog)[0]['id'] ?? null, $task);
        }
    }
}

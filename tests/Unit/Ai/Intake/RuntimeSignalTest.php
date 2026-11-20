<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Intake;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Intake\RuntimeSignal;

/**
 * The intake rewrites an idea's next step only while it is still the intake's
 * own, word for word: a note an owner wrote anywhere into it keeps the task.
 */
final class RuntimeSignalTest extends TestCase
{
    private const LOG = 'var/log/app.log';

    private static function signal(): RuntimeSignal
    {
        return new RuntimeSignal(str_repeat('a', 40), 'pipeline', 'Unhandled exception', 'Shop\\OutOfStock', 'src/modules/Shop/Handler.php', ['ShopPayload'], 2, '2026-11-20T10:00:00+00:00', '2026-11-20T11:00:00+00:00');
    }

    public function test_the_intakes_own_next_step_is_recognised(): void
    {
        $signal = self::signal();

        self::assertTrue(RuntimeSignal::wroteNextStep($signal->taskId(), $signal->nextStep(self::LOG), self::LOG));
        self::assertTrue(RuntimeSignal::wroteNextStep($signal->taskId(), $signal->nextStep('var/log/app errors.log'), 'var/log/app errors.log'), 'a configured path with a space');
    }

    public function test_a_note_written_into_any_field_keeps_the_task(): void
    {
        $signal = self::signal();
        $own = $signal->nextStep(self::LOG);
        $notes = [
            'in the log field' => str_replace('in var/log/app.log,', 'in var/log/app.log (owner: real bug in checkout, do not discard),', $own),
            'after a time' => str_replace('to 2026-11-20T11:00:00+00:00.', 'to 2026-11-20T11:00:00+00:00 URGENT.', $own),
            'in the grep' => str_replace('--grep=OutOfStock', '--grep=OutOfStock|owner-note', $own),
            'before it' => 'Checked with Olena. ' . $own,
            'after it' => $own . ' Fix in the cart first.',
        ];

        foreach ($notes as $where => $edited) {
            self::assertNotSame($own, $edited, "the {$where} case edits something");
            self::assertFalse(RuntimeSignal::wroteNextStep($signal->taskId(), $edited, self::LOG), "a note {$where} is the owner's");
        }
    }

    public function test_a_title_shows_only_the_plain_words_a_message_starts_with(): void
    {
        $title = static fn (string $message, ?string $class = null): string => (new RuntimeSignal(str_repeat('b', 40), 'files', $message, $class, null, [], 1, '', ''))->title();

        self::assertSame('Runtime error: Dashboard widget failed (files)', $title('Dashboard widget failed'));
        self::assertSame('Runtime error: Login failed for… (files)', $title('Login failed for user=V password=V'));
        self::assertSame('Runtime error: Не вдалося зберегти файл (files)', $title('Не вдалося зберегти файл'));
        foreach (["Can't open '/home/john smith/cv.pdf'", "Doesn't match ' bob smith '", "Couldn't decode '{\"user\": \"bob smith\"}' here"] as $message) {
            self::assertSame('Runtime error: a logged error (files)', $title($message), $message);
        }
        self::assertSame('Runtime error: Reader: Could not read… (files)', $title('Could not read sk_live_abcdefghijklmnop now', 'App\\Reader'), 'with a class and no file, the plain head is the detail');
    }

    public function test_another_log_is_not_this_runs_text(): void
    {
        $signal = self::signal();

        self::assertFalse(RuntimeSignal::wroteNextStep($signal->taskId(), $signal->nextStep('var/log/other.log'), self::LOG));
    }
}

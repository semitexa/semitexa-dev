<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Intake;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Intake\SignalFingerprint;

final class SignalFingerprintTest extends TestCase
{
    public function test_what_varies_per_occurrence_is_normalised_away(): void
    {
        self::assertSame('Job N failed for Q at HOST token=V', SignalFingerprint::normalise('Job 42 failed for "alice" at 10.0.0.1:6379 token=abc'));
        self::assertSame(
            'GET URL by EMAIL id ID T',
            SignalFingerprint::normalise('GET https://x.test/a?b=1 by bob@x.test id 123e4567-e89b-12d3-a456-426614174000 sse_9f8e7d6c5b4a3f2e1d0c'),
        );
        self::assertSame('Dashboard widget failed', SignalFingerprint::normalise("  Dashboard   widget\tfailed "));
    }

    public function test_one_error_is_one_fingerprint_and_another_class_or_file_is_another(): void
    {
        $one = SignalFingerprint::of('pipeline', 'Job 7 failed', 'Shop\\OutOfStock', 'src/modules/Shop/Handler.php');

        self::assertSame(40, strlen($one));
        self::assertSame($one, SignalFingerprint::of('pipeline', 'Job 1234 failed', 'Shop\\OutOfStock', 'src/modules/Shop/Handler.php'), 'only the id differs');
        self::assertNotSame($one, SignalFingerprint::of('pipeline', 'Job 7 failed', 'Shop\\NoPrice', 'src/modules/Shop/Handler.php'));
        self::assertNotSame($one, SignalFingerprint::of('pipeline', 'Job 7 failed', 'Shop\\OutOfStock', 'src/modules/Shop/Cart.php'));
        self::assertNotSame($one, SignalFingerprint::of('queue', 'Job 7 failed', 'Shop\\OutOfStock', 'src/modules/Shop/Handler.php'));
    }

    public function test_a_credential_after_its_name_or_a_long_key_is_normalised_away(): void
    {
        self::assertSame('Bearer V rejected', SignalFingerprint::normalise('Bearer abcdefghijklmnopqrstuv rejected'));
        self::assertSame('password V', SignalFingerprint::normalise('password: hunter2'));
        self::assertSame('key T refused', SignalFingerprint::normalise('key sk_live_abcdefghijklmnop refused'));
        self::assertSame('Login failed for user=V password=V', SignalFingerprint::normalise('Login failed for user=bob password=hunter2'));
        self::assertSame('ConstraintViolationException thrown', SignalFingerprint::normalise('ConstraintViolationException thrown'), 'a class name is not a key');
    }

    public function test_only_the_head_of_a_long_message_is_read(): void
    {
        $long = str_repeat('ab-', 70000);

        $normalised = SignalFingerprint::normalise($long);

        self::assertNotSame('', $normalised);
        self::assertLessThanOrEqual(SignalFingerprint::MAX_MESSAGE, strlen($normalised));
        self::assertSame(SignalFingerprint::normalise(substr($long, 0, SignalFingerprint::MAX_MESSAGE)), $normalised);
    }

    public function test_with_a_class_and_a_file_the_message_does_not_split_one_defect(): void
    {
        self::assertSame(
            SignalFingerprint::of('pipeline', 'User john not found', 'App\\UserMissing', 'src/modules/Users/Find.php'),
            SignalFingerprint::of('pipeline', 'User mary not found', 'App\\UserMissing', 'src/modules/Users/Find.php'),
        );
        self::assertNotSame(
            SignalFingerprint::of('ui', 'Dashboard widget failed', null, null),
            SignalFingerprint::of('ui', 'Command palette source failed', null, null),
            'without a class and a file the message is all there is to tell them apart',
        );
    }

    public function test_the_site_is_the_file_inside_the_project_without_its_line(): void
    {
        self::assertSame('src/modules/Shop/Handler.php', SignalFingerprint::site('/app/src/modules/Shop/Handler.php:42', '/app'));
        self::assertSame('src/modules/Shop/Handler.php', SignalFingerprint::site('/app/src/modules/Shop/Handler.php', '/app/'));
        self::assertSame('/usr/share/php/Thing.php', SignalFingerprint::site('/usr/share/php/Thing.php:3', '/app'), 'outside the project it stays as written');
        self::assertNull(SignalFingerprint::site('', '/app'));
        self::assertNull(SignalFingerprint::site(null, '/app'));
    }

    public function test_the_same_error_on_another_checkout_is_the_same_signal(): void
    {
        self::assertSame(
            SignalFingerprint::of('pipeline', 'm', 'E', SignalFingerprint::site('/var/www/html/src/X.php:10', '/var/www/html')),
            SignalFingerprint::of('pipeline', 'm', 'E', SignalFingerprint::site('/home/dev/shop/src/X.php:11', '/home/dev/shop')),
        );
    }
}

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

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\AbstractPayloadRoute;
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Dev\Application\Service\Ai\Verify\Mechanism\PublicPageReadsIdentityDetector;

/**
 * A public page whose handler decides who may see it (tk-public-payload-hand-rolled-auth).
 *
 * The first case is the Guard run's /guard/profile, line for line in shape: a
 * public payload, a handler asking isGuest() and rendering a guest card. Every
 * other case is one the rule must stay quiet on.
 */
final class PublicPageReadsIdentityDetectorTest extends TestCase
{
    private const PROFILE_HANDLER = [
        '<?php',
        'namespace App\Modules\Guard\Application\Handler\PayloadHandler;',
        '',
        'use App\Modules\Guard\Application\Payload\Request\ProfilePayload;',
        'use Semitexa\Core\Auth\AuthContextInterface;',
        '',
        '#[AsPayloadHandler(payload: ProfilePayload::class, resource: ProfileResource::class)]',
        'final class ProfileHandler implements TypedHandlerInterface',
        '{',
        '    public function handle(ProfilePayload $payload, ProfileResource $resource): ProfileResource',
        '    {',
        '        if ($this->auth->isGuest()) {',
        "            return \$resource->with('guestCard', true);",
        '        }',
        '        return $resource->with(\'user\', $this->auth->getUser());',
        '    }',
        '}',
    ];

    private const PAYLOAD = 'App\Modules\Guard\Application\Payload\Request\ProfilePayload';

    #[Test]
    public function the_guard_profile_page_is_reported_at_its_first_identity_read(): void
    {
        $findings = $this->detect(new AsPublicPayload(path: '/guard/profile', methods: ['GET']));

        self::assertCount(1, $findings, 'one finding per handler, not one per read');
        self::assertSame('authorization.protected-page', $findings[0]->capabilityId);
        self::assertSame(12, $findings[0]->line);
        self::assertStringContainsString('ProfilePayload (/guard/profile) reads isGuest()', $findings[0]->evidence);
    }

    #[Test]
    public function a_public_page_that_says_why_is_left_alone(): void
    {
        self::assertSame([], $this->detect(new AsPublicPayload(path: '/sign-in', reason: 'sign-in page: greets a returning visitor')));
    }

    #[Test]
    public function an_empty_reason_is_no_reason(): void
    {
        self::assertCount(1, $this->detect(new AsPublicPayload(path: '/guard/profile', reason: '  ')));
    }

    #[Test]
    public function a_protected_page_reading_its_user_is_the_point_and_is_left_alone(): void
    {
        self::assertSame([], $this->detect(self::protectedRoute()));
    }

    #[Test]
    public function a_payload_with_no_route_attribute_is_left_alone(): void
    {
        self::assertSame([], $this->detect(null));
    }

    #[Test]
    public function a_payload_that_cannot_be_examined_leaves_the_page_not_cleared(): void
    {
        // The lint must not print [OK] for a page it could not read (review, dev#218).
        $findings = (new PublicPageReadsIdentityDetector(static function (string $class): never {
            throw new \RuntimeException('class ' . $class . ' does not autoload');
        }))->detect('ProfileHandler.php', self::PROFILE_HANDLER);

        self::assertCount(1, $findings);
        self::assertSame(12, $findings[0]->line);
        self::assertStringContainsString('ProfilePayload could not be examined (class ' . self::PAYLOAD . ' does not autoload)', $findings[0]->evidence);
        self::assertStringContainsString('not cleared', $findings[0]->evidence);
    }

    #[Test]
    public function a_handler_naming_no_payload_class_is_not_cleared_when_it_reads_identity(): void
    {
        $lines = str_replace('payload: ProfilePayload::class', "payload: 'App\\Profile'", self::PROFILE_HANDLER);
        $findings = $this->detector(new AsPublicPayload(path: '/x'))->detect('ProfileHandler.php', $lines);

        self::assertCount(1, $findings);
        self::assertStringContainsString('its payload could not be examined', $findings[0]->evidence);
    }

    #[Test]
    public function a_positional_payload_a_grouped_import_and_an_array_argument_still_resolve(): void
    {
        $lines = self::PROFILE_HANDLER;
        $lines[3] = 'use App\Modules\Guard\Application\Payload\Request\{ProfilePayload, OtherPayload as Other};';
        $lines[6] = "#[AsPayloadHandler(ProfilePayload::class, ProfileResource::class)]";
        self::assertCount(1, $this->detector(new AsPublicPayload(path: '/guard/profile'))->detect('H.php', $lines), 'positional, grouped import');

        $lines[6] = "#[AsPayloadHandler(tags: ['a', 'b'], payload: ProfilePayload::class, resource: ProfileResource::class)]";
        self::assertCount(1, $this->detector(new AsPublicPayload(path: '/guard/profile'))->detect('H.php', $lines), 'a ] before payload:');
    }

    #[Test]
    public function the_default_reflection_reads_real_payload_classes(): void
    {
        $detector = new PublicPageReadsIdentityDetector();
        $handlerOf = static fn (string $payload): array => str_replace(
            ['use App\Modules\Guard\Application\Payload\Request\ProfilePayload;', 'payload: ProfilePayload::class'],
            ['use Semitexa\Dev\Tests\Fixtures\Mechanism\\' . $payload . ';', 'payload: ' . $payload . '::class'],
            self::PROFILE_HANDLER,
        );

        $public = $detector->detect('H.php', $handlerOf('PublicProfilePayload'));
        self::assertCount(1, $public);
        self::assertStringContainsString('PublicProfilePayload (/guard/profile) reads isGuest()', $public[0]->evidence);
        self::assertSame([], $detector->detect('H.php', $handlerOf('PublicSignInPayload')), 'a stated reason');
        self::assertSame([], $detector->detect('H.php', $handlerOf('RoutelessPayload')), 'no route attribute');
        $missing = $detector->detect('H.php', $handlerOf('NoSuchPayload'));
        self::assertCount(1, $missing);
        self::assertStringContainsString('does not autoload', $missing[0]->evidence);
    }

    #[Test]
    public function a_handler_that_reads_no_identity_is_left_alone(): void
    {
        $lines = array_values(array_filter(
            self::PROFILE_HANDLER,
            static fn (string $l): bool => !str_contains($l, 'isGuest') && !str_contains($l, 'getUser'),
        ));

        self::assertSame([], $this->detector(new AsPublicPayload(path: '/about'))->detect('ProfileHandler.php', $lines));
    }

    #[Test]
    public function a_slot_handler_on_a_public_page_is_not_read(): void
    {
        // A user menu on a public page reads who is signed in by design.
        $lines = str_replace('AsPayloadHandler(payload: ProfilePayload::class, resource: ProfileResource::class)', "AsSlotHandler(page: 'home', slot: 'user-menu')", self::PROFILE_HANDLER);

        self::assertSame([], $this->detector(new AsPublicPayload(path: '/'))->detect('MenuSlotHandler.php', $lines));
    }

    #[Test]
    public function the_payload_is_resolved_through_the_namespace_when_not_imported(): void
    {
        $seen = [];
        $lines = array_values(array_filter(self::PROFILE_HANDLER, static fn (string $l): bool => !str_contains($l, 'ProfilePayload;')));
        (new PublicPageReadsIdentityDetector(static function (string $class) use (&$seen): ?AbstractPayloadRoute {
            $seen[] = $class;

            return null;
        }))->detect('ProfileHandler.php', $lines);

        self::assertSame(['App\Modules\Guard\Application\Handler\PayloadHandler\ProfilePayload'], $seen);
    }

    #[Test]
    public function an_identity_word_in_an_import_or_a_comment_is_not_a_read(): void
    {
        $lines = [
            '<?php',
            'namespace App\X;',
            'use App\Y\ProfilePayload;',
            'use Semitexa\Auth\Session\AuthSessionSegment;',
            '#[AsPayloadHandler(payload: ProfilePayload::class)]',
            'final class H {',
            '    // no ->getUser() here: the page is the same for everyone',
            '}',
        ];

        self::assertSame([], (new PublicPageReadsIdentityDetector(static fn (): AbstractPayloadRoute => new AsPublicPayload(path: '/p')))->detect('H.php', $lines));
    }

    /** @return list<\Semitexa\Dev\Application\Service\Ai\Verify\Mechanism\MechanismFinding> */
    private function detect(?AbstractPayloadRoute $route): array
    {
        return $this->detector($route)->detect('src/modules/Guard/ProfileHandler.php', self::PROFILE_HANDLER);
    }

    private function detector(?AbstractPayloadRoute $route): PublicPageReadsIdentityDetector
    {
        return new PublicPageReadsIdentityDetector(static function (string $class) use ($route): ?AbstractPayloadRoute {
            if ($class !== self::PAYLOAD) {
                throw new \RuntimeException('unexpected payload ' . $class);
            }

            return $route;
        });
    }

    private static function protectedRoute(): AbstractPayloadRoute
    {
        return new class (path: '/guard/profile') extends AbstractPayloadRoute {
            public function getAccessType(): PayloadAccessType
            {
                return PayloadAccessType::Protected;
            }
        };
    }
}

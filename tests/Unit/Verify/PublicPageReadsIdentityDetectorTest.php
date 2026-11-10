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
    public function a_payload_that_cannot_be_loaded_gives_no_finding(): void
    {
        self::assertSame([], $this->detect(null));
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
        return new PublicPageReadsIdentityDetector(
            static fn (string $class): ?AbstractPayloadRoute => $class === self::PAYLOAD ? $route : null,
        );
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

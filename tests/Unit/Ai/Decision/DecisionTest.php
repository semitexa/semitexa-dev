<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Decision;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Decision\Decision;
use Semitexa\Dev\Application\Service\Ai\Decision\DecisionScope;
use Semitexa\Dev\Application\Service\Ai\Decision\DecisionStatus;

/** What a decision says now: read off its record at the moment of asking. */
final class DecisionTest extends TestCase
{
    #[Test]
    public function a_decision_is_active_until_its_expiry_its_act_or_its_revocation(): void
    {
        $decision = new Decision('d-a', 'Так, випускай', 'one stable release', 'release', DecisionScope::ONCE, '2026-10-31T19:57:00+00:00', expiresAt: '2026-11-07T00:00:00+00:00');
        $before = new \DateTimeImmutable('2026-11-06T23:59:59+00:00');
        $at = new \DateTimeImmutable('2026-11-07T00:00:00+00:00');

        self::assertSame(DecisionStatus::ACTIVE, $decision->status($before));
        self::assertSame(DecisionStatus::EXPIRED, $decision->status($at), 'expiry is read at the moment of asking, never stored');
        self::assertSame(DecisionStatus::CONSUMED, $decision->consumed('2026-11-01T04:48:00+00:00', 'released 2026.11.01.0448')->status($before));
        self::assertSame(DecisionStatus::REVOKED, $decision->revokedNow()->status($before));
        // Revoked outranks consumed: what the owner took back is what is reported.
        self::assertSame(DecisionStatus::REVOKED, $decision->consumed('2026-11-01T04:48:00+00:00', 'x')->revokedNow()->status($before));
    }

    #[Test]
    public function a_decision_round_trips_and_a_broken_one_is_refused(): void
    {
        $decision = new Decision('d-b', 'Хай буде а', 'core skips bc-gate once, declared', 'release', DecisionScope::STANDING, '2026-11-01T06:50:00+00:00', 'operator');
        self::assertEquals($decision, Decision::fromArray($decision->toFileArray()));
        self::assertSame('active', $decision->toArray(new \DateTimeImmutable('2026-11-02'))['status']);

        $file = $decision->toFileArray();
        $once = (new Decision('d-c', 'w', 'm', 'release', DecisionScope::ONCE, '2026-11-01T06:50:00+00:00'))->toFileArray();
        self::assertTrue(Decision::isInstant('2026-11-01T06:50:00Z'), 'Z is UTC');
        self::assertSame('2026-11-01T06:50:00Z', Decision::fromArray(['decided_at' => '2026-11-01T06:50:00Z'] + $file)->decidedAt);
        foreach ([['decided_at' => '2026-02-30T00:00:00+00:00'] + $file, ['decided_at' => '2026-11-01T06:50:00'] + $file, ['consumed_at' => '2026-11-01T07:00:00+00:00'] + $once, ['consumed_by' => 'a release'] + $once, ['consumed_at' => '2026-11-01T07:00:00+00:00', 'consumed_by' => ' '] + $once, ['consumed_at' => '2026-11-01T07:00:00+00:00', 'consumed_by' => "\u{00A0}"] + $once, ['words' => "\u{2003}"] + $file, ['consumed_at' => '2026-11-01T07:00:00+00:00', 'consumed_by' => 'x'] + $file, ['schema_version' => 'x'] + $file, ['words' => ' '] + $file, ['scope' => 'forever'] + $file, ['revoked' => 'no'] + $file, ['expires_at' => 5] + $file] as $broken) {
            try {
                Decision::fromArray($broken);
                self::fail('accepted ' . json_encode($broken));
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

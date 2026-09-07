<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Semitexa\Dev\Application\Console\Command\LintMechanismsCommand;

/**
 * A finding a project cannot act on must not turn the channel red.
 *
 * `lint:mechanisms` reports application code that hand-rolls something the
 * framework already provides — and it runs inside `ai:verify`. But the catalog
 * of mechanisms spans the whole ecosystem, so a project that deliberately does
 * not install `semitexa/platform-ui` can be told it hand-rolled a UI kit it has
 * no way to use. Failing there is a red gate with no fault behind it, and a
 * gate that cries wolf gets muted — after which the findings that DO matter
 * stop being read too.
 *
 * So: report it, name the package, and do not fail on it. Findings whose
 * capability IS installed still fail, because those are actionable.
 *
 * The one case that must not be mistaken for "not installed" is a catalog that
 * failed to build. An empty catalog then means "nothing could be classified",
 * not "nothing is installed" — downgrading every finding on that basis would
 * silence the whole lint the first time discovery hiccups.
 */
final class MechanismLintUninstalledNoteTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $catalog
     * @return array{actionable: int, notes: int}
     */
    private static function partition(array $findings, ?array $catalog): array
    {
        $m = new ReflectionMethod(LintMechanismsCommand::class, 'partitionByInstalled');
        $m->setAccessible(true);

        /** @var array{actionable: int, notes: int} $result */
        $result = $m->invoke(null, $findings, $catalog);

        return $result;
    }

    #[Test]
    public function a_finding_for_an_uninstalled_capability_is_a_note(): void
    {
        $counts = self::partition(['ui.kit'], ['api.external' => []]);

        self::assertSame(0, $counts['actionable'], 'nothing to act on: the capability is not installed here');
        self::assertSame(1, $counts['notes']);
    }

    #[Test]
    public function a_finding_for_an_installed_capability_still_fails(): void
    {
        $counts = self::partition(['api.external'], ['api.external' => []]);

        self::assertSame(1, $counts['actionable']);
        self::assertSame(0, $counts['notes']);
    }

    #[Test]
    public function an_unavailable_catalog_does_not_silence_every_finding(): void
    {
        // null = the catalog could not be built. Nothing can be classified, so
        // nothing may be downgraded on the strength of that.
        $counts = self::partition(['ui.kit', 'api.external'], null);

        self::assertSame(2, $counts['actionable'], 'an unbuildable catalog must not read as "nothing is installed"');
        self::assertSame(0, $counts['notes']);
    }
}

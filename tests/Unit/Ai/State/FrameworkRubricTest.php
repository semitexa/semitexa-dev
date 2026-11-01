<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\State;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\State\Criterion;
use Semitexa\Dev\Application\Service\Ai\State\CriterionKind;
use Semitexa\Dev\Application\Service\Ai\State\CriterionScore;
use Semitexa\Dev\Application\Service\Ai\State\FrameworkRubric;
use Semitexa\Dev\Application\Service\Ai\State\Phase;
use Semitexa\Dev\Application\Service\Ai\State\Rubric;
use Semitexa\Dev\Application\Service\Ai\State\StateScore;

/**
 * The rubric moved from the make-it skill's markdown into code
 * (tk-state-rubric-in-code). It has to give the numbers the scorecard gave:
 * on 2026-11-01 work 97 · right 78 · fast 33 (var/docs/make-it/scorecard.md).
 */
final class FrameworkRubricTest extends TestCase
{
    /** The points per criterion on the scorecard of 2026-11-01 06:40. */
    private const SCORECARD_2026_11_01 = [
        'work.installs' => 20, 'work.release-tests' => 20, 'work.known-bugs' => 14, 'work.capabilities' => 20, 'work.real-load' => 13, 'work.users-get-fixes' => 10,
        'right.reliability-audits' => 19, 'right.static-analysis' => 10, 'right.architecture' => 8, 'right.ratchets' => 15, 'right.test-quality' => 7, 'right.stability-contract' => 19,
        'fast.hot-paths' => 12, 'fast.audit-rest' => 0, 'fast.concurrency' => 4, 'fast.measured-performance' => 3, 'fast.platform' => 6, 'fast.speed-ratchet' => 8,
    ];

    #[Test]
    public function the_declared_rubric_reproduces_the_scorecard(): void
    {
        $scores = [];
        foreach (self::SCORECARD_2026_11_01 as $id => $points) {
            $scores[] = new CriterionScore($id, $points, 'scorecard 2026-11-01 06:40');
        }

        $phases = StateScore::phases(FrameworkRubric::rubric(), $scores);

        self::assertSame(['work' => 97, 'right' => 78, 'fast' => 33], array_map(static fn (array $p): int => $p['percent'], $phases));
        self::assertSame([[], [], []], array_values(array_map(static fn (array $p): array => $p['unscored'], $phases)));
    }

    #[Test]
    public function the_framework_rubric_keeps_the_weights_it_was_scored_on(): void
    {
        $rubric = FrameworkRubric::rubric();

        self::assertSame(array_keys(self::SCORECARD_2026_11_01), array_map(static fn (Criterion $c): string => $c->id, $rubric->criteria));
        self::assertSame(
            ['work' => [20, 20, 15, 20, 15, 10], 'right' => [20, 20, 15, 15, 10, 20], 'fast' => [25, 10, 20, 25, 10, 10]],
            ['work' => self::weights($rubric, Phase::WORK), 'right' => self::weights($rubric, Phase::RIGHT), 'fast' => self::weights($rubric, Phase::FAST)],
        );
        // Computed where a command or file gives the measurement today; the
        // rest is read by hand until a source exists (performance: no perf.*
        // metric yet).
        self::assertSame(
            ['work.installs', 'work.release-tests', 'work.known-bugs', 'work.users-get-fixes', 'right.reliability-audits', 'right.static-analysis', 'right.test-quality', 'fast.audit-rest', 'fast.speed-ratchet'],
            array_values(array_map(static fn (Criterion $c): string => $c->id, array_filter($rubric->criteria, static fn (Criterion $c): bool => $c->kind === CriterionKind::COMPUTED))),
        );
    }

    /** The code is the rubric now, and the skill's tables must still say the same. */
    #[Test]
    public function the_skill_s_tables_say_what_the_code_declares(): void
    {
        $markdown = file_get_contents(dirname(__DIR__, 4) . '/resources/skills/make-it/references/scorecard.md');
        self::assertIsString($markdown);
        preg_match_all('/^\| ([^|]+?) \| (\d+) \| (.+?) \|$/m', $markdown, $rows, PREG_SET_ORDER);

        self::assertSame(
            array_map(static fn (array $r): array => [$r[1], (int) $r[2], $r[3]], $rows),
            array_map(static fn (Criterion $c): array => [$c->title, $c->weight, $c->evidence], FrameworkRubric::rubric()->criteria),
        );
    }

    #[Test]
    public function an_unscored_criterion_counts_nothing_and_is_named(): void
    {
        $phases = StateScore::phases(FrameworkRubric::rubric(), [new CriterionScore('fast.hot-paths', 25, 'ranked list')]);

        self::assertSame(['percent' => 25, 'points' => 25, 'weight' => 100, 'unscored' => ['fast.audit-rest', 'fast.concurrency', 'fast.measured-performance', 'fast.platform', 'fast.speed-ratchet']], $phases['fast']);
        self::assertNull($phases['work']['percent'], 'nothing scored is no number, not a measured 0');
        self::assertSame(0, StateScore::phases(FrameworkRubric::rubric(), [new CriterionScore('fast.audit-rest', 0, 'nothing closed')])['fast']['percent'], 'a measured 0 is 0');
    }

    #[Test]
    public function a_score_or_a_rubric_that_does_not_hold_is_refused(): void
    {
        $refused = [
            static fn () => StateScore::phases(FrameworkRubric::rubric(), [new CriterionScore('work.nope', 1, 'e')]),
            static fn () => StateScore::phases(FrameworkRubric::rubric(), [new CriterionScore('work.installs', 21, 'e')]),
            static fn () => StateScore::phases(FrameworkRubric::rubric(), [new CriterionScore('work.installs', -1, 'e')]),
            static fn () => StateScore::phases(FrameworkRubric::rubric(), [new CriterionScore('work.installs', 20, ' ')]),
            static fn () => StateScore::phases(FrameworkRubric::rubric(), [new CriterionScore('work.installs', 20, 'e'), new CriterionScore('work.installs', 19, 'e')]),
            static fn () => new Rubric([new Criterion('work.a', Phase::WORK, 'A', 60, CriterionKind::JUDGED, 'e'), new Criterion('work.b', Phase::WORK, 'B', 30, CriterionKind::JUDGED, 'e')]),
            static fn () => new Rubric([new Criterion('work.a', Phase::WORK, 'A', 50, CriterionKind::JUDGED, 'e'), new Criterion('work.a', Phase::WORK, 'A', 50, CriterionKind::JUDGED, 'e')]),
            static fn () => new Criterion('right.a', Phase::WORK, 'A', 10, CriterionKind::JUDGED, 'e'),
            static fn () => new Criterion('work.a', Phase::WORK, 'A', 0, CriterionKind::JUDGED, 'e'),
            static fn () => new Criterion('work.a', Phase::WORK, 'A', 10, CriterionKind::JUDGED, ' '),
            static fn () => new Criterion("work.a\n", Phase::WORK, 'A', 10, CriterionKind::JUDGED, 'e'),
            static fn () => Phase::forCriterion('slow.a'),
        ];
        foreach ($refused as $i => $build) {
            try {
                $build();
                self::fail("case {$i} was accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return list<int> */
    private static function weights(Rubric $rubric, Phase $phase): array
    {
        return array_map(static fn (Criterion $c): int => $c->weight, $rubric->forPhase($phase));
    }
}

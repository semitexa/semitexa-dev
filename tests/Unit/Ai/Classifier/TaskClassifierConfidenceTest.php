<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Classifier;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Classifier\ClassificationResult;
use Semitexa\Dev\Application\Service\Ai\Classifier\TaskClassifier;

/**
 * ai:task is pipeline step [1]; every agent hits it. The classifier must label
 * each result with an honest confidence band so the doctrine's "confidence<bar
 * → clarify" gate (AGENTS.md §2[1]/§3) is enforced by the tool, not left for
 * each agent to re-derive from the raw score. high only when score ≥ 8 with a
 * clear lead; weak/ambiguous matches are low (not confidently wrong); gibberish
 * is none.
 */
final class TaskClassifierConfidenceTest extends TestCase
{
    private TaskClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new TaskClassifier();
    }

    #[Test]
    public function a_strong_unambiguous_match_is_high(): void
    {
        $result = $this->classifier->classify('add a new CLI command that imports users');

        self::assertSame('add_cli_command', $result->recipe->id);
        self::assertGreaterThanOrEqual(8, $result->score);
        self::assertSame(ClassificationResult::CONFIDENCE_HIGH, $result->confidence);
    }

    #[Test]
    public function a_weak_match_above_the_floor_is_low_not_confidently_wrong(): void
    {
        // "fix a bug in the auth middleware" historically scored fix_template_text:3 —
        // above the noise floor, so it was returned as an authoritative recipe.
        $result = $this->classifier->classify('fix a bug in the auth middleware');

        self::assertGreaterThan(0, $result->score);
        self::assertLessThan(8, $result->score);
        self::assertSame(ClassificationResult::CONFIDENCE_LOW, $result->confidence);
        self::assertStringContainsString('low confidence', $result->reason);
    }

    #[Test]
    public function gibberish_is_none_and_falls_back_to_unknown(): void
    {
        $result = $this->classifier->classify('asdf qwer zxcv');

        self::assertSame('unknown_task', $result->recipe->id);
        self::assertSame(0, $result->score);
        self::assertSame(ClassificationResult::CONFIDENCE_NONE, $result->confidence);
    }

    #[Test]
    public function a_high_score_tied_within_two_points_is_demoted_to_low(): void
    {
        // The §3 inline bar requires "no alternative within 2 points": a tie must
        // not read as high even when the top score clears 8.
        $tied = $this->classifier->classify('add update create payload handler service contract');

        if ($tied->alternatives !== [] && ($tied->score - $tied->alternatives[0]['score']) < 3) {
            self::assertSame(ClassificationResult::CONFIDENCE_LOW, $tied->confidence);
        } else {
            $this->markTestSkipped('Could not construct a tie-within-2 case from the live recipe registry.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Phpstan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\AcceptedViolations;

/**
 * The registry exists so the gate and the repository-wide ratchet cannot
 * disagree about what has been accepted. These tests are about the properties
 * that keep it honest: every entry carries a reason, an allowance is bounded,
 * and nothing is accepted by accident.
 */
final class AcceptedViolationsTest extends TestCase
{
    #[Test]
    public function every_entry_states_a_reason_and_a_bounded_count(): void
    {
        foreach (AcceptedViolations::all() as $path => $rules) {
            self::assertNotSame('', trim($path));
            self::assertNotSame([], $rules, "{$path} has no rules");

            foreach ($rules as $rule => $entry) {
                self::assertNotSame('', trim($rule));
                self::assertGreaterThan(0, $entry['diagnostics'], "{$path}/{$rule} accepts no analyser report, so the entry is dead");
                self::assertGreaterThanOrEqual(
                    $entry['diagnostics'],
                    $entry['source_occurrences'],
                    "{$path}/{$rule}: the textual scan cannot see fewer than the analyser reports"
                );
                self::assertGreaterThan(
                    40,
                    strlen(trim($entry['reason'])),
                    "{$path}/{$rule} has no real reason; an entry without one is a baseline in disguise"
                );
            }
        }
    }

    #[Test]
    public function an_unlisted_file_is_not_accepted(): void
    {
        self::assertSame(0, AcceptedViolations::allowanceFor('packages/whatever/src/New.php', 'semitexa.staticContainerAccess'));
        self::assertNull(AcceptedViolations::reasonFor('packages/whatever/src/New.php', 'semitexa.staticContainerAccess'));
    }

    #[Test]
    public function an_accepted_file_is_not_accepted_for_a_different_rule(): void
    {
        $path = 'packages/semitexa-dev/src/Application/Console/Command/AiInvokeCommand.php';

        self::assertGreaterThan(0, AcceptedViolations::allowanceFor($path, 'semitexa.staticContainerAccess'));
        self::assertSame(
            0,
            AcceptedViolations::allowanceFor($path, 'semitexa.injectionViaConstructor'),
            'accepting one rule in a file must not excuse every other rule in it'
        );
    }

    #[Test]
    public function the_per_rule_view_is_sorted_and_complete(): void
    {
        $counts = AcceptedViolations::countsForRule('semitexa.staticContainerAccess');

        self::assertNotSame([], $counts);
        $sorted = $counts;
        ksort($sorted);
        self::assertSame($sorted, $counts, 'the ratchet compares this with === , so ordering is part of the contract');

        foreach ($counts as $path => $count) {
            self::assertIsInt($count);
            // Textual matches, so never fewer than the analyser reports the
            // gate accepts — and sometimes more, where the pattern is in a
            // comment as well.
            self::assertGreaterThanOrEqual(
                AcceptedViolations::allowanceFor($path, 'semitexa.staticContainerAccess'),
                $count,
            );
        }
    }

    #[Test]
    public function the_gate_allowance_never_exceeds_what_was_actually_measured(): void
    {
        // The two numbers measure different things: the analyser reports code,
        // the ratchet scans text and also sees comments. Letting the gate use
        // the larger one would accept a genuinely new violation in that file.
        foreach (AcceptedViolations::all() as $path => $rules) {
            foreach ($rules as $rule => $entry) {
                self::assertSame(
                    $entry['diagnostics'],
                    AcceptedViolations::allowanceFor($path, $rule),
                    "{$path}/{$rule}: the gate must allow analyser reports, not textual matches"
                );
            }
        }

        $textual = AcceptedViolations::countsForRule('semitexa.staticContainerAccess');
        $invoker = 'packages/semitexa-graphql/src/Application/Service/Runtime/ContainerHandlerInvoker.php';
        self::assertSame(2, $textual[$invoker], 'the scan sees the docblock mention too');
        self::assertSame(1, AcceptedViolations::allowanceFor($invoker, 'semitexa.staticContainerAccess'));
    }

    #[Test]
    public function the_same_file_is_accepted_in_either_layout(): void
    {
        // packages/ here, vendor/semitexa/ in a consumer install — and in this
        // workspace both spellings reach the same file through a symlink.
        $rule = 'semitexa.staticContainerAccess';
        $inWorkspace = 'packages/semitexa-dev/src/Application/Console/Command/AiInvokeCommand.php';
        $inConsumer  = 'vendor/semitexa/dev/src/Application/Console/Command/AiInvokeCommand.php';

        self::assertSame(
            AcceptedViolations::allowanceFor($inWorkspace, $rule),
            AcceptedViolations::allowanceFor($inConsumer, $rule),
            'a decision about a file cannot depend on which layout it was addressed through'
        );
        self::assertSame(
            AcceptedViolations::reasonFor($inWorkspace, $rule),
            AcceptedViolations::reasonFor($inConsumer, $rule),
        );
    }

    #[Test]
    public function canonicalisation_handles_hyphenated_packages_and_leaves_everything_else_alone(): void
    {
        self::assertSame(
            'packages/semitexa-platform-ui/src/X.php',
            AcceptedViolations::canonicalise('vendor/semitexa/platform-ui/src/X.php'),
        );
        self::assertSame(
            'packages/semitexa-dev/src/X.php',
            AcceptedViolations::canonicalise('/packages/semitexa-dev/src/X.php'),
            'a leading slash is not a different file'
        );

        foreach ([
            'src/modules/Demo/X.php',
            'vendor/other/pkg/src/X.php',
            'vendor/semitexa',
            'vendor/semitexa/',
        ] as $untouched) {
            self::assertSame(
                ltrim($untouched, '/'),
                AcceptedViolations::canonicalise($untouched),
                "canonicalise must not invent a package out of '{$untouched}'"
            );
        }
    }

    #[Test]
    public function a_rule_nobody_accepted_yields_nothing(): void
    {
        self::assertSame([], AcceptedViolations::countsForRule('semitexa.noSuchRule'));
    }

    #[Test]
    public function every_accepted_path_still_exists(): void
    {
        // An entry pointing at a deleted file accepts nothing and hides the
        // fact that the decision no longer applies to anything.
        $root = dirname(__DIR__, 7);

        foreach (array_keys(AcceptedViolations::all()) as $path) {
            self::assertFileExists($root . '/' . $path, "accepted path no longer exists: {$path}");
        }
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\TestIntegrity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity\TestChangeFinding;
use Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity\TestChangeScan;
use Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity\VacuousAssertions;

/**
 * An added assertion an empty value passes is a finding (tk-verify-catches-vacuous-assertions).
 * The shapes are the ones CodeRabbit found on 2026-11-12, in dev#240, #243, #246, core#229 and
 * ultimate#144, as they stood when it reviewed them.
 */
final class VacuousAssertionsTest extends TestCase
{
    private const FOUND = <<<'PHP'
        <?php
        final class FoundTest extends TestCase
        {
            public function unread_file(): void
            {
                $manual = (string) file_get_contents(self::ROOT . '/AGENTS.md');
                $doctrine = (string) file_get_contents(self::ROOT . '/AGENTS_DOCTRINE.md');
                self::assertStringContainsString('one check', $manual);
                self::assertDoesNotMatchRegularExpression('/lint/', $doctrine);
            }

            public function failed_encode(): void
            {
                $json = (string) json_encode($brief);
                self::assertLessThan(6000, strlen($json));
                self::assertCount(8, $brief['tasks']);
            }

            public function empty_loop(): void
            {
                foreach ($tasks as $task) {
                    self::assertArrayNotHasKey('next_step', $task);
                }
            }

            public function missing_record(): void
            {
                self::assertStringNotContainsString('ai:epic', (string) RecipeRegistry::find('unknown_task')?->summary);
            }

            public function emptied_constant(): void
            {
                self::assertSame(['x' => PushHook::FAILED], $hook->install(['x' => $repo]));
                self::assertNotContains(PushHook::FAILED, PushHook::GATED);
            }

            public function nothing_collected(): void
            {
                $meta = $this->collect($diagnostics);
                self::assertArrayNotHasKey(RefusedRoutePayload::class, $meta);
                self::assertCount(1, $diagnostics->getWarnings());
            }
        }
        PHP;

    /** The same tests after their review answers, and comparisons two empty values fail. */
    private const SHOWN = <<<'PHP'
        <?php
        final class ShownTest extends TestCase
        {
            public function unread_file(): void
            {
                $doctrine = (string) file_get_contents(self::ROOT . '/AGENTS_DOCTRINE.md');
                self::assertNotSame('', $doctrine);
                self::assertDoesNotMatchRegularExpression('/lint/', $doctrine);
            }

            public function failed_encode(): void
            {
                $json = (string) json_encode($brief);
                self::assertJson($json);
                self::assertLessThan(6000, strlen($json));
            }

            public function the_record_is_there(): void
            {
                $recipe = RecipeRegistry::find('unknown_task');
                self::assertNotNull($recipe);
                self::assertStringContainsString('ai:ask', $recipe->summary);
                self::assertStringNotContainsString('ai:epic', $recipe->summary);
            }

            public function the_constant_is_pinned(): void
            {
                self::assertSame([PushHook::WRITTEN], PushHook::GATED);
                self::assertNotContains(PushHook::FAILED, PushHook::GATED);
            }

            public function two_computed_values(): void
            {
                self::assertNotSame($generator->next(), $generator->next());
                self::assertLessThan($priority($later), $priority($earlier));
                self::assertStringNotContainsString('x', 'a literal');
            }
        }
        PHP;

    #[Test]
    public function an_absence_check_on_a_value_nothing_shows_is_found(): void
    {
        self::assertSame([
            ['unread_file', 9, 'assertDoesNotMatchRegularExpression($doctrine)'],
            ['failed_encode', 15, 'assertLessThan(strlen($json))'],
            ['empty_loop', 22, 'assertArrayNotHasKey($task)'],
            ['missing_record', 28, "assertStringNotContainsString((string)RecipeRegistry::find('unknown_task')?->summary)"],
            ['emptied_constant', 34, 'assertNotContains(PushHook::GATED)'],
            ['nothing_collected', 40, 'assertArrayNotHasKey($meta)'],
        ], self::summary(VacuousAssertions::in('tests/FoundTest.php', self::FOUND)));
    }

    /** Review, dev#247: assertions an empty value passes too show nothing. */
    private const NOT_SHOWN = <<<'PHP'
        <?php
        final class NotShownTest extends TestCase
        {
            public function typed_only(): void
            {
                $text = (string) file_get_contents($path);
                self::assertIsString($text);
                self::assertNotNull($text);
                self::assertStringNotContainsString('secret', $text);
            }

            public function pinned_empty(): void
            {
                self::assertCount(0, $items);
                self::assertArrayNotHasKey(0, $items);
                self::assertNotContains('', $items);
            }

            public function zero_is_not_more_than_zero(): void
            {
                self::assertLessThanOrEqual(count($expected), count($actual));
            }
        }
        PHP;

    #[Test]
    public function a_type_check_an_empty_key_and_an_empty_count_show_nothing(): void
    {
        self::assertSame([
            ['typed_only', 9, 'assertStringNotContainsString($text)'],
            ['pinned_empty', 15, 'assertArrayNotHasKey($items)'],
            ['pinned_empty', 16, 'assertNotContains($items)'],
            ['zero_is_not_more_than_zero', 21, 'assertLessThanOrEqual(count($actual))'],
        ], self::summary(VacuousAssertions::in('tests/NotShownTest.php', self::NOT_SHOWN)));
    }

    /** Fable's review of dev#247: loops, null and false, sizes, strings that hold brackets. */
    private const LOOPS_AND_BOUNDS = <<<'PHP'
        <?php
        final class LoopTest extends TestCase
        {
            public function a_shown_list_does_not_show_its_rows(): void
            {
                self::assertNotEmpty($rows);
                foreach ($rows as $row) {
                    self::assertArrayNotHasKey('secret', $row);
                }
            }

            public function loop_over_a_call(): void
            {
                foreach ($this->rows() as $row) {
                    self::assertSame('a', $row['name']);
                    self::assertArrayNotHasKey('secret', $row);
                }
            }

            public function not_false_is_not_not_empty(): void
            {
                self::assertNotSame(false, $json);
                self::assertLessThan(6000, strlen((string) $json));
            }

            public function only_instances_of_nothing(): void
            {
                self::assertContainsOnlyInstancesOf(Foo::class, $items);
                self::assertGreaterThanOrEqual(0, count($items));
                self::assertNotContains('x', $items);
            }

            public function a_duration_may_be_zero(): void
            {
                self::assertLessThan(0.5, microtime(true) - $start);
                self::assertLessThanOrEqual(3, $attempts);
            }

            public function brackets_in_strings(): void
            {
                $body = "{\"id\":$id}";
                self::assertStringNotContainsString("$a)", $out);
                self::assertSame('x', $out);
            }

            public function a_wrapper_keeps_its_first_argument(): void
            {
                self::assertStringNotContainsString('x', trim($out, "\n"));
            }
        }
        PHP;

    #[Test]
    public function a_loop_needs_its_list_shown_and_an_upper_bound_needs_a_size(): void
    {
        self::assertSame([
            // CodeRabbit on dev#247, over Fable's reading: assertNotEmpty($rows) passes on [[]].
            ['a_shown_list_does_not_show_its_rows', 8, 'assertArrayNotHasKey($row)'],
            ['loop_over_a_call', 16, 'assertArrayNotHasKey($row)'],
            ['not_false_is_not_not_empty', 23, 'assertLessThan(strlen((string)$json))'],
            ['only_instances_of_nothing', 30, 'assertNotContains($items)'],
            ['a_wrapper_keeps_its_first_argument', 48, 'assertStringNotContainsString(trim($out,"\n"))'],
        ], self::summary(VacuousAssertions::in('tests/LoopTest.php', self::LOOPS_AND_BOUNDS)));
    }

    /** CodeRabbit on dev#247, third round: evidence that may be skipped, an empty literal list, another property. */
    private const SKIPPED_OR_ELSEWHERE = <<<'PHP'
        <?php
        final class ElsewhereTest extends TestCase
        {
            public function guarded_evidence(): void
            {
                if ($text !== '') {
                    self::assertNotEmpty($text);
                }
                self::assertStringNotContainsString('secret', $text);
            }

            public function empty_literal_list(): void
            {
                $rows = [];
                foreach ($rows as $row) {
                    self::assertSame('a', $row['name']);
                    self::assertArrayNotHasKey('secret', $row);
                }
            }

            public function another_property(): void
            {
                self::assertSame('id', $record->id);
                self::assertStringNotContainsString('secret', $record->summary);
            }

            public function closure_evidence(): void
            {
                $check = function () use ($out) { self::assertNotSame('', $out); };
                self::assertStringNotContainsString('x', $out);
            }

            public function the_same_property_a_part_and_the_whole(): void
            {
                self::assertStringContainsString('ok', $record->summary);
                self::assertStringNotContainsString('secret', $record->summary);
                self::assertTrue($tasks[0]['suggested']);
                self::assertArrayNotHasKey('stale', $tasks[0]);
                self::assertSame(['a' => 1], $config);
                self::assertArrayNotHasKey('b', $config['nested']);
                $cases = ['a', 'b'];
                foreach ($cases as $case) {
                    self::assertNotSame('', $case);
                    self::assertStringNotContainsString('x', $case);
                }
            }
        }
        PHP;

    #[Test]
    public function evidence_that_may_be_skipped_or_is_about_another_part_shows_nothing(): void
    {
        self::assertSame([
            ['guarded_evidence', 9, 'assertStringNotContainsString($text)'],
            ['empty_literal_list', 17, 'assertArrayNotHasKey($row)'],
            ['another_property', 24, 'assertStringNotContainsString($record->summary)'],
            ['closure_evidence', 30, 'assertStringNotContainsString($out)'],
        ], self::summary(VacuousAssertions::in('tests/ElsewhereTest.php', self::SKIPPED_OR_ELSEWHERE)));
    }

    #[Test]
    public function a_new_line_like_a_later_unchanged_one_is_the_added_one(): void
    {
        $before = "a\nb\nx\nc\n";
        $after = "a\nx\nb\nx\nc\n";

        self::assertSame([2], TestChangeScan::addedLineNumbers($before, $after));
        self::assertSame([1, 2], TestChangeScan::addedLineNumbers(null, "a\nb"));
    }

    #[Test]
    public function a_value_another_assertion_shows_and_a_comparison_of_computed_values_are_not(): void
    {
        self::assertSame([], VacuousAssertions::in('tests/ShownTest.php', self::SHOWN));
    }

    #[Test]
    public function only_an_assertion_the_change_wrote_is_its_finding(): void
    {
        $before = str_replace("        self::assertNotContains(PushHook::FAILED, PushHook::GATED);\n", '', self::FOUND);
        $added = TestChangeScan::addedLineNumbers($before, self::FOUND);

        self::assertSame(
            [['emptied_constant', 34, 'assertNotContains(PushHook::GATED)']],
            self::summary(VacuousAssertions::in('tests/FoundTest.php', self::FOUND, $added)),
        );
    }

    /** Review, dev#247: a rewritten subject on its own line is an assertion the change wrote. */
    #[Test]
    public function a_multiline_assertion_whose_subject_the_change_rewrote_is_its_finding(): void
    {
        $call = "        self::assertStringNotContainsString(\n            'secret',\n            %s,\n        );\n";
        $template = "<?php\nfinal class LogTest extends TestCase\n{\n    public function log(): void\n    {\n%s    }\n}\n";
        $before = sprintf($template, sprintf($call, "'clean text'"));
        $after = sprintf($template, sprintf($call, '(string) file_get_contents($path)'));

        self::assertSame(
            [['log', 6, 'assertStringNotContainsString((string)file_get_contents($path))']],
            self::summary(VacuousAssertions::in('tests/LogTest.php', $after, TestChangeScan::addedLineNumbers($before, $after))),
        );
        self::assertSame([], VacuousAssertions::in('tests/LogTest.php', $after, TestChangeScan::addedLineNumbers($after, $after)));
    }

    #[Test]
    public function the_lint_reports_it_for_a_new_test_file_and_takes_a_reason(): void
    {
        $scan = static fn (string $source): array => (new TestChangeScan(
            static fn (string $path): ?string => null,
            static fn (string $path): string => $source,
        ))->scan(['tests/FoundTest.php']);

        $found = $scan(self::FOUND);
        self::assertCount(6, $found['findings']);
        self::assertSame(TestChangeFinding::VACUOUS_ASSERTION, $found['findings'][0]->code);
        self::assertStringContainsString('passes when that value is empty or missing', $found['findings'][0]->message);

        $reasoned = $scan(str_replace("<?php\n", "<?php\n// " . TestChangeScan::MARKER . " the fixture is a list of shapes, not a test\n", self::FOUND));
        self::assertSame([], $reasoned['findings']);
        self::assertCount(6, $reasoned['accepted']);
    }

    /**
     * @param list<TestChangeFinding> $findings
     * @return list<array{0: ?string, 1: int, 2: string}>
     */
    private static function summary(array $findings): array
    {
        return array_map(static function (TestChangeFinding $f): array {
            preg_match('/line (\d+): (.+?) passes/', $f->message, $m);

            return [$f->method, (int) ($m[1] ?? 0), $m[2] ?? $f->message];
        }, $findings);
    }
}

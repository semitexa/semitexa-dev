<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Invoke;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Invoke\FieldExpectations;

/**
 * `--expect-field` turns ai:invoke into a question with a yes/no answer.
 *
 * Without it, asserting "the total came back as 3" meant serialising the
 * envelope, decoding it and indexing in — three places to go quietly wrong.
 */
final class FieldExpectationsTest extends TestCase
{
    /** @param list<string> $raw */
    private function check(array $raw, ?array $resource): array
    {
        return FieldExpectations::fromOptions($raw)->check($resource);
    }

    #[Test]
    public function a_matching_field_holds(): void
    {
        $report = $this->check(['total=3'], ['total' => 3]);

        self::assertSame(1, $report['checked']);
        self::assertSame(0, $report['failed']);
        self::assertTrue($report['results'][0]['ok']);
    }

    /**
     * A shell flag carries no types, so the comparison is textual and the rule
     * is one sentence: the value is rendered as the flag would have to spell
     * it. Inventing a type syntax would be a worse trade than saying this.
     */
    #[Test]
    public function the_comparison_is_textual_and_covers_the_scalars(): void
    {
        self::assertSame(0, $this->check(['n=3'], ['n' => 3])['failed'], 'int');
        self::assertSame(0, $this->check(['n=3'], ['n' => '3'])['failed'], 'string');
        self::assertSame(0, $this->check(['f=1.5'], ['f' => 1.5])['failed'], 'float');
        self::assertSame(0, $this->check(['b=true'], ['b' => true])['failed'], 'bool');
        self::assertSame(0, $this->check(['b=false'], ['b' => false])['failed'], 'false is not absence');
        self::assertSame(0, $this->check(['z=null'], ['z' => null])['failed'], 'null is a value');
    }

    #[Test]
    public function a_wrong_value_reports_both_sides(): void
    {
        $result = $this->check(['name=Ada'], ['name' => 'Grace'])['results'][0];

        self::assertFalse($result['ok']);
        self::assertSame('Ada', $result['expected']);
        self::assertSame('Grace', $result['actual']);
    }

    /** A path that is not there is a failure with a reason, not a silent false. */
    #[Test]
    public function an_absent_path_says_so(): void
    {
        $result = $this->check(['nope=1'], ['total' => 3])['results'][0];

        self::assertFalse($result['ok']);
        self::assertNull($result['actual']);
        self::assertStringContainsString('no such path', $result['reason']);
    }

    #[Test]
    public function a_dot_path_walks_into_nested_data(): void
    {
        $resource = ['meta' => ['pagination' => ['total' => 42]]];

        self::assertSame(0, $this->check(['meta.pagination.total=42'], $resource)['failed']);
        self::assertSame(1, $this->check(['meta.pagination.total=41'], $resource)['failed']);
        self::assertSame(1, $this->check(['meta.missing.total=42'], $resource)['failed']);
    }

    /**
     * A non-scalar renders as its type in angle brackets — something no flag
     * value equals by accident, so a caller asserting against a list gets a
     * mismatch that explains itself rather than a lucky pass.
     */
    #[Test]
    public function a_non_scalar_never_matches_and_names_its_type(): void
    {
        $result = $this->check(['items=3'], ['items' => [1, 2, 3]])['results'][0];

        self::assertFalse($result['ok']);
        self::assertSame('<array>', $result['actual']);
    }

    /**
     * The comparison uses the REAL value — otherwise every assertion under a
     * secret-looking key would be compared against a mask and always fail —
     * while the report shows the masked form, because this envelope is printed
     * and pasted like any other.
     */
    #[Test]
    public function a_secret_is_compared_truly_and_reported_masked(): void
    {
        $report = $this->check(['password=hunter2'], ['password' => 'hunter2']);
        $result = $report['results'][0];

        self::assertTrue($result['ok'], 'the comparison must see the real value');
        self::assertNotSame('hunter2', $result['actual'], 'and the report must not carry it');
        self::assertTrue($result['actual_redacted']);
    }

    /**
     * `<array>` was meant to be unspellable, and a caller can spell it — so an
     * assertion against a list passed. Scalar-ness is part of the verdict now,
     * not a property of the label. Raised in review of dev#84.
     */
    #[Test]
    public function spelling_the_type_label_does_not_make_it_match(): void
    {
        foreach ([['items' => [1, 2]], ['items' => new \stdClass()]] as $resource) {
            $result = $this->check(['items=<' . get_debug_type($resource['items']) . '>'], $resource)['results'][0];

            self::assertFalse($result['ok'], 'a type description is not a value');
            self::assertStringContainsString('no --expect-field value can equal', $result['reason']);
        }
    }

    /**
     * Masking only the actual left the caller's own expected text printing the
     * secret verbatim — back in through the door the mask was guarding.
     */
    #[Test]
    public function the_expected_side_is_masked_too(): void
    {
        $result = $this->check(['password=hunter2'], ['password' => 'hunter2'])['results'][0];

        self::assertTrue($result['ok'], 'the comparison still sees the real values');
        self::assertNotSame('hunter2', $result['expected']);
        self::assertNotSame('hunter2', $result['actual']);
        self::assertTrue($result['expected_redacted']);
        self::assertSame([], array_filter(
            [$result['expected'], $result['actual']],
            static fn (string $v): bool => str_contains($v, 'hunter2'),
        ), 'nothing in the report may carry it');
    }

    /** An ordinary field is reported as written, not masked into uselessness. */
    #[Test]
    public function an_ordinary_expected_value_is_left_alone(): void
    {
        $result = $this->check(['title=Ada'], ['title' => 'Ada'])['results'][0];

        self::assertSame('Ada', $result['expected']);
        self::assertArrayNotHasKey('expected_redacted', $result);
    }

    #[Test]
    public function a_resource_that_never_arrived_fails_every_expectation(): void
    {
        $report = $this->check(['a=1', 'b=2'], null);

        self::assertSame(2, $report['failed']);
    }

    #[Test]
    public function an_entry_without_a_value_is_refused_rather_than_guessed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/path=value/');

        FieldExpectations::fromOptions(['justapath']);
    }

    #[Test]
    public function an_empty_value_is_a_legitimate_expectation(): void
    {
        self::assertSame(0, $this->check(['note='], ['note' => ''])['failed']);
        self::assertSame(1, $this->check(['note='], ['note' => 'x'])['failed']);
    }
}

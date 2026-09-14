<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Mechanism\HeredocPromptDetector;

/**
 * Same discipline as the detectors before it: every positive is paired with the
 * silence case it must not swallow. A heredoc is the most overloaded syntax in
 * PHP — SQL, HTML, JSON and help text all live in one — so the cases that stay
 * quiet are the ones that decide whether this rule is worth having.
 */
final class HeredocPromptDetectorTest extends TestCase
{
    /** @param list<string> $lines */
    private static function detect(array $lines, string $file = 'src/modules/Social/src/Application/Service/GroupHarvester.php'): array
    {
        return (new HeredocPromptDetector())->detect($file, $lines);
    }

    #[Test]
    public function a_prompt_constant_holding_a_heredoc_is_reported(): void
    {
        // The measured shape: six services in one consumer kept their prompts
        // exactly like this while the same project used the catalog elsewhere.
        $findings = self::detect([
            'final class GroupHarvester',
            '{',
            '    private const SYSTEM_PROMPT = <<<TXT',
            '    You summarise a chat room.',
            '    TXT;',
        ]);

        self::assertCount(1, $findings);
        self::assertSame('prompt.catalog', $findings[0]->capabilityId);
        self::assertSame(3, $findings[0]->line);
        self::assertStringContainsString('prompt:list', $findings[0]->evidence);
    }

    #[Test]
    public function the_intent_is_taken_from_the_label_when_it_is_not_in_the_name(): void
    {
        // `const SYSTEM = <<<PROMPT` says it in the label. Requiring the word in
        // both places would miss half of them.
        self::assertCount(1, self::detect(['    private const SYSTEM = <<<PROMPT']));
        self::assertCount(1, self::detect(['        $text = <<<PROMPT']));
    }

    #[Test]
    public function named_arguments_and_array_values_are_matched_too(): void
    {
        self::assertCount(1, self::detect(['            system: <<<PROMPT']));
        self::assertCount(1, self::detect(["            'system' => <<<PROMPT"]));
    }

    #[Test]
    public function a_heredoc_that_is_not_a_prompt_is_left_alone(): void
    {
        // The whole precision argument in one test: the syntax is shared, the
        // naming is what carries the intent.
        self::assertSame([], self::detect([
            '        $sql = <<<SQL',
            '        $html = <<<HTML',
            '        $body = <<<JSON',
        ]));
    }

    #[Test]
    public function a_one_line_prompt_string_is_left_alone(): void
    {
        // Not an oversight — `prompt.catalog` says in its own avoidWhen that one
        // short fixed instruction used in one place should stay where it is. A
        // rule that contradicted the capability it recommends would be worse
        // than no rule.
        self::assertSame([], self::detect(["        \$prompt = 'Summarize this.';"]));
    }

    #[Test]
    public function the_word_prompt_in_prose_is_not_a_finding(): void
    {
        self::assertSame([], self::detect([
            '        // the prompt is built from <<<TXT below',
            '        /** Renders the prompt. */',
        ]));
    }

    #[Test]
    public function a_class_declaring_a_catalog_prompt_is_never_reported(): void
    {
        // It IS the mechanism. Firing here would point at the correct solution
        // and call it the mistake — including the legacy system() path, where a
        // heredoc body is the supported migration shape.
        self::assertSame([], self::detect([
            '#[AsPrompt(id: \'social.topic\')]',
            'final class TopicPrompt implements PromptDefinitionInterface',
            '    public function system(): string { return <<<PROMPT',
        ]));
    }

    #[Test]
    public function a_prompt_shaped_fixture_in_a_test_is_left_alone(): void
    {
        // Nothing sends it anywhere; moving it to the catalog would only make
        // the test depend on discovery.
        self::assertSame(
            [],
            self::detect(
                ['    private const SYSTEM_PROMPT = <<<TXT'],
                'src/modules/Social/tests/Unit/GroupHarvesterTest.php',
            ),
        );
    }

    #[Test]
    public function it_reads_php_and_says_so(): void
    {
        // A detector declaring the wrong extension never runs and looks exactly
        // like a passing check.
        self::assertSame(['php'], (new HeredocPromptDetector())->extensions());
    }
}

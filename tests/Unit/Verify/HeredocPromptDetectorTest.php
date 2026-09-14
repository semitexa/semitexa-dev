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
    public function a_prompt_inlined_without_an_assignment_is_reported(): void
    {
        // The two commonest ways a service inlines a prompt, and the two the
        // first version of this rule could not see while its docblock claimed
        // the label alone was enough. It read as a passing check over exactly
        // the code it exists to find.
        self::assertCount(1, self::detect(['        return <<<PROMPT']));
        self::assertCount(1, self::detect(['        $this->llm->complete(<<<PROMPT']));
    }

    #[Test]
    public function a_language_labelled_heredoc_is_not_a_prompt_even_under_a_prompt_ish_name(): void
    {
        // `$sqlPrompt = <<<SQL` is a name collision, not a prompt. The label is
        // believed over the target here — but only in this direction: a label
        // that says PROMPT is believed whatever it is assigned to.
        self::assertSame([], self::detect(['        $sqlPrompt = <<<SQL']));
        self::assertCount(1, self::detect(['        $sqlPrompt = <<<PROMPT']));
    }

    #[Test]
    public function only_an_applied_attribute_exempts_a_file_not_a_passing_mention(): void
    {
        // A substring test exempted a whole file on a `use` import or a docblock
        // {@see}, so a service that merely named the mechanism became
        // permanently invisible to the rule that looks for its absence.
        self::assertCount(1, self::detect([
            'use Semitexa\\Prompt\\Attribute\\AsPrompt;',
            '    private const SYSTEM_PROMPT = <<<TXT',
        ]));
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
    public function a_heredoc_opener_inside_a_comment_is_documentation_not_code(): void
    {
        // Reported on the PR: a service whose comment ends with an example —
        // `// example: $prompt = <<<TXT` — failed verification over text that
        // compiles to nothing. The rule was punishing the person writing the
        // mistake down. Position is tokenized, so code with a trailing comment
        // is still code.
        self::assertSame([], self::detect(['        // example: $prompt = <<<TXT']));
        self::assertSame([], self::detect(['        // return <<<PROMPT']));
        self::assertSame([], self::detect([
            '    /**',
            '     * Usage: $prompt = <<<TXT',
            '     */',
        ]));
        self::assertSame([], self::detect([
            '    /*',
            '     $prompt = <<<TXT',
            '     */',
        ]));
    }

    #[Test]
    public function a_declaration_wrapped_across_lines_still_exempts_the_file(): void
    {
        // Reported on the PR: `class Foo implements` with the interface on the
        // next line is the same declaration, and the per-line test read it as a
        // service hand-rolling the mechanism it actually implements.
        self::assertSame([], self::detect([
            'final class LegacyPrompt implements',
            '    PromptDefinitionInterface',
            '{',
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
    public function a_compound_assignment_keeps_its_target(): void
    {
        // Reported on the PR: `.=` is how prompt text gets appended, and the
        // assignment branch did not accept it — the bare branch then took over,
        // saw a generic label, and the prompt-bearing target was lost.
        self::assertCount(1, self::detect(['        $systemPrompt .= <<<TXT']));
        self::assertCount(1, self::detect(['        $prompt ??= <<<TXT']));
    }

    #[Test]
    public function a_qualified_or_aliased_declaration_still_exempts_the_file(): void
    {
        // Reported on the PR: the same declaration written in full, or imported
        // under an alias, is still the mechanism. A literal short-name test
        // reported a real prompt class as a hand-rolled copy of itself.
        self::assertSame([], self::detect([
            '#[\\Semitexa\\Prompt\\Attribute\\AsPrompt(id: \'x\')]',
            '    private const P = <<<PROMPT',
        ]));
        self::assertSame([], self::detect([
            'use Semitexa\\Prompt\\Attribute\\AsPrompt as CatalogPrompt;',
            '#[CatalogPrompt(id: \'x\')]',
            '    private const P = <<<PROMPT',
        ]));
        self::assertSame([], self::detect([
            'use Semitexa\\Prompt\\Domain\\Contract\\PromptDefinitionInterface as Def;',
            'final class P implements Def',
            '{',
            '    public function system(): string { return <<<PROMPT',
        ]));
    }

    #[Test]
    public function a_tests_directory_is_exempt_even_as_the_scan_root(): void
    {
        // Reported on the PR: `--path=tests` yields names like
        // `tests/Fixtures/PromptFixture.php` — no surrounding slashes, no Test
        // suffix — so the exemption missed exactly the files it exists for.
        self::assertSame(
            [],
            self::detect(['    private const SYSTEM_PROMPT = <<<TXT'], 'tests/Fixtures/PromptFixture.php'),
        );

        // A segment boundary, not a substring: `contests/` is not a test tree.
        self::assertCount(
            1,
            self::detect(['    private const SYSTEM_PROMPT = <<<TXT'], 'contests/Thing.php'),
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

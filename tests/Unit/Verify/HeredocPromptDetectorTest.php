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
 *
 * Every fixture here is REAL PHP, closed heredocs and all, because the rule
 * reads tokens rather than lines. That is the point: each false positive this
 * rule has had came from judging text PHP does not execute.
 */
final class HeredocPromptDetectorTest extends TestCase
{
    private const SERVICE = 'src/modules/Social/src/Application/Service/GroupHarvester.php';
    private const PROMPT_CLASS = 'src/modules/Social/src/Application/Prompt/TopicPrompt.php';

    /**
     * @param list<string> $lines
     * @return list<\Semitexa\Dev\Application\Service\Ai\Verify\Mechanism\MechanismFinding>
     */
    private static function detect(array $lines, string $file = self::SERVICE): array
    {
        return (new HeredocPromptDetector())->detect($file, $lines);
    }

    /**
     * The same, in a file that talks to a model.
     *
     * Appended rather than prepended so line numbers stay stable, and required
     * because the rule now asks for that corroboration before calling any
     * heredoc a model prompt — the word means two things in English, and a CLI
     * confirmation message is the other one.
     *
     * @param list<string> $lines
     * @return list<\Semitexa\Dev\Application\Service\Ai\Verify\Mechanism\MechanismFinding>
     */
    private static function detectInLlmService(array $lines, string $file = self::SERVICE): array
    {
        return self::detect([...$lines, '$answer = $this->llm->complete($body);'], $file);
    }

    #[Test]
    public function a_prompt_constant_holding_a_heredoc_is_reported(): void
    {
        // The measured shape: six services in one consumer kept their prompts
        // exactly like this while the same project used the catalog elsewhere.
        $findings = self::detectInLlmService([
            'final class GroupHarvester',
            '{',
            '    private const SYSTEM_PROMPT = <<<TXT',
            '    You summarise a chat room.',
            '    TXT;',
            '}',
        ]);

        self::assertCount(1, $findings);
        self::assertSame('prompt.catalog', $findings[0]->capabilityId);
        self::assertSame(3, $findings[0]->line);
        self::assertStringContainsString('prompt:list', $findings[0]->evidence);
    }

    #[Test]
    public function a_prompt_inlined_without_an_assignment_is_reported(): void
    {
        // The two commonest ways a service inlines a prompt, and the two an
        // assignment-only matcher could not see while its docblock claimed the
        // label alone was enough.
        self::assertCount(1, self::detectInLlmService([
            'function a(): string { return <<<PROMPT',
            'Write a post.',
            'PROMPT; }',
        ]));
        self::assertCount(1, self::detectInLlmService([
            '$answer = $this->llm->complete(<<<PROMPT',
            'Write a post.',
            'PROMPT);',
        ]));
    }

    #[Test]
    public function a_compound_assignment_keeps_its_target(): void
    {
        // Reported on the PR: `.=` is how prompt text gets appended, and an
        // assignment branch that accepted only `=` lost the target to the bare
        // form, which then saw a generic label and stayed silent.
        self::assertCount(1, self::detectInLlmService(['$systemPrompt .= <<<TXT', 'more', 'TXT;']));
        self::assertCount(1, self::detectInLlmService(['$prompt ??= <<<TXT', 'more', 'TXT;']));
    }

    #[Test]
    public function an_indexed_target_still_yields_its_name(): void
    {
        // Reported on the PR: `$systemPrompts[] = <<<TXT` ends in `]`, so the
        // token before the operator is a bracket and the prompt-bearing name
        // sits before the subscript. With a generic label nothing was reported.
        self::assertCount(1, self::detectInLlmService(['$systemPrompts[] = <<<TXT', 'body', 'TXT;']));
        self::assertCount(1, self::detectInLlmService(["\$prompts['system'] = <<<TXT", 'body', 'TXT;']));
        self::assertCount(1, self::detectInLlmService(["\$this->prompts['system'] = <<<TXT", 'body', 'TXT;']));

        // And the silence case it must not cost: an indexed target that is not
        // a prompt stays quiet.
        self::assertSame([], self::detect(["\$rows['x'] = <<<SQL", 'SELECT 1', 'SQL;']));
    }

    #[Test]
    public function a_heredoc_inside_a_larger_expression_keeps_its_target(): void
    {
        // Reported on the PR: `$systemPrompt = $prefix . <<<TXT` puts a `.`
        // immediately before the opener, and requiring adjacency missed the
        // prompt whenever the label was generic.
        self::assertCount(1, self::detectInLlmService(['$systemPrompt = $prefix . <<<TXT', 'body', 'TXT;']));
        self::assertSame([], self::detect(['$sqlText = $prefix . <<<SQL', 'SELECT 1', 'SQL;']));
    }

    #[Test]
    public function a_target_is_never_borrowed_across_a_statement_boundary(): void
    {
        // The cost the scan must not incur: a bare opener in the NEXT statement
        // would otherwise pick up the previous statement's target.
        self::assertSame([], self::detect([
            '$systemPrompt = 1;',
            'f(<<<TXT',
            'body',
            'TXT);',
        ]));
        self::assertSame([], self::detect([
            'function f() { return <<<TXT',
            'body',
            'TXT; }',
        ]));
    }

    #[Test]
    public function catalog_symbols_are_matched_the_way_php_resolves_them(): void
    {
        // Reported on the PR: PHP resolves class, interface and alias names
        // case-insensitively, so `#[catalogprompt]` is the same declaration and
        // an exact-case test reported the catalog's own implementation as a
        // hand-rolled copy of itself.
        self::assertSame([], self::detect([
            'use Semitexa\\Prompt\\Attribute\\AsPrompt as CatalogPrompt;',
            "#[catalogprompt(id: 'x')]",
            'final class P {',
            '    private const B = <<<PROMPT',
            'body',
            'PROMPT;',
            '}',
        ], self::PROMPT_CLASS));

        self::assertSame([], self::detect([
            'use Semitexa\\Prompt\\Domain\\Contract\\PromptDefinitionInterface;',
            'final class P implements promptdefinitioninterface',
            '{',
            '    public function system(): string { return <<<PROMPT',
            'body',
            'PROMPT; }',
            '}',
        ], self::PROMPT_CLASS));

        // Case-insensitivity must not become a way to exempt the wrong symbol.
        self::assertCount(1, self::detectInLlmService([
            'use Vendor\\Ui\\AsPrompt as CatalogPrompt;',
            '#[catalogprompt]',
            'final class S {',
            '    private const SYSTEM_PROMPT = <<<TXT',
            'body',
            'TXT;',
            '}',
        ]));
    }

    #[Test]
    public function an_unrelated_symbol_with_the_same_short_name_exempts_nothing(): void
    {
        // Reported on the PR: a short name is not an identity.
        // `use Vendor\Ui\AsPrompt as CatalogPrompt;` is a DIFFERENT attribute,
        // and exempting the file for it would hide every real prompt heredoc in
        // that service. Imports carry the namespace precisely for this.
        self::assertCount(1, self::detectInLlmService([
            'use Vendor\\Ui\\AsPrompt as CatalogPrompt;',
            '#[CatalogPrompt]',
            'final class S {',
            '    private const SYSTEM_PROMPT = <<<TXT',
            'body',
            'TXT;',
            '}',
        ]));

        self::assertCount(1, self::detectInLlmService([
            'use Vendor\\Ui\\AsPrompt;',
            '#[AsPrompt]',
            'final class S {',
            '    private const SYSTEM_PROMPT = <<<TXT',
            'body',
            'TXT;',
            '}',
        ]));

        self::assertCount(1, self::detectInLlmService([
            'use Vendor\\Ui\\PromptDefinitionInterface as Def;',
            'final class S implements Def',
            '{',
            '    private const SYSTEM_PROMPT = <<<TXT',
            'body',
            'TXT;',
            '}',
        ]));

        // The real symbol, plainly imported, still exempts.
        self::assertSame([], self::detect([
            'use Semitexa\\Prompt\\Attribute\\AsPrompt;',
            "#[AsPrompt(id: 'x')]",
            'final class P {',
            '    private const B = <<<PROMPT',
            'body',
            'PROMPT;',
            '}',
        ], self::PROMPT_CLASS));
    }

    #[Test]
    public function the_intent_is_taken_from_the_label_when_it_is_not_in_the_name(): void
    {
        // `const SYSTEM = <<<PROMPT` says it in the label. Requiring the word in
        // both places would miss half of them.
        self::assertCount(1, self::detectInLlmService(['const SYSTEM = <<<PROMPT', 'body', 'PROMPT;']));
        self::assertCount(1, self::detectInLlmService(['$text = <<<PROMPT', 'body', 'PROMPT;']));
    }

    #[Test]
    public function named_arguments_and_array_values_are_matched_too(): void
    {
        self::assertCount(1, self::detectInLlmService(['$x = f(system: <<<PROMPT', 'body', 'PROMPT);']));
        self::assertCount(1, self::detectInLlmService(["\$a = ['system' => <<<PROMPT", 'body', 'PROMPT];']));
    }

    #[Test]
    public function a_heredoc_that_is_not_a_prompt_is_left_alone(): void
    {
        // The whole precision argument in one test: the syntax is shared, the
        // naming is what carries the intent.
        self::assertSame([], self::detect(['$sql = <<<SQL', 'SELECT 1', 'SQL;']));
        self::assertSame([], self::detect(['$html = <<<HTML', '<p></p>', 'HTML;']));
        self::assertSame([], self::detect(['$body = <<<JSON', '{}', 'JSON;']));
    }

    #[Test]
    public function a_language_labelled_heredoc_is_not_a_prompt_even_under_a_prompt_ish_name(): void
    {
        // `$sqlPrompt = <<<SQL` is a name collision, not a prompt. The label is
        // believed over the target here — but only in this direction: a label
        // that says PROMPT is believed whatever it is assigned to.
        self::assertSame([], self::detect(['$sqlPrompt = <<<SQL', 'SELECT 1', 'SQL;']));
        self::assertCount(1, self::detectInLlmService(['$sqlPrompt = <<<PROMPT', 'body', 'PROMPT;']));
    }

    #[Test]
    public function a_user_facing_prompt_is_not_a_model_prompt(): void
    {
        // Reported on the PR: "prompt" means two things in English and only one
        // of them is this capability's. A CLI confirmation message failed
        // verification with a recommendation the code had no use for.
        //
        // Proving the text reaches a model needs dataflow this rule does not do,
        // so the corroboration is at file level: does anything here face a model
        // at all? A file that does not gets no findings, whichever way the word
        // appears.
        self::assertSame([], self::detect([
            'final class Confirm {',
            '    private const CONFIRMATION_PROMPT = <<<TXT',
            'Are you sure?',
            'TXT;',
            '}',
        ]));

        self::assertSame([], self::detect([
            'final class Confirm {',
            '    private const MESSAGE = <<<PROMPT',
            'Are you sure?',
            'PROMPT;',
            '}',
        ]));
    }

    #[Test]
    public function the_corroboration_comes_from_code_not_from_a_comment(): void
    {
        // Otherwise a passing mention of an LLM in prose would qualify a file
        // whose code never talks to one — the same mistake as exempting a file
        // for an attribute named only in a docblock, in the other direction.
        self::assertSame([], self::detect([
            '// we may send this to an llm one day',
            'final class S {',
            '    private const CONFIRMATION_PROMPT = <<<TXT',
            'Are you sure?',
            'TXT;',
            '}',
        ]));

        // An import is code, and is enough.
        self::assertCount(1, self::detect([
            'use Semitexa\\Llm\\Domain\\Contract\\LlmClientInterface;',
            'final class S {',
            '    private const SYSTEM_PROMPT = <<<TXT',
            'You summarise a chat room.',
            'TXT;',
            '}',
        ]));
    }

    #[Test]
    public function a_one_line_prompt_string_is_left_alone(): void
    {
        // Not an oversight — `prompt.catalog` says in its own avoidWhen that one
        // short fixed instruction used in one place should stay where it is. A
        // rule that contradicted the capability it recommends would be worse
        // than no rule.
        self::assertSame([], self::detect(["\$prompt = 'Summarize this.';"]));
    }

    #[Test]
    public function a_heredoc_opener_inside_a_comment_is_documentation_not_code(): void
    {
        // Reported on the PR: a service whose comment ends with an example —
        // `// example: $prompt = <<<TXT` — failed verification over text that
        // compiles to nothing. The rule was punishing the person writing the
        // mistake down.
        self::assertSame([], self::detect(['// example: $prompt = <<<TXT']));
        self::assertSame([], self::detect(['// return <<<PROMPT']));
        self::assertSame([], self::detect([
            '/**',
            ' * Usage: $prompt = <<<TXT',
            ' */',
            'const A = 1;',
        ]));
    }

    #[Test]
    public function an_example_inside_another_heredoc_is_string_content_not_a_second_prompt(): void
    {
        // Reported on the PR: CLI help or documentation carrying a PHP example
        // was read as a prompt of its own. PHP tokenizes it as content of the
        // outer string, and so does this rule now.
        self::assertSame([], self::detect([
            '$help = <<<HELP',
            'Inline a prompt like: return <<<PROMPT',
            'HELP;',
        ]));
    }

    #[Test]
    public function an_attribute_named_only_in_a_docblock_does_not_exempt_the_file(): void
    {
        // Reported on the PR, and the costly direction: a migration note
        // mentioning #[AsPrompt] silently exempted an entire service, hiding the
        // real findings in it. T_ATTRIBUTE is emitted only where PHP attaches
        // an attribute, so prose cannot do that any more.
        self::assertCount(1, self::detectInLlmService([
            "/** Migrate this to #[AsPrompt(id: 'social.topic')] one day. */",
            'private const SYSTEM_PROMPT = <<<TXT',
            'You summarise a chat room.',
            'TXT;',
        ]));
    }

    #[Test]
    public function a_class_declaring_a_catalog_prompt_is_never_reported(): void
    {
        // It IS the mechanism. Firing here would point at the correct solution
        // and call it the mistake — including the legacy system() path, where a
        // heredoc body is the supported migration shape.
        self::assertSame([], self::detect([
            "#[AsPrompt(id: 'social.topic')]",
            'final class TopicPrompt implements PromptDefinitionInterface',
            '{',
            '    public function system(): string { return <<<PROMPT',
            'Write a post.',
            'PROMPT; }',
            '}',
        ], self::PROMPT_CLASS));
    }

    #[Test]
    public function a_qualified_or_aliased_declaration_still_exempts_the_file(): void
    {
        // The same declaration written in full, or imported under an alias, is
        // still the mechanism. Reading tokens gets all three forms at once.
        self::assertSame([], self::detect([
            "#[\\Semitexa\\Prompt\\Attribute\\AsPrompt(id: 'x')]",
            'final class P {',
            '    private const B = <<<PROMPT',
            'body',
            'PROMPT;',
            '}',
        ], self::PROMPT_CLASS));

        self::assertSame([], self::detect([
            'use Semitexa\\Prompt\\Attribute\\AsPrompt as CatalogPrompt;',
            "#[CatalogPrompt(id: 'x')]",
            'final class P {',
            '    private const B = <<<PROMPT',
            'body',
            'PROMPT;',
            '}',
        ], self::PROMPT_CLASS));

        self::assertSame([], self::detect([
            'use Semitexa\\Prompt\\Domain\\Contract\\PromptDefinitionInterface as Def;',
            'final class P implements Def',
            '{',
            '    public function system(): string { return <<<PROMPT',
            'body',
            'PROMPT; }',
            '}',
        ], self::PROMPT_CLASS));
    }

    #[Test]
    public function a_grouped_or_comma_separated_import_carries_its_aliases(): void
    {
        // Reported on the PR: one `use` statement can introduce several names,
        // and stopping at the first alias found no alias at all for a grouped
        // import — so a class using it was reported for hand-rolling the
        // mechanism it implements.
        self::assertSame([], self::detect([
            'use Semitexa\\Prompt\\Attribute\\{AsPrompt as CatalogPrompt};',
            "#[CatalogPrompt(id: 'x')]",
            'final class P {',
            '    private const B = <<<PROMPT',
            'body',
            'PROMPT;',
            '}',
        ], self::PROMPT_CLASS));

        self::assertSame([], self::detect([
            'use Semitexa\\Prompt\\Attribute\\{Other, AsPrompt as CatalogPrompt};',
            "#[CatalogPrompt(id: 'x')]",
            'final class P {',
            '    private const B = <<<PROMPT',
            'body',
            'PROMPT;',
            '}',
        ], self::PROMPT_CLASS));

        self::assertSame([], self::detect([
            'use Semitexa\\Prompt\\Domain\\Contract\\{PromptDefinitionInterface as Def};',
            'final class P implements Def',
            '{',
            '    public function system(): string { return <<<PROMPT',
            'body',
            'PROMPT; }',
            '}',
        ], self::PROMPT_CLASS));

        self::assertSame([], self::detect([
            'use Foo\\Bar as Baz, Semitexa\\Prompt\\Attribute\\AsPrompt as CatalogPrompt;',
            "#[CatalogPrompt(id: 'x')]",
            'final class P {',
            '    private const B = <<<PROMPT',
            'body',
            'PROMPT;',
            '}',
        ], self::PROMPT_CLASS));
    }

    #[Test]
    public function every_attribute_in_a_group_is_inspected(): void
    {
        // Reported on the PR: PHP allows several attributes in one `#[...]`, and
        // reading only the first name missed the declaration — reporting a real
        // prompt class for the body it is supposed to have.
        foreach ([
            "#[Other, AsPrompt(id: 'x')]",
            "#[AsPrompt(id: 'x'), Other]",
        ] as $attributes) {
            self::assertSame([], self::detect([
                $attributes,
                'final class P {',
                '    private const B = <<<PROMPT',
                'body',
                'PROMPT;',
                '}',
            ], self::PROMPT_CLASS), $attributes);
        }
    }

    #[Test]
    public function a_name_used_as_an_attribute_argument_exempts_nothing(): void
    {
        // The other side of reading a group: `#[Other(AsPrompt::class)]` mentions
        // the symbol as an ARGUMENT, and no attribute of that kind is applied.
        // Exempting there would hand anyone a way to silence the rule.
        self::assertCount(1, self::detectInLlmService([
            '#[Other(AsPrompt::class)]',
            'final class S {',
            '    private const SYSTEM_PROMPT = <<<TXT',
            'body',
            'TXT;',
            '}',
        ]));
    }

    #[Test]
    public function an_unrelated_alias_exempts_nothing(): void
    {
        // The alias map must map to the SYMBOL, not merely record a name that
        // looks like one — otherwise the exemption becomes a way to silence the
        // rule by importing something else.
        self::assertCount(1, self::detectInLlmService([
            'use Foo\\{Bar as AsPrompt2};',
            'private const SYSTEM_PROMPT = <<<TXT',
            'body',
            'TXT;',
        ]));
    }

    #[Test]
    public function a_declaration_wrapped_across_lines_still_exempts_the_file(): void
    {
        // `class Foo implements` with the interface on the next line is the same
        // declaration; a per-line test read it as a service hand-rolling the
        // mechanism it actually implements.
        self::assertSame([], self::detect([
            'final class LegacyPrompt implements',
            '    PromptDefinitionInterface',
            '{',
            '    public function system(): string { return <<<PROMPT',
            'body',
            'PROMPT; }',
            '}',
        ], self::PROMPT_CLASS));
    }

    #[Test]
    public function a_tests_directory_is_exempt_even_as_the_scan_root(): void
    {
        // `--path=tests` yields names like `tests/Fixtures/PromptFixture.php` —
        // no surrounding slashes, no Test suffix — so a substring test missed
        // exactly the files it exists for.
        self::assertSame(
            [],
            self::detect(['const SYSTEM_PROMPT = <<<TXT', 'body', 'TXT;'], 'tests/Fixtures/PromptFixture.php'),
        );

        // A segment boundary, not a substring: `contests/` is not a test tree.
        self::assertCount(1, self::detectInLlmService(['const SYSTEM_PROMPT = <<<TXT', 'body', 'TXT;'], 'contests/Thing.php'),
        );
    }

    #[Test]
    public function source_that_will_not_tokenize_reports_nothing(): void
    {
        // Fail closed toward silence, not toward noise: judging unparseable text
        // by pattern is how every false positive here was born.
        self::assertSame([], self::detect(['<<<<<< HEAD', '$prompt = <<<TXT']));
    }

    #[Test]
    public function it_reads_php_and_says_so(): void
    {
        // A detector declaring the wrong extension never runs and looks exactly
        // like a passing check.
        self::assertSame(['php'], (new HeredocPromptDetector())->extensions());
    }
}

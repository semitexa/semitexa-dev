<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Recipe;

/**
 * A single canonical task pattern. The classifier maps a task description to
 * one of these; the context packer fills in prior art and conventions for it;
 * the planner reads `default_risk` as a starting estimate before adjusting
 * for actual blast radius.
 *
 * Recipes are intentionally data, not classes — adding a new pattern should
 * be a one-line entry in {@see RecipeRegistry::recipes()}, never a new file.
 */
final readonly class Recipe
{
    /**
     * @param list<string>             $keywords      Words that, when present in a task description, score this recipe up.
     * @param list<string>             $verbs         Action verbs that, when present, score this recipe up (weighted higher than keywords).
     * @param list<string>             $generator_chain Sequence of make:* commands that build this artifact end-to-end.
     * @param list<string>             $context_signals Node types / module-name fragments the context packer should look for.
     * @param array<string, string>    $arg_hints     Per-arg descriptions used by `ai:task` to suggest the make invocation.
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $summary,
        public array $keywords,
        public array $verbs,
        public array $generator_chain,
        public array $context_signals,
        public array $arg_hints,
        public string $default_risk = 'low',
        /**
         * Its `verbs` are the telling nouns (grid, wizard, widget): it builds that
         * thing. So a request to mend one is not for it, and a word of creation
         * counts only once one of those nouns has been named.
         */
        public bool $noun_led = false,
        /**
         * Words that rule this recipe out, however well the rest matches: a task
         * that names a sign-in is not for the recipe that builds an open endpoint
         * (review, dev#240: "a private JSON endpoint that returns health status"
         * scored 18 for the public route and 9 for the authenticated one). A word
         * or a two-word phrase. The score it would have had still counts against
         * the winner's lead, so a near thing is asked about rather than guessed.
         *
         * @var list<string>
         */
        public array $excluded_by = [],
    ) {}
}

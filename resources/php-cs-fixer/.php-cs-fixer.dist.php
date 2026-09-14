<?php

declare(strict_types=1);

/**
 * The style the code ALREADY has, written down — not a style imposed on it.
 *
 * THE ORDER THIS WAS BUILT IN IS THE POINT. The config came first and ran from
 * a throwaway install; a rule stayed only once it was measured to change zero
 * of the 4605 files in packages/ and src/. Adding the dependency first invites
 * a large normalising commit nobody can review, and turns a tool that should
 * CONFIRM the code into one that rewrites it.
 *
 * So this is deliberately not @PSR12 wholesale. PSR-12 as a set disagrees with
 * this codebase in seven places, and each disagreement is a decision someone
 * should make on purpose rather than a default accepted silently. The rules
 * left out are listed at the bottom with the number of files each would touch,
 * measured 2026-09-14, so enabling one later is a deliberate act with a known
 * cost rather than a surprise.
 */

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/packages')
    ->in(__DIR__ . '/src')
    ->name('*.php')
    ->exclude(['vendor', 'var', 'node_modules'])
    // Fixtures state their own shape on purpose — several exist precisely to
    // be malformed.
    ->notPath('#/tests/.*/Fixtures/#')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setFinder($finder)
    ->setRiskyAllowed(false)
    ->setRules([
        // Each of these was run alone and changed 0 files.
        'encoding' => true,
        'full_opening_tag' => true,
        'elseif' => true,
        'line_ending' => true,
        'lowercase_keywords' => true,
        'lowercase_static_reference' => true,
        'constant_case' => true,
        'no_closing_tag' => true,
        'no_empty_statement' => true,
        'no_leading_import_slash' => true,
        'short_scalar_cast' => true,
        'switch_case_semicolon_to_colon' => true,
        'visibility_required' => ['elements' => ['property', 'method', 'const']],

        // NOT declare_strict_types: php-cs-fixer classes it risky, and this
        // config stays non-risky. The declaration is already on every file, so
        // the rule would buy a risky flag for a no-op.

        // LEFT OUT, with what each would cost today (files changed, of 4605):
        //
        //   ordered_imports              787
        //   no_unused_imports            142   <- not style: 142 files import
        //                                        something nothing uses.
        //                                        Recorded as its own task.
        //   blank_line_after_opening_tag  44
        //   no_trailing_whitespace        30
        //   single_blank_line_at_eof      19
        //   single_line_after_imports      9
        //   trailing_comma_in_multiline    4
        //
        // Turning any of them on is a real commit touching real files. That is
        // allowed — it is just not something a linter should do to the tree on
        // the day it arrives.
    ]);

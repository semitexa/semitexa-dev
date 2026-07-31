<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Mechanism\HandRolledDeferredDetector;

/**
 * Both halves of a lint rule matter, and the second is the one usually left
 * untested: it must fire on the real thing, and it must stay silent on
 * everything else. A rule proven only on its positive case is how a channel
 * fills with noise and stops being read.
 *
 * The silence cases below are not hypothetical — each is a pattern that exists
 * in this repository today and must never be reported.
 */
final class HandRolledDeferredDetectorTest extends TestCase
{
    /** @param list<string> $lines */
    private static function detect(array $lines): array
    {
        return (new HandRolledDeferredDetector())->detect('module.js', $lines);
    }

    #[Test]
    public function it_reports_a_region_fetched_and_injected_by_hand(): void
    {
        $findings = self::detect([
            'async function loadPanel() {',
            "  const res = await fetch('/dashboard/panel');",
            '  const html = await res.text();',
            "  document.querySelector('#panel').innerHTML = html;",
            '}',
        ]);

        self::assertCount(1, $findings);
        self::assertSame(2, $findings[0]->line, 'the finding anchors to the fetch, which is the line to change');
        self::assertSame('ssr.deferred', $findings[0]->capabilityId);
    }

    #[Test]
    public function the_evidence_names_both_lines(): void
    {
        // "You hand-rolled this" is unactionable without showing what was seen.
        $findings = self::detect([
            "const res = await fetch('/widget/html');",
            'const html = await res.text();',
            'target.innerHTML = html;',
        ]);

        self::assertStringContainsString('/widget/html', $findings[0]->evidence);
        self::assertStringContainsString('line 1', $findings[0]->evidence);
        self::assertStringContainsString('line 3', $findings[0]->evidence);
    }

    #[Test]
    public function insert_adjacent_html_counts_as_a_markup_write(): void
    {
        $findings = self::detect([
            "const res = await fetch('/list/rows');",
            "container.insertAdjacentHTML('beforeend', await res.text());",
        ]);

        self::assertCount(1, $findings);
    }

    #[Test]
    public function a_third_party_api_call_is_never_reported(): void
    {
        // Not a region the framework could have rendered. Reporting it would be
        // the rule telling someone to replace an external dependency with a
        // template.
        $findings = self::detect([
            "const res = await fetch('https://api.example.com/rates');",
            'box.innerHTML = render(await res.json());',
        ]);

        self::assertSame([], $findings);
    }

    #[Test]
    public function a_protocol_relative_url_is_off_origin_despite_the_slash(): void
    {
        $findings = self::detect([
            "const res = await fetch('//cdn.example.com/fragment');",
            'box.innerHTML = await res.text();',
        ]);

        self::assertSame([], $findings);
    }

    #[Test]
    public function a_fetch_with_no_markup_write_is_not_a_deferred_region(): void
    {
        // The pattern PipelineTest actually uses: fetch a JSON envelope and show
        // it as data. That is a form submission, not a rendered region.
        $findings = self::detect([
            "const res = await fetch('/pipeline/sync', { method: 'POST' });",
            'const data = await res.json();',
            'pane.textContent = JSON.stringify(data, null, 2);',
        ]);

        self::assertSame([], $findings);
    }

    #[Test]
    public function two_unrelated_features_in_one_file_are_not_joined(): void
    {
        // Same file, far apart. Without the proximity window this would be
        // reported as one hand-rolled region, which it is not.
        $lines = array_merge(
            ["const res = await fetch('/a/data');", 'const data = await res.json();'],
            array_fill(0, 40, '// ...'),
            ['legend.innerHTML = staticMarkup;'],
        );

        self::assertSame([], self::detect($lines));
    }

    #[Test]
    public function a_variable_target_is_not_guessed_at(): void
    {
        // Cannot be shown to be same-origin. Silence is correct; guessing is
        // how a rule starts crying wolf.
        $findings = self::detect([
            'const res = await fetch(endpoint);',
            'box.innerHTML = await res.text();',
        ]);

        self::assertSame([], $findings);
    }

    #[Test]
    public function each_hand_rolled_region_in_a_file_is_reported_separately(): void
    {
        $findings = self::detect([
            "const a = await fetch('/one');",
            'x.innerHTML = await a.text();',
            "const b = await fetch('/two');",
            'y.innerHTML = await b.text();',
        ]);

        self::assertCount(2, $findings);
        self::assertSame([1, 3], array_map(static fn ($f): int => $f->line, $findings));
    }
}

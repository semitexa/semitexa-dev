<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\SourceHighlighter;

/**
 * The one invariant that matters: N lines in, N lines out, whatever the tokens
 * do. A colour in the wrong place is cosmetic; a line number off by one sends
 * the developer to the wrong line of their editor.
 */
final class SourceHighlighterTest extends TestCase
{
    #[Test]
    public function line_count_survives_multi_line_tokens(): void
    {
        $lines = [
            '/**',
            ' * Two-line docblock.',
            ' */',
            'public function x(): string',
            '{',
            "    return <<<'TXT'",
            '    heredoc line',
            '    TXT;',
            '}',
        ];

        $out = (new SourceHighlighter())->lines($lines);

        self::assertCount(count($lines), $out);
        self::assertStringContainsString('<span class="c">/**</span>', $out[0]);
        self::assertStringContainsString('<span class="c"> * Two-line docblock.</span>', $out[1], 'each piece of a split token is wrapped on its own');
        self::assertStringContainsString('<span class="k">public</span>', $out[3]);
        self::assertStringContainsString('<span class="k">function</span>', $out[3]);
        self::assertStringContainsString('<span class="i">x</span>', $out[3]);
    }

    #[Test]
    public function html_in_the_source_is_escaped(): void
    {
        $out = (new SourceHighlighter())->lines(['$a = "<b>" . \'</b>\';']);

        self::assertCount(1, $out);
        self::assertStringNotContainsString('<b>', $out[0]);
        self::assertStringContainsString('&lt;b&gt;', $out[0]);
        self::assertStringContainsString('<span class="v">$a</span>', $out[0]);
        self::assertStringContainsString('<span class="s">', $out[0]);
    }

    #[Test]
    public function an_empty_line_stays_an_empty_string(): void
    {
        $out = (new SourceHighlighter())->lines(['$a = 1;', '', '$b = 2;']);

        self::assertSame('', $out[1]);
        self::assertStringContainsString('<span class="n">1</span>', $out[0]);
    }

    #[Test]
    public function no_lines_no_output(): void
    {
        self::assertSame([], (new SourceHighlighter())->lines([]));
    }

    #[Test]
    public function literals_read_as_keywords_not_names(): void
    {
        $out = (new SourceHighlighter())->lines(['return null ?? true;']);

        self::assertStringContainsString('<span class="k">null</span>', $out[0]);
        self::assertStringContainsString('<span class="k">true</span>', $out[0]);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

/**
 * PHP source to per-line HTML, coloured by token class.
 *
 * Server-side on purpose: the trace viewer ships no JavaScript library and no
 * asset pipeline (dev must not depend on ssr), so the only place a highlighter
 * can run is here. `highlight_string()` was the obvious shortcut and was
 * rejected — it emits inline colours from php.ini, cannot be themed against
 * the page's dark/light tokens, and returns one blob that cannot be numbered
 * per line.
 *
 * Every line in is exactly one line out, including lines inside a multi-line
 * comment or heredoc: a token that spans lines is split at each newline and
 * each piece wrapped on its own, so the `<ol>` around the result keeps its
 * numbering honest.
 */
final class SourceHighlighter
{
    /** Token class → CSS class. Anything not listed is emitted unwrapped. */
    private const CLASSES = [
        T_COMMENT => 'c',
        T_DOC_COMMENT => 'c',
        T_CONSTANT_ENCAPSED_STRING => 's',
        T_ENCAPSED_AND_WHITESPACE => 's',
        T_START_HEREDOC => 's',
        T_END_HEREDOC => 's',
        T_VARIABLE => 'v',
        T_LNUMBER => 'n',
        T_DNUMBER => 'n',
        T_STRING => 'i',
        T_NAME_QUALIFIED => 'i',
        T_NAME_FULLY_QUALIFIED => 'i',
        T_NAME_RELATIVE => 'i',
        T_ATTRIBUTE => 'a',
    ];

    /**
     * @param  list<string> $lines raw source lines, no trailing newline
     * @return list<string> one HTML string per input line, escaped
     */
    public function lines(array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $source = implode("\n", $lines);

        try {
            // A slice rarely starts with an open tag; the tokenizer needs one to
            // read PHP rather than inline HTML. It is dropped again below.
            $tokens = \PhpToken::tokenize("<?php\n" . $source);
        } catch (\Throwable) {
            return array_map(fn (string $l): string => $this->e($l), $lines);
        }

        $out = [''];
        $row = 0;
        $first = true;

        foreach ($tokens as $token) {
            if ($first) {
                // T_OPEN_TAG swallows the newline that follows it, so the
                // synthetic first line disappears with the tag.
                $first = false;
                continue;
            }

            $class = $this->classFor($token);
            $pieces = explode("\n", $token->text);
            foreach ($pieces as $i => $piece) {
                if ($i > 0) {
                    $row++;
                    $out[$row] = '';
                }
                if ($piece === '') {
                    continue;
                }
                $out[$row] .= $class === null
                    ? $this->e($piece)
                    : '<span class="' . $class . '">' . $this->e($piece) . '</span>';
            }
        }

        // Defensive: never return a different number of lines than we were given.
        // A tokenizer surprise must misplace a colour, not a line number.
        if (count($out) !== count($lines)) {
            return array_map(fn (string $l): string => $this->e($l), $lines);
        }

        return $out;
    }

    private function classFor(\PhpToken $token): ?string
    {
        if (isset(self::CLASSES[$token->id])) {
            // A bare identifier that is a known constant or type name reads
            // better as a keyword than as a name.
            if ($token->id === T_STRING && in_array(strtolower($token->text), ['true', 'false', 'null', 'self', 'static', 'parent'], true)) {
                return 'k';
            }

            return self::CLASSES[$token->id];
        }

        // Keywords carry no single flag; a named token that is not whitespace,
        // not a name and not literal text is one.
        if ($token->id >= 256 && $token->id !== T_WHITESPACE && $token->id !== T_INLINE_HTML) {
            $name = $token->getTokenName();
            if (is_string($name) && str_starts_with($name, 'T_')) {
                return 'k';
            }
        }

        return null;
    }

    private function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

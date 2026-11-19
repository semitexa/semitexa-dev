<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Shell;

/**
 * Cuts a POSIX sh script into the tokens {@see ShellFallbackScan} parses.
 *
 * It knows what a reader of commands must not mistake for one: a quoted
 * string, a comment, a here-document body, and everything inside `$( … )`,
 * backquotes and `${ … }`, which stays part of the word that holds it. A
 * command substitution runs in a subshell, so nothing in it is the scan's
 * business; it is skipped by its parentheses, with `case` patterns counted so
 * that their `)` does not close it.
 *
 * @internal
 */
final class ShellLexer
{
    /** Words after which the next word is a command name again (inside a command substitution). */
    private const COMMAND_FOLLOWS = ['then', 'do', 'else', 'elif', 'if', 'while', 'until', '{', '!'];

    private int $i = 0;

    private int $line = 1;

    private readonly int $n;

    /** @var list<ShellToken> */
    private array $tokens = [];

    /** @var array<int, string> line => the comment on it, from its `#` */
    private array $comments = [];

    /** @var list<array{delimiter: string, stripTabs: bool}> here-documents whose bodies start after the next newline */
    private array $heredocs = [];

    private function __construct(private readonly string $s)
    {
        $this->n = strlen($s);
    }

    /**
     * @return array{tokens: list<ShellToken>, comments: array<int, string>}
     *
     * @throws \UnexpectedValueException on a quote, substitution or expansion the script never closes
     */
    public static function read(string $source): array
    {
        $lexer = new self($source);
        $lexer->run();
        $lexer->tokens[] = new ShellToken(ShellToken::END, '', $lexer->line);

        return ['tokens' => $lexer->tokens, 'comments' => $lexer->comments];
    }

    private function run(): void
    {
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if ($c === "\n") {
                $this->tokens[] = new ShellToken(ShellToken::NEWLINE, "\n", $this->line);
                $this->i++;
                $this->line++;
                $this->skipHeredocBodies();
                continue;
            }
            if ($c === ' ' || $c === "\t" || $c === "\r") {
                $this->i++;
                continue;
            }
            if ($c === '\\' && $this->at($this->i + 1) === "\n") {
                $this->i += 2;
                $this->line++;
                continue;
            }
            if ($c === '#') {
                $end = strpos($this->s, "\n", $this->i);
                $end = $end === false ? $this->n : $end;
                $this->comments[$this->line] = substr($this->s, $this->i, $end - $this->i);
                $this->i = $end;
                continue;
            }
            if ($this->operator()) {
                continue;
            }
            $line = $this->line;
            $word = $this->word();
            // `2>file`: the digits name a descriptor of the redirection that follows, they are no argument.
            if (ctype_digit($word) && in_array($this->at($this->i), ['<', '>'], true)) {
                continue;
            }
            $this->tokens[] = new ShellToken(ShellToken::WORD, $word, $line);
        }
    }

    private function operator(): bool
    {
        foreach (['&&', '||', ';;', ';', '&', '|', '(', ')'] as $operator) {
            if (substr_compare($this->s, $operator, $this->i, strlen($operator)) === 0) {
                $this->tokens[] = new ShellToken(ShellToken::OPERATOR, $operator, $this->line);
                $this->i += strlen($operator);

                return true;
            }
        }
        foreach (['<<-', '<<', '<&', '<>', '>>', '>&', '>|', '<', '>'] as $redirect) {
            if (substr_compare($this->s, $redirect, $this->i, strlen($redirect)) !== 0) {
                continue;
            }
            $line = $this->line;
            $this->i += strlen($redirect);
            if ($redirect !== '<<' && $redirect !== '<<-') {
                $this->tokens[] = new ShellToken(ShellToken::REDIRECT, $redirect, $line);

                return true;
            }
            while (in_array($this->at($this->i), [' ', "\t"], true)) {
                $this->i++;
            }
            $delimiter = str_replace(["'", '"', '\\'], '', $this->word());
            if ($delimiter === '') {
                throw new \UnexpectedValueException("line {$line}: a here-document with no delimiter");
            }
            $this->heredocs[] = ['delimiter' => $delimiter, 'stripTabs' => $redirect === '<<-'];
            $this->tokens[] = new ShellToken(ShellToken::HEREDOC, $redirect, $line);

            return true;
        }

        return false;
    }

    /** The bodies are data: read up to each delimiter line and emit nothing. */
    private function skipHeredocBodies(): void
    {
        foreach ($this->heredocs as $heredoc) {
            while ($this->i < $this->n) {
                $end = strpos($this->s, "\n", $this->i);
                $text = substr($this->s, $this->i, ($end === false ? $this->n : $end) - $this->i);
                $this->i = $end === false ? $this->n : $end + 1;
                if ($end !== false) {
                    $this->line++;
                }
                if (rtrim($heredoc['stripTabs'] ? ltrim($text, "\t") : $text, "\r") === $heredoc['delimiter']) {
                    break;
                }
            }
        }
        $this->heredocs = [];
    }

    /** One word, with every quoted or substituted part kept inside it. */
    private function word(): string
    {
        $start = $this->i;
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if (strpbrk($c, " \t\r\n;&|()<>") !== false) {
                break;
            }
            match ($c) {
                '\\' => $this->advanceTo($this->i + 2),
                "'" => $this->skipSingle(),
                '"' => $this->skipDouble(),
                '`' => $this->skipBackquotes(),
                '$' => $this->skipDollar(false),
                default => $this->i++,
            };
        }

        return substr($this->s, $start, $this->i - $start);
    }

    private function skipSingle(): void
    {
        $end = strpos($this->s, "'", $this->i + 1);
        if ($end === false) {
            throw new \UnexpectedValueException("line {$this->line}: a single quote is never closed");
        }
        $this->advanceTo($end + 1);
    }

    private function skipDouble(): void
    {
        $opened = $this->line;
        $this->i++;
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if ($c === '"') {
                $this->i++;

                return;
            }
            match ($c) {
                '\\' => $this->advanceTo($this->i + 2),
                '`' => $this->skipBackquotes(),
                '$' => $this->skipDollar(true),
                default => $this->advanceTo($this->i + 1),
            };
        }

        throw new \UnexpectedValueException("line {$opened}: a double quote is never closed");
    }

    private function skipBackquotes(): void
    {
        $opened = $this->line;
        $this->i++;
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if ($c === '`') {
                $this->i++;

                return;
            }
            $this->advanceTo($this->i + ($c === '\\' ? 2 : 1));
        }

        throw new \UnexpectedValueException("line {$opened}: a backquote is never closed");
    }

    /** At a `$`: a command substitution, an arithmetic expansion, a `${ … }`, or just the character. */
    private function skipDollar(bool $inDoubleQuotes): void
    {
        $this->i++;
        if ($this->at($this->i) === '(') {
            $this->skipParentheses();
        } elseif ($this->at($this->i) === '{') {
            $this->skipBraces($inDoubleQuotes);
        }
    }

    /** At the `{` of `${ … }`. Inside double quotes a single quote is a character, not a quote. */
    private function skipBraces(bool $inDoubleQuotes): void
    {
        $opened = $this->line;
        $this->i++;
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if ($c === '}') {
                $this->i++;

                return;
            }
            if ($c === "'" && !$inDoubleQuotes) {
                $this->skipSingle();
                continue;
            }
            match ($c) {
                '\\' => $this->advanceTo($this->i + 2),
                '"' => $this->skipDouble(),
                '`' => $this->skipBackquotes(),
                '$' => $this->skipDollar($inDoubleQuotes),
                default => $this->advanceTo($this->i + 1),
            };
        }

        throw new \UnexpectedValueException("line {$opened}: a \${ is never closed");
    }

    /**
     * At the `(` of `$( … )` or `$(( … ))`: to its matching `)`. A `case`
     * pattern ends in a `)` that opens nothing, so the patterns of each open
     * `case` are counted out; a `#` comment is skipped whole.
     */
    private function skipParentheses(): void
    {
        $opened = $this->line;
        $this->i++;
        $depth = 1;
        /** @var list<int> $cases the depth each open `case` sits at */
        $cases = [];
        $wordStart = true;
        $commandStart = true;
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if ($c === "\n" || $c === ' ' || $c === "\t" || $c === "\r") {
                $commandStart = $commandStart || $c === "\n";
                $wordStart = true;
                $this->advanceTo($this->i + 1);
                continue;
            }
            if ($c === '#' && $wordStart) {
                $end = strpos($this->s, "\n", $this->i);
                $this->i = $end === false ? $this->n : $end;
                continue;
            }
            if ($c === '(') {
                $depth++;
                $this->i++;
                $wordStart = $commandStart = true;
                continue;
            }
            if ($c === ')') {
                $this->i++;
                $wordStart = $commandStart = true;
                if ($cases !== [] && $cases[count($cases) - 1] === $depth) {
                    continue;
                }
                if (--$depth === 0) {
                    return;
                }
                continue;
            }
            if ($c === ';' || $c === '&' || $c === '|') {
                $this->i++;
                $wordStart = $commandStart = true;
                continue;
            }
            if ($wordStart && preg_match('/\G[A-Za-z_!{}][A-Za-z0-9_]*(?![^\s;&|()<>])/', $this->s, $bare, 0, $this->i) === 1) {
                if ($commandStart && $bare[0] === 'case') {
                    $cases[] = $depth;
                } elseif ($commandStart && $bare[0] === 'esac' && $cases !== [] && $cases[count($cases) - 1] === $depth) {
                    array_pop($cases);
                }
                $commandStart = in_array($bare[0], self::COMMAND_FOLLOWS, true);
                $wordStart = false;
                $this->i += strlen($bare[0]);
                continue;
            }
            $wordStart = $commandStart = false;
            match ($c) {
                '\\' => $this->advanceTo($this->i + 2),
                "'" => $this->skipSingle(),
                '"' => $this->skipDouble(),
                '`' => $this->skipBackquotes(),
                '$' => $this->skipDollar(false),
                default => $this->i++,
            };
        }

        throw new \UnexpectedValueException("line {$opened}: a \$( is never closed");
    }

    private function advanceTo(int $to): void
    {
        $to = min($to, $this->n);
        $this->line += substr_count($this->s, "\n", $this->i, $to - $this->i);
        $this->i = $to;
    }

    private function at(int $offset): string
    {
        return $offset < $this->n ? $this->s[$offset] : '';
    }
}

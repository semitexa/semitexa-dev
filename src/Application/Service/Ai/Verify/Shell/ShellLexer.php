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
            $delimiter = self::unquoted($this->word());
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

    /**
     * A word as the shell compares it: quote removal done, expansions left as written.
     * `'E"OF'` is `E"OF`, `E\OF` is `EOF`, `"/dev/null"` is `/dev/null`.
     */
    public static function unquoted(string $word): string
    {
        $out = '';
        $n = strlen($word);
        $i = 0;
        while ($i < $n) {
            $c = $word[$i];
            if ($c === "'") {
                $end = strpos($word, "'", $i + 1);
                $end = $end === false ? $n : $end;
                $out .= substr($word, $i + 1, $end - $i - 1);
                $i = $end + 1;
            } elseif ($c === '"') {
                for ($i++; $i < $n && $word[$i] !== '"'; $i++) {
                    // A backslash-newline joins two lines here as it does outside quotes: neither byte is in the word.
                    if ($word[$i] === '\\' && $i + 1 < $n && $word[$i + 1] === "\n") {
                        $i++;
                        continue;
                    }
                    // Otherwise a backslash inside double quotes escapes only these four.
                    if ($word[$i] === '\\' && $i + 1 < $n && strpbrk($word[$i + 1], '$`"\\') !== false) {
                        $i++;
                    }
                    $out .= $word[$i];
                }
                $i++;
            } elseif ($c === '\\' && $i + 1 < $n) {
                $out .= $word[$i + 1];
                $i += 2;
            } else {
                $out .= $c;
                $i++;
            }
        }

        return $out;
    }

    /**
     * One word, with every quoted or substituted part kept inside it. A
     * backslash-newline outside quotes joins two lines and is no part of the
     * word, so it is left out of the spelling and still counted as a line.
     */
    private function word(): string
    {
        $text = '';
        $start = $this->i;
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if (strpbrk($c, " \t\r\n;&|()<>") !== false) {
                break;
            }
            if ($c === '\\' && $this->at($this->i + 1) === "\n") {
                $text .= substr($this->s, $start, $this->i - $start);
                $this->i += 2;
                $this->line++;
                $start = $this->i;
                continue;
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

        return $text . substr($this->s, $start, $this->i - $start);
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
     * pattern ends in a `)` that opens nothing, so each open `case` is followed
     * through its parts: the keyword, its subject, `in`, a pattern list (`start`
     * until its first character, where `esac` still ends the case; then
     * `pattern`, where every word is a pattern, `case` and `esac` included) and
     * a body. A `#` comment is skipped whole.
     */
    private function skipParentheses(): void
    {
        $opened = $this->line;
        $this->i++;
        $depth = 1;
        /** @var list<array{depth: int, part: string}> $cases each open `case`: the depth it sits at, the part being read */
        $cases = [];
        $wordStart = true;
        $commandStart = true;
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            $last = count($cases) - 1;
            $part = $last >= 0 && $cases[$last]['depth'] === $depth ? $cases[$last]['part'] : '';
            if ($c === "\n" || $c === ' ' || $c === "\t" || $c === "\r") {
                if ($part === 'keyword' || ($part === 'subject' && !$wordStart)) {
                    $cases[$last] = ['depth' => $depth, 'part' => $part === 'keyword' ? 'subject' : 'in'];
                }
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
            if ($c === '(' || $c === ')') {
                $this->i++;
                $wordStart = $commandStart = true;
                if ($part === 'start' || $part === 'pattern') {
                    // The optional `(` before a pattern list, or the `)` that ends it: neither nests.
                    $cases[$last] = ['depth' => $depth, 'part' => $c === '(' ? 'pattern' : 'body'];
                } elseif ($c === '(') {
                    $depth++;
                } elseif (--$depth === 0) {
                    return;
                }
                continue;
            }
            if ($c === ';' || $c === '&' || $c === '|') {
                if ($part === 'body' && $c === ';' && $this->at($this->i + 1) === ';') {
                    $cases[$last] = ['depth' => $depth, 'part' => 'start'];
                    $this->i++;
                }
                $this->i++;
                $wordStart = $commandStart = true;
                continue;
            }
            if ($wordStart && preg_match('/\G[A-Za-z_!{}][A-Za-z0-9_]*(?![^\s;&|()<>])/', $this->s, $bare, 0, $this->i) === 1) {
                if ($part === '' || $part === 'body') {
                    if ($commandStart && $bare[0] === 'case') {
                        $cases[] = ['depth' => $depth, 'part' => 'keyword'];
                    } elseif ($commandStart && $bare[0] === 'esac' && $part === 'body') {
                        array_pop($cases);
                    }
                    $commandStart = in_array($bare[0], self::COMMAND_FOLLOWS, true);
                } elseif ($part === 'start' && $bare[0] === 'esac') {
                    array_pop($cases);
                } elseif ($part === 'in' || $part === 'start') {
                    $cases[$last] = ['depth' => $depth, 'part' => $part === 'in' && $bare[0] === 'in' ? 'start' : ($part === 'start' ? 'pattern' : 'in')];
                }
                $wordStart = false;
                $this->i += strlen($bare[0]);
                continue;
            }
            if ($part === 'start') {
                $cases[$last] = ['depth' => $depth, 'part' => 'pattern'];
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

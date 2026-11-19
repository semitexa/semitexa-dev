<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Shell;

/** One token of a POSIX sh script, as {@see ShellLexer} cuts it. */
final readonly class ShellToken
{
    /** A word as written, quotes and expansions included. */
    public const WORD = 'word';
    /** A control operator: `&&`, `||`, `;`, `;;`, `&`, `|`, `(`, `)`. */
    public const OPERATOR = 'operator';
    /** A redirection operator with its target in the next word: `>`, `>>`, `<`, `>|`, `<>`, `>&`, `<&`. */
    public const REDIRECT = 'redirect';
    /** A here-document, delimiter and body already consumed. */
    public const HEREDOC = 'heredoc';
    public const NEWLINE = 'newline';
    public const END = 'end';

    public function __construct(
        public string $type,
        public string $text,
        public int $line,
    ) {}

    public function isWord(string ...$texts): bool
    {
        return $this->type === self::WORD && in_array($this->text, $texts, true);
    }

    public function isOperator(string ...$texts): bool
    {
        return $this->type === self::OPERATOR && in_array($this->text, $texts, true);
    }
}

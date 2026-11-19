<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Shell;

/**
 * Finds the fallback a POSIX shell never reaches (tk-verify-catches-review-no-classes, class 1a).
 *
 * In POSIX sh (dash, BusyBox ash, the image's /bin/sh) a redirection that
 * fails on a SPECIAL builtin does not fail the command: it exits the shell.
 * So in
 *
 *     if mkdir -p "$d" && : > "$d/x.log" 2>/dev/null; then …; else FALLBACK; fi
 *
 * the else branch is unreachable for the one failure it was written for. Line
 * coverage cannot see it (the line ran), and the script works wherever the
 * file is writable.
 *
 * Flagged: a special builtin (`:`, `exec`, `export`, `readonly`, `set`,
 * `unset`, `eval`, `.`) with a redirection to or from a file, whose status
 * something reads:
 *  - it is in the condition of `if`, `elif`, `while` or `until`;
 *  - it is negated with `!`;
 *  - it is an operand of `&&` or `||` with another operand after it.
 * The LAST operand of an and-or list is judged by where the list stands: in
 * `[ -f x ] || : > x` as a plain statement nothing reads the status of `:`,
 * so the shell exiting is what a failed command does under `set -e` anyway and
 * no handling is skipped; the same list as an `if` condition is flagged.
 * A `{ …; }` group is transparent: its commands stand where the group stands.
 *
 * Not flagged, because the shell that exits there is not the script's:
 * anything inside `( … )`, `$( … )` or backquotes, a member of a pipeline, a
 * list sent to the background. Nor a body (`then`, `else`, `do`, a `case`
 * branch, a function): a plain statement there exits the script too, but no
 * fallback was written for it. `/dev/null` and descriptor copies (`2>&1`,
 * `3>&-`) are no file that can refuse.
 *
 * A function called as a condition is not followed into its body.
 *
 * Accepted with `# verify:accept-shell-fallback <reason>` in a comment on the
 * line of the builtin or the line above it — the convention of
 * {@see \Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity\TestChangeScan::MARKER}.
 */
final class ShellFallbackScan
{
    public const MARKER = 'verify:accept-shell-fallback';

    private const MIN_REASON = 20;

    /** The POSIX special builtins a script redirects in practice. */
    private const SPECIAL_BUILTINS = [':', 'exec', 'export', 'readonly', 'set', 'unset', 'eval', '.'];

    /** Redirections that open a file; `>&` and `<&` copy or close a descriptor. */
    private const FILE_REDIRECTS = ['>', '>>', '<', '>|', '<>'];

    /** Reserved words that end a list when they stand where a command would. */
    private const LIST_ENDS = ['then', 'else', 'elif', 'fi', 'do', 'done', 'esac', '}'];

    /** Where a project keeps the scripts it wrote, relative to its root. */
    private const ROOTS = ['bin', 'scripts', 'tools', 'docker', 'src', 'packages', '.claude/skills', '.codex/skills'];

    /** Directories whose scripts belong to someone else, or to nobody. */
    private const PRUNED = ['vendor', 'node_modules', '.git', 'var'];

    private int $position = 0;

    /** @var list<array{line: int, builtin: string, probe: string, context: string}> */
    private array $flagged = [];

    /** @param list<ShellToken> $tokens */
    private function __construct(private readonly array $tokens) {}

    /** Whether the shebang names a POSIX shell: `#!/bin/sh`, with options or through env. Bash has its own rules. */
    public static function isPosixSh(string $source): bool
    {
        return preg_match('~^#!\s*(?:/usr)?/bin/(?:env\s+)?(?:sh|dash|ash)(?:\s|$)~', substr($source, 0, strcspn($source, "\n"))) === 1;
    }

    /**
     * @return array{
     *     findings: list<array{line: int, builtin: string, probe: string, context: string, shortReason: bool}>,
     *     accepted: list<array{line: int, builtin: string, probe: string, context: string, reason: string}>,
     * }
     *
     * @throws \UnexpectedValueException when the script cannot be parsed: a scan that could not read must not pass
     */
    public static function scan(string $source): array
    {
        $read = ShellLexer::read($source);
        $parser = new self($read['tokens']);
        $parser->list();
        $left = $parser->peek();
        if ($left->type !== ShellToken::END) {
            throw new \UnexpectedValueException("line {$left->line}: unexpected `{$left->text}`");
        }

        $flagged = $parser->flagged;
        usort($flagged, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
        $findings = [];
        $accepted = [];
        foreach ($flagged as $found) {
            $marker = self::marker($read['comments'], $found['line']);
            if (is_string($marker)) {
                $accepted[] = $found + ['reason' => $marker];
                continue;
            }
            $findings[] = $found + ['shortReason' => $marker];
        }

        return ['findings' => $findings, 'accepted' => $accepted];
    }

    /**
     * Every POSIX sh script of the project, or the given ones, with what was found in each.
     *
     * @param list<string> $files       paths relative to the project root; empty: every script under the project's own directories
     * @param bool         $withProject read the project's scripts beside the given ones: a changed script may lie in no directory the walk enters
     * @return array<string, list<string>> script, relative to the project => its problems
     */
    public static function problems(string $projectRoot, array $files = [], bool $withProject = false): array
    {
        $root = rtrim($projectRoot, '/');
        $problems = [];
        $scripts = $files === [] || $withProject ? array_values(array_unique([...$files, ...self::scripts($root)])) : $files;
        foreach ($scripts as $relative) {
            if ($relative === '') {
                // An unset variable in `--file="$script"`: it names nothing, and must not pass as "nothing found".
                $problems['(empty --file)'][] = 'no path was given: the lint checked nothing for it';
                continue;
            }
            $source = @file_get_contents($root . '/' . $relative);
            if ($source === false) {
                $problems[$relative][] = 'cannot be read: the lint did not check it';
                continue;
            }
            if (!self::isPosixSh($source)) {
                continue;
            }
            try {
                $findings = self::scan($source)['findings'];
            } catch (\UnexpectedValueException $e) {
                $problems[$relative][] = "could not be parsed ({$e->getMessage()}): the lint did not check it";
                continue;
            }
            foreach ($findings as $finding) {
                $problems[$relative][] = sprintf(
                    'line %d: `%s` with a file redirection is %s; in POSIX sh a redirection that fails on a special builtin exits the shell, so the fallback never runs. %s%s',
                    $finding['line'],
                    $finding['builtin'],
                    $finding['context'],
                    // The probe repeats the script's own redirection: any other mode would empty or create a file the script did not mean to.
                    $finding['builtin'] === ':'
                        ? "Run it in a subshell: {$finding['probe']}"
                        : "Try the same redirection in a subshell first, and run the builtin only when that worked: {$finding['probe']} && {$finding['builtin']} …",
                    $finding['shortReason'] ? sprintf('. Its `# %s` gives no reason of %d characters', self::MARKER, self::MIN_REASON) : '',
                );
            }
        }
        ksort($problems);

        return $problems;
    }

    /**
     * Candidates: every `*.sh`, and every file without an extension in a `bin` directory. The shebang decides.
     *
     * @return list<string>
     */
    private static function scripts(string $root): array
    {
        $found = [];
        foreach (self::ROOTS as $start) {
            if (!is_dir($root . '/' . $start)) {
                continue;
            }
            $tree = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root . '/' . $start, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $entry): bool => !$entry->isDir() || !in_array($entry->getFilename(), self::PRUNED, true),
            ));
            foreach ($tree as $file) {
                // A link to nothing is no file, and still a script the project names: kept, so reading it fails aloud.
                if (!$file instanceof \SplFileInfo || !($file->isFile() || ($file->isLink() && !file_exists($file->getPathname())))) {
                    continue;
                }
                $name = $file->getFilename();
                if (str_ends_with($name, '.sh') || (!str_contains($name, '.') && basename($file->getPath()) === 'bin')) {
                    $found[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @param array<int, string> $comments
     * @return string|bool the reason; true for a marker whose reason is too short; false for no marker
     */
    private static function marker(array $comments, int $line): string|bool
    {
        $short = false;
        foreach ([$line, $line - 1] as $at) {
            if (preg_match('~^#+\s*' . preg_quote(self::MARKER, '~') . '(?![\w-])(.*)$~u', $comments[$at] ?? '', $match) !== 1) {
                continue;
            }
            $reason = trim($match[1], " \t\r:—-");
            if (mb_strlen($reason) >= self::MIN_REASON) {
                return $reason;
            }
            $short = true;
        }

        return $short;
    }

    /**
     * A list of and-or lists, up to whatever closes it.
     *
     * @return list<array{line: int, builtin: string, probe: string}> the redirected special builtins nothing in the list has judged yet
     */
    private function list(): array
    {
        $open = [];
        while (true) {
            $token = $this->peek();
            if ($token->type === ShellToken::NEWLINE || $token->isOperator(';')) {
                $this->position++;
                continue;
            }
            if ($token->type === ShellToken::END || $token->isOperator(')', ';;') || $token->isWord(...self::LIST_ENDS)) {
                return $open;
            }
            array_push($open, ...$this->andOr());
        }
    }

    /** @return list<array{line: int, builtin: string, probe: string}> */
    private function andOr(): array
    {
        $mark = count($this->flagged);
        $open = $this->pipeline();
        while ($this->peek()->isOperator('&&', '||')) {
            // Another operand follows, so what came before decides whether it runs.
            $this->flag($open, 'an operand of && / || that the rest of the list depends on');
            $this->position++;
            $this->skipNewlines();
            $open = $this->pipeline();
        }
        if ($this->peek()->isOperator('&')) {
            // In the background: the whole list runs in a subshell.
            $this->position++;
            array_splice($this->flagged, $mark);

            return [];
        }

        // The last operand: its status is the list's, so whoever holds the list judges it.
        return $open;
    }

    /** @return list<array{line: int, builtin: string, probe: string}> */
    private function pipeline(): array
    {
        $mark = count($this->flagged);
        $negated = $this->peek()->isWord('!');
        if ($negated) {
            $this->position++;
        }
        $open = $this->command();
        $piped = false;
        while ($this->peek()->isOperator('|')) {
            $piped = true;
            $this->position++;
            $this->skipNewlines();
            $this->command();
        }
        if ($piped) {
            // Each member of a pipeline runs in a subshell.
            array_splice($this->flagged, $mark);

            return [];
        }
        if ($negated) {
            $this->flag($open, 'negated with !');

            return [];
        }

        return $open;
    }

    /** @return list<array{line: int, builtin: string, probe: string}> */
    private function command(): array
    {
        $token = $this->peek();
        if ($token->isOperator('(')) {
            $mark = count($this->flagged);
            $this->position++;
            $this->list();
            $this->expectOperator(')');
            array_splice($this->flagged, $mark);
            $this->redirections();

            return [];
        }
        if ($token->type === ShellToken::WORD) {
            $after = $this->tokens[$this->position + 1] ?? $token;
            $afterThat = $this->tokens[$this->position + 2] ?? $token;
            if ($after->isOperator('(') && $afterThat->isOperator(')')) {
                // name() body: called from anywhere, so its body starts as a plain statement.
                $this->position += 3;
                $this->skipNewlines();
                $this->command();

                return [];
            }
            $open = match ($token->text) {
                'if' => $this->conditional(),
                'while', 'until' => $this->loop($token->text),
                'for' => $this->forLoop(),
                'case' => $this->caseOf(),
                '{' => $this->group(),
                default => null,
            };
            if ($open !== null) {
                $this->redirections();

                return $open;
            }
            if (in_array($token->text, self::LIST_ENDS, true)) {
                throw new \UnexpectedValueException("line {$token->line}: unexpected `{$token->text}`");
            }
        }
        if (in_array($token->type, [ShellToken::WORD, ShellToken::REDIRECT, ShellToken::HEREDOC], true)) {
            return $this->simple();
        }

        throw new \UnexpectedValueException("line {$token->line}: unexpected " . ($token->type === ShellToken::END ? 'end of the script' : "`{$token->text}`"));
    }

    /** @return list<array{line: int, builtin: string, probe: string}> */
    private function simple(): array
    {
        $name = null;
        $line = 0;
        $toFile = [];
        while (true) {
            $token = $this->peek();
            if ($token->type === ShellToken::WORD) {
                $this->position++;
                // Leading NAME=value words are assignments; the first other word is the command.
                if ($name === null && preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $token->text) !== 1) {
                    $name = $token->text;
                    $line = $token->line;
                }
            } elseif ($token->type === ShellToken::REDIRECT) {
                $target = $this->redirectTarget();
                // After quote removal, so a quote that belongs to the name stays in it.
                if (in_array($token->text, self::FILE_REDIRECTS, true) && ShellLexer::unquoted($target) !== '/dev/null') {
                    $toFile[] = "{$token->text} {$target}";
                }
            } elseif ($token->type === ShellToken::HEREDOC) {
                $this->position++;
            } else {
                break;
            }
        }

        return $name !== null && $toFile !== [] && in_array($name, self::SPECIAL_BUILTINS, true)
            ? [['line' => $line, 'builtin' => $name, 'probe' => '( : ' . implode(' ', $toFile) . ' ) 2>/dev/null']]
            : [];
    }

    /** @return list<array{line: int, builtin: string, probe: string}> */
    private function conditional(): array
    {
        $keyword = 'if';
        do {
            $this->position++;
            $this->flag($this->list(), "in the condition of {$keyword}");
            $this->expectWord('then');
            $this->list();
            $keyword = 'elif';
        } while ($this->peek()->isWord('elif'));
        if ($this->peek()->isWord('else')) {
            $this->position++;
            $this->list();
        }
        $this->expectWord('fi');

        return [];
    }

    /** @return list<array{line: int, builtin: string, probe: string}> */
    private function loop(string $keyword): array
    {
        $this->position++;
        $this->flag($this->list(), "in the condition of {$keyword}");
        $this->body();

        return [];
    }

    /** @return list<array{line: int, builtin: string, probe: string}> */
    private function forLoop(): array
    {
        $this->position += 2;
        $this->skipNewlines();
        if ($this->peek()->isWord('in')) {
            // Up to the `;` or newline every word is a value, `do` and `done` too.
            do {
                $this->position++;
            } while ($this->peek()->type === ShellToken::WORD);
        }
        while ($this->peek()->type === ShellToken::NEWLINE || $this->peek()->isOperator(';')) {
            $this->position++;
        }
        $this->body();

        return [];
    }

    private function body(): void
    {
        $this->expectWord('do');
        $this->list();
        $this->expectWord('done');
    }

    /** @return list<array{line: int, builtin: string, probe: string}> */
    private function caseOf(): array
    {
        $this->position += 2;
        $this->skipNewlines();
        $this->expectWord('in');
        while (true) {
            $this->skipNewlines();
            if ($this->peek()->isWord('esac')) {
                $this->position++;

                return [];
            }
            // A pattern list: words and `|`, an optional `(` before it, a `)` after it.
            if ($this->peek()->isOperator('(')) {
                $this->position++;
            }
            while ($this->peek()->type === ShellToken::WORD || $this->peek()->isOperator('|')) {
                $this->position++;
            }
            $this->expectOperator(')');
            $this->list();
            if ($this->peek()->isOperator(';;')) {
                $this->position++;
            }
        }
    }

    /** @return list<array{line: int, builtin: string, probe: string}> what the group holds stands where the group stands */
    private function group(): array
    {
        $this->position++;
        $open = $this->list();
        $this->expectWord('}');

        return $open;
    }

    /** Redirections after a compound command: they belong to no special builtin. */
    private function redirections(): void
    {
        while (true) {
            $token = $this->peek();
            if ($token->type === ShellToken::REDIRECT) {
                $this->redirectTarget();
            } elseif ($token->type === ShellToken::HEREDOC) {
                $this->position++;
            } else {
                return;
            }
        }
    }

    /** At a redirection operator: over it and its target, which must be a word. */
    private function redirectTarget(): string
    {
        $operator = $this->peek();
        $this->position++;
        $target = $this->peek();
        if ($target->type !== ShellToken::WORD) {
            throw new \UnexpectedValueException("line {$operator->line}: `{$operator->text}` with no target");
        }
        $this->position++;

        return $target->text;
    }

    /** @param list<array{line: int, builtin: string, probe: string}> $open */
    private function flag(array $open, string $context): void
    {
        foreach ($open as $found) {
            $this->flagged[] = $found + ['context' => $context];
        }
    }

    private function skipNewlines(): void
    {
        while ($this->peek()->type === ShellToken::NEWLINE) {
            $this->position++;
        }
    }

    private function expectWord(string $word): void
    {
        $token = $this->peek();
        if (!$token->isWord($word)) {
            throw new \UnexpectedValueException("line {$token->line}: expected `{$word}`, found " . ($token->type === ShellToken::END ? 'the end of the script' : "`{$token->text}`"));
        }
        $this->position++;
    }

    private function expectOperator(string $operator): void
    {
        $token = $this->peek();
        if (!$token->isOperator($operator)) {
            throw new \UnexpectedValueException("line {$token->line}: expected `{$operator}`, found " . ($token->type === ShellToken::END ? 'the end of the script' : "`{$token->text}`"));
        }
        $this->position++;
    }

    private function peek(): ShellToken
    {
        return $this->tokens[min($this->position, count($this->tokens) - 1)];
    }
}

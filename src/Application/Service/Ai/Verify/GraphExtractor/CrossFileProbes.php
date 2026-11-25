<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\GraphExtractor;

/**
 * The places where PHP code reads beyond the file it was given, by its tokens
 * (tk-verify-catches-review-no-classes, class 5).
 *
 * A probe is one of:
 *  - a call of a global filesystem function (`is_file`, `glob`, `file_get_contents`, …),
 *    under its own name or an alias (`use function is_file as there`), or a `new` of a
 *    class that walks or opens files (`SplFileInfo`, `Finder`, …), or Symfony's
 *    `Finder::create()`;
 *  - `include` / `require`, in all four forms;
 *  - `new Reflection*`, and a static call on such a class
 *    (`ReflectionMethod::createFromMethodName()`);
 *  - a call that asks the runtime about a class (`class_exists`, `method_exists`,
 *    `is_subclass_of`, …), and `constant()` of anything but a plain global name
 *    (`constant("A::B")` loads A);
 *  - one of those functions named as a string for `call_user_func`,
 *    `call_user_func_array` or `array_map` to call;
 *  - a `new` of a class named at run time (`new $x(…)`, `new ($expr)`): it autoloads
 *    whichever file declares that class.
 *
 * Not a probe: a method of the same name (`$files->glob(`, `Finder::file(`), a
 * string that nothing calls, a function imported from a namespace
 * (`use function App\is_file`), a qualified call (`App\is_file(`), a function the
 * file itself declares in its namespace, and anything inside an attribute.
 *
 * Flagged though it may read nothing: `is_a`, `method_exists` and `property_exists`
 * given an object ask about a class that is loaded already. Tokens do not say what
 * the argument is, so the call is flagged and the accept says why it is safe.
 *
 * Not seen, known limits: a function called through a variable (`$f = 'is_file';
 * $f($p)`), a static call on a class named at run time (`$c::from($v)`, which
 * autoloads `$c`), and `unserialize()`, which autoloads whatever class the data names.
 */
final class CrossFileProbes
{
    public const FILESYSTEM = 'filesystem';
    public const INCLUDE = 'include';
    public const REFLECTION = 'reflection';
    public const CLASS_PROBE = 'class-probe';
    public const DYNAMIC_NEW = 'dynamic-new';

    private const FUNCTIONS = [
        'file_get_contents' => self::FILESYSTEM, 'file' => self::FILESYSTEM, 'fopen' => self::FILESYSTEM,
        'file_exists' => self::FILESYSTEM, 'is_file' => self::FILESYSTEM, 'is_dir' => self::FILESYSTEM,
        'is_link' => self::FILESYSTEM, 'is_readable' => self::FILESYSTEM, 'glob' => self::FILESYSTEM,
        'scandir' => self::FILESYSTEM, 'opendir' => self::FILESYSTEM, 'readdir' => self::FILESYSTEM,
        'realpath' => self::FILESYSTEM, 'filemtime' => self::FILESYSTEM, 'filesize' => self::FILESYSTEM,
        'stat' => self::FILESYSTEM, 'lstat' => self::FILESYSTEM, 'parse_ini_file' => self::FILESYSTEM,
        'md5_file' => self::FILESYSTEM, 'sha1_file' => self::FILESYSTEM, 'hash_file' => self::FILESYSTEM,
        'is_writable' => self::FILESYSTEM, 'readfile' => self::FILESYSTEM, 'filectime' => self::FILESYSTEM,
        'simplexml_load_file' => self::FILESYSTEM,
        'class_exists' => self::CLASS_PROBE, 'interface_exists' => self::CLASS_PROBE, 'trait_exists' => self::CLASS_PROBE,
        'enum_exists' => self::CLASS_PROBE, 'method_exists' => self::CLASS_PROBE, 'property_exists' => self::CLASS_PROBE,
        'get_class_methods' => self::CLASS_PROBE, 'get_parent_class' => self::CLASS_PROBE, 'class_implements' => self::CLASS_PROBE,
        'class_parents' => self::CLASS_PROBE, 'class_uses' => self::CLASS_PROBE, 'is_subclass_of' => self::CLASS_PROBE,
        'is_a' => self::CLASS_PROBE,
    ];

    /** Functions that call the function their first argument names. */
    private const CALLERS = ['call_user_func', 'call_user_func_array', 'array_map'];

    /** Every kind of probe, as an accept names it. */
    public const KINDS = [self::FILESYSTEM, self::INCLUDE, self::REFLECTION, self::CLASS_PROBE, self::DYNAMIC_NEW];

    /** Classes whose construction opens or walks files, lower case. */
    private const FILESYSTEM_CLASSES = [
        'splfileinfo', 'splfileobject', 'directoryiterator', 'recursivedirectoryiterator', 'filesystemiterator', 'globiterator',
        self::FINDER,
    ];

    private const FINDER = 'symfony\component\finder\finder';

    private const INCLUDES = [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE];

    private const NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    private string $namespace = '';

    /** @var array<string, string> alias, lower case => the function it stands for, lower case */
    private array $functions = [];

    /** @var array<string, string> alias, lower case => the class or namespace it stands for, lower case */
    private array $classes = [];

    /** @param list<\PhpToken> $tokens */
    private function __construct(private readonly array $tokens) {}

    /**
     * @param string $marker what a comment starts with to accept a probe
     * @return array{
     *     probes: list<array{line: int, probe: string, kind: string, index: int}>,
     *     markers: list<array{line: int, text: string, trailing: bool, from: int, to: int}>,
     * } `index` is the probe's token. A marker's `text` is what follows the marker word. A trailing
     *   marker (code stands before it on its line) covers the probes of that line; any other covers
     *   the tokens `from`..`to`, the statement that follows it: up to its `;`, or the `{` of the block
     *   it opens, or, inside a list, the `,` that ends the element
     *
     * @throws \ParseError when the source is not PHP that parses: a scan that could not read must not pass
     */
    public static function read(string $source, string $marker): array
    {
        $reader = new self(array_values(\PhpToken::tokenize($source, TOKEN_PARSE)));
        $probes = [];
        foreach ($reader->probes() as [$index, $probe, $kind]) {
            $probes[] = ['line' => $reader->tokens[$index]->line, 'probe' => $probe, 'kind' => $kind, 'index' => $index];
        }

        return ['probes' => $probes, 'markers' => $reader->markers($marker)];
    }

    /** @return list<array{line: int, text: string, trailing: bool, from: int, to: int}> */
    private function markers(string $marker): array
    {
        $found = [];
        $nesting = 0;
        foreach ($this->tokens as $i => $token) {
            $nesting += match (true) {
                $token->is(T_ATTRIBUTE), in_array($token->text, ['(', '['], true) => 1,
                in_array($token->text, [')', ']'], true) => -1,
                default => 0,
            };
            if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            foreach (explode("\n", $token->text) as $n => $line) {
                if (preg_match('~^' . preg_quote($marker, '~') . '(?![\w-])(.*)$~u', self::commentText($line), $match) !== 1) {
                    continue;
                }
                $from = $this->nextIndex($i);
                $found[] = [
                    'line' => $token->line + $n,
                    'text' => trim($match[1]),
                    'trailing' => $n === 0 && $this->followsCode($i),
                    'from' => $from,
                    'to' => $this->statementEnd($from, $nesting > 0),
                ];
            }
        }

        return $found;
    }

    /** Whether code stands before the comment on its line. */
    private function followsCode(int $comment): bool
    {
        for ($k = $comment - 1; $k >= 0 && $this->tokens[$k]->is(T_WHITESPACE); $k--) {
            if (str_contains($this->tokens[$k]->text, "\n")) {
                return false;
            }
        }
        // An opening tag and a `//` comment end with the line they stand on.
        return $k >= 0 && !str_ends_with($this->tokens[$k]->text, "\n") && !$this->tokens[$k]->is(T_OPEN_TAG);
    }

    /**
     * The last token of the statement that starts at $from.
     *
     * @param bool $inList the statement is an element of a list (an argument, an array item): a `,` ends it
     */
    private function statementEnd(int $from, bool $inList): int
    {
        $depth = 0;
        $count = count($this->tokens);
        for ($k = $from; $k < $count; $k++) {
            $text = $this->tokens[$k]->text;
            if ($this->tokens[$k]->is(T_ATTRIBUTE) || $text === '(' || $text === '[') {
                $depth++;
            } elseif ($text === ')' || $text === ']') {
                if (--$depth < 0) {
                    return $k;
                }
            } elseif ($depth === 0 && ($text === ';' || $text === '}' || $this->tokens[$k]->id === ord('{') || ($inList && $text === ','))) {
                return $k;
            }
        }

        return $count - 1;
    }

    /** @return list<array{int, string, string}> token index, the probe as written, its kind */
    private function probes(): array
    {
        $found = [];
        /** @var list<array{int, string, string, string, string}> $calls index, namespace, name, kind, the probe as reported: unqualified calls, judged once every declaration is known */
        $calls = [];
        $declared = [];
        $bracketed = false;
        $depth = 0;
        $count = count($this->tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $this->tokens[$i];
            if ($token->isIgnorable()) {
                continue;
            }
            $top = $depth === ($bracketed ? 1 : 0);
            $next = $this->next($i);
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            } elseif ($token->is(T_ATTRIBUTE)) {
                $i = $this->attributeEnd($i);
            } elseif ($token->is(T_NAMESPACE)) {
                $named = $next !== null && $next->is([T_STRING, T_NAME_QUALIFIED]);
                $this->namespace = $named ? strtolower($next->text) : '';
                $opening = $named ? $this->next($this->nextIndex($i)) : $next;
                $bracketed = $opening?->text === '{';
                $this->functions = $this->classes = [];
            } elseif ($token->is(T_USE) && $top && $next?->text !== '(') {
                $i = $this->import($i);
            } elseif ($token->is(T_FUNCTION) && $top) {
                $name = $next?->text === '&' ? $this->next($this->nextIndex($i)) : $next;
                if ($name?->is(T_STRING) === true) {
                    $declared[$this->namespace][strtolower($name->text)] = true;
                }
            } elseif ($token->is(self::INCLUDES)) {
                $found[] = [$i, strtolower($token->text), self::INCLUDE];
            } elseif ($token->is(T_NEW) && $next !== null) {
                if ($next->is(T_VARIABLE) || $next->text === '(' || $next->text === '$') {
                    $found[] = [$i, 'new ' . ($next->is(T_VARIABLE) ? $next->text : ($next->text === '(' ? '(…)' : '$…')), self::DYNAMIC_NEW];
                } elseif ($next->is(self::NAMES)) {
                    $class = $this->classNamed($next);
                    $kind = preg_match('~^reflection\w*$~', $class) === 1 ? self::REFLECTION : (in_array($class, self::FILESYSTEM_CLASSES, true) ? self::FILESYSTEM : null);
                    if ($kind !== null) {
                        $found[] = [$i, 'new ' . ltrim($next->text, '\\'), $kind];
                    }
                }
            } elseif ($token->is(self::NAMES) && $next?->is(T_DOUBLE_COLON) === true) {
                // Finder::create() is `new Finder()` by another spelling.
                $method = $this->next($this->nextIndex($i));
                $called = $this->next($this->nextIndex($this->nextIndex($i)))?->text === '(';
                if ($called && $method !== null && strtolower($method->text) === 'create' && $this->classNamed($token) === self::FINDER) {
                    $found[] = [$i, ltrim($token->text, '\\') . '::create', self::FILESYSTEM];
                } elseif ($called && $method !== null && preg_match('~^reflection\w*$~', $this->classNamed($token)) === 1) {
                    $found[] = [$i, ltrim($token->text, '\\') . '::' . $method->text, self::REFLECTION];
                }
            } elseif ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && $next?->text === '(') {
                $written = strtolower(ltrim($token->text, '\\'));
                // `use function is_file as there`: the call reaches what the alias stands for.
                $name = $token->is(T_STRING) ? $this->functions[$written] ?? $written : $written;
                if ($this->namesNoFunctionCall($i)) {
                    continue;
                }
                $imported = $token->is(T_NAME_FULLY_QUALIFIED) || isset($this->functions[$written]);
                $probe = $name === $written ? $name : "{$written} ({$name})";
                $kind = self::FUNCTIONS[$name] ?? null;
                if ($kind === null) {
                    [$probe, $kind] = $this->calledByName($i, $name) ?? [$probe, null];
                }
                if ($kind === null) {
                    continue;
                }
                if ($imported) {
                    $found[] = [$i, $probe, $kind];
                } else {
                    $calls[] = [$i, $this->namespace, $written, $kind, $probe];
                }
            }
        }
        foreach ($calls as [$index, $namespace, $name, $kind, $probe]) {
            // A function the file declares in its own namespace is the one an unqualified call reaches.
            if ($namespace === '' || !isset($declared[$namespace][$name])) {
                $found[] = [$index, $probe, $kind];
            }
        }
        usort($found, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $found;
    }

    /**
     * A probe that $function, called at $index, reaches through its first argument:
     * `constant("A::B")`, `array_map('is_file', $paths)`.
     *
     * @return array{string, string}|null the probe as reported, its kind
     */
    private function calledByName(int $index, string $function): ?array
    {
        $argument = $this->nextIndex($this->nextIndex($index));
        $first = $this->tokens[$argument] ?? null;
        $literal = $first?->is(T_CONSTANT_ENCAPSED_STRING) === true && in_array($this->next($argument)?->text, [',', ')'], true)
            ? stripslashes(substr($first->text, 1, -1))
            : null;
        if ($function === 'constant') {
            // A plain name is a global constant; anything else may name a class, and loads it.
            return $literal !== null && !str_contains($literal, '::') ? null : ['constant', self::CLASS_PROBE];
        }
        if (!in_array($function, self::CALLERS, true) || $literal === null) {
            return null;
        }
        $called = strtolower(ltrim($literal, '\\'));

        return isset(self::FUNCTIONS[$called]) ? ["{$function}('{$called}')", self::FUNCTIONS[$called]] : null;
    }

    /** A name before `(` that calls no function: a method, a declaration, a class after `new`. */
    private function namesNoFunctionCall(int $index): bool
    {
        $before = $this->previousIndex($index);
        if ($before === null) {
            return false;
        }
        $previous = $this->tokens[$before];
        if ($previous->text === '&') {
            $previous = $this->previous($before) ?? $previous;
        }

        return $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW]);
    }

    /** The class a name after `new` stands for, lower case, as PHP resolves it: classes never fall back to the global namespace. */
    private function classNamed(\PhpToken $name): string
    {
        $written = strtolower($name->text);
        if ($name->is(T_NAME_FULLY_QUALIFIED)) {
            return ltrim($written, '\\');
        }
        if ($name->is(T_NAME_RELATIVE)) {
            return ltrim($this->namespace . substr($written, strlen('namespace')), '\\');
        }
        [$first, $rest] = explode('\\', $written, 2) + [1 => null];
        $head = $this->classes[$first] ?? ltrim($this->namespace . '\\' . $first, '\\');

        return $rest === null ? $head : $head . '\\' . $rest;
    }

    /**
     * Reads one `use …;` at namespace level into the import tables.
     *
     * @return int the index of its `;`
     */
    private function import(int $index): int
    {
        $text = '';
        $count = count($this->tokens);
        for ($k = $index + 1; $k < $count && $this->tokens[$k]->text !== ';'; $k++) {
            if (!$this->tokens[$k]->isIgnorable()) {
                $text .= $this->tokens[$k]->text . ' ';
            }
        }
        $type = 'class';
        if (preg_match('~^(function|const) (.*)$~is', $text, $m) === 1) {
            [$type, $text] = [strtolower($m[1]), $m[2]];
        }
        $prefix = '';
        if (preg_match('~^(.*?)\{(.*)\}~s', $text, $m) === 1) {
            [$prefix, $text] = [str_replace(' ', '', $m[1]), $m[2]];
        }
        foreach (explode(',', $text) as $item) {
            $item = trim($item);
            $itemType = $type;
            if (preg_match('~^(function|const) (.*)$~is', $item, $m) === 1) {
                [$itemType, $item] = [strtolower($m[1]), $m[2]];
            }
            $parts = preg_split('~\s+as\s+~i', $item) ?: [$item];
            $name = strtolower(ltrim($prefix . str_replace(' ', '', $parts[0]), '\\'));
            if ($name === '') {
                continue;
            }
            $alias = strtolower(trim($parts[1] ?? substr((string) strrchr('\\' . $name, '\\'), 1)));
            if ($itemType === 'function') {
                $this->functions[$alias] = $name;
            } elseif ($itemType === 'class') {
                $this->classes[$alias] = $name;
            }
        }

        return $k;
    }

    /** One line of a comment without what frames it: `//`, `#`, `/**`, a leading `*`, the closing mark. */
    private static function commentText(string $line): string
    {
        return trim((string) preg_replace(['~\*+/\s*$~', '~^\s*(?://+|#+|/\*+|\*+)~'], '', $line));
    }

    /** @return int the index of the `]` that closes the attribute group opened at $index */
    private function attributeEnd(int $index): int
    {
        $depth = 0;
        $count = count($this->tokens);
        for ($k = $index; $k < $count; $k++) {
            $text = $this->tokens[$k]->text;
            $depth += $text === '[' || $this->tokens[$k]->is(T_ATTRIBUTE) ? 1 : ($text === ']' ? -1 : 0);
            if ($depth === 0) {
                return $k;
            }
        }

        return $count;
    }

    private function nextIndex(int $index): int
    {
        $count = count($this->tokens);
        for ($k = $index + 1; $k < $count && $this->tokens[$k]->isIgnorable(); $k++);

        return $k;
    }

    private function next(int $index): ?\PhpToken
    {
        return $this->tokens[$this->nextIndex($index)] ?? null;
    }

    private function previousIndex(int $index): ?int
    {
        for ($k = $index - 1; $k >= 0 && $this->tokens[$k]->isIgnorable(); $k--);

        return $k >= 0 ? $k : null;
    }

    private function previous(int $index): ?\PhpToken
    {
        $before = $this->previousIndex($index);

        return $before === null ? null : $this->tokens[$before];
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\GraphExtractor;

/**
 * The places where PHP code reads beyond the file it was given, by its tokens
 * (tk-verify-catches-review-no-classes, class 5).
 *
 * A probe is one of:
 *  - a call of a global filesystem function (`is_file`, `glob`, `file_get_contents`, …),
 *    or a `new` of a class that walks or opens files (`SplFileInfo`, `Finder`, …), or
 *    Symfony's `Finder::create()`;
 *  - `include` / `require`, in all four forms;
 *  - `new Reflection*`;
 *  - a call that asks the runtime about a class (`class_exists`, `method_exists`,
 *    `is_subclass_of`, …);
 *  - a `new` of a class named at run time (`new $x(…)`, `new ($expr)`): it autoloads
 *    whichever file declares that class.
 *
 * Not a probe: a method of the same name (`$files->glob(`, `Finder::file(`), a
 * string, a function imported from a namespace (`use function App\is_file`), a
 * qualified call (`App\is_file(`), a function the file itself declares in its
 * namespace, and anything inside an attribute.
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
        'class_exists' => self::CLASS_PROBE, 'interface_exists' => self::CLASS_PROBE, 'trait_exists' => self::CLASS_PROBE,
        'enum_exists' => self::CLASS_PROBE, 'method_exists' => self::CLASS_PROBE, 'property_exists' => self::CLASS_PROBE,
        'get_class_methods' => self::CLASS_PROBE, 'get_parent_class' => self::CLASS_PROBE, 'class_implements' => self::CLASS_PROBE,
        'class_parents' => self::CLASS_PROBE, 'class_uses' => self::CLASS_PROBE, 'is_subclass_of' => self::CLASS_PROBE,
        'is_a' => self::CLASS_PROBE,
    ];

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
     * @return array{
     *     probes: list<array{line: int, probe: string, kind: string, classLike: ?int}>,
     *     comments: array<int, string>,
     *     classLikes: list<array{name: string, line: int, markerLines: list<int>}>,
     * } `classLike` indexes `classLikes`: the named class, trait, interface or enum the probe stands in;
     *   `comments` is line => the text of the comment on it, without its `//`, `#` or `*`
     *
     * @throws \ParseError when the source is not PHP that parses: a scan that could not read must not pass
     */
    public static function read(string $source): array
    {
        $reader = new self(array_values(\PhpToken::tokenize($source, TOKEN_PARSE)));
        $extents = $reader->classLikes();
        $probes = [];
        foreach ($reader->probes() as [$index, $probe, $kind]) {
            $inside = null;
            foreach ($extents as $n => $extent) {
                if ($index > $extent['open'] && $index < $extent['close']) {
                    $inside = $n;
                    break;
                }
            }
            $probes[] = ['line' => $reader->tokens[$index]->line, 'probe' => $probe, 'kind' => $kind, 'classLike' => $inside];
        }

        return [
            'probes' => $probes,
            'comments' => $reader->comments(),
            'classLikes' => array_map(static fn (array $e): array => ['name' => $e['name'], 'line' => $e['line'], 'markerLines' => $e['markerLines']], $extents),
        ];
    }

    /** @return list<array{int, string, string}> token index, the probe as written, its kind */
    private function probes(): array
    {
        $found = [];
        /** @var list<array{int, string, string, string}> $calls index, namespace, name, kind: unqualified calls, judged once every declaration is known */
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
                }
            } elseif ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && $next?->text === '(') {
                $name = strtolower(ltrim($token->text, '\\'));
                $kind = self::FUNCTIONS[$name] ?? null;
                if ($kind === null || $this->namesNoFunctionCall($i)) {
                    continue;
                }
                if ($token->is(T_NAME_FULLY_QUALIFIED)) {
                    $found[] = [$i, $name, $kind];
                } elseif (($this->functions[$name] ?? $name) === $name) {
                    $calls[] = [$i, $this->namespace, $name, $kind];
                }
            }
        }
        foreach ($calls as [$index, $namespace, $name, $kind]) {
            // A function the file declares in its own namespace is the one an unqualified call reaches.
            if ($namespace === '' || !isset($declared[$namespace][$name])) {
                $found[] = [$index, $name, $kind];
            }
        }
        usort($found, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $found;
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

    /**
     * Every named class, trait, interface and enum: where its body lies, and the lines a marker for all of it may stand on.
     *
     * @return list<array{name: string, line: int, markerLines: list<int>, open: int, close: int}>
     */
    private function classLikes(): array
    {
        $found = [];
        $count = count($this->tokens);
        foreach ($this->tokens as $i => $token) {
            $name = $this->next($i);
            if (!$token->is([T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM]) || $name?->is(T_STRING) !== true || $this->previous($i)?->is(T_DOUBLE_COLON) === true) {
                continue;
            }
            // The declaration starts at its first modifier.
            $start = $i;
            while (($before = $this->previousIndex($start)) !== null && $this->tokens[$before]->is([T_FINAL, T_ABSTRACT, T_READONLY])) {
                $start = $before;
            }
            for ($open = $i; $open < $count && $this->tokens[$open]->text !== '{'; $open++);
            $depth = 0;
            for ($close = $open; $close < $count; $close++) {
                $at = $this->tokens[$close];
                if ($at->text === '{' || $at->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                } elseif ($at->text === '}' && --$depth === 0) {
                    break;
                }
            }
            $line = $this->tokens[$start]->line;
            $found[] = ['name' => $name->text, 'line' => $line, 'markerLines' => [$line - 1, ...$this->docblockLastLine($start)], 'open' => $open, 'close' => $close];
        }

        return $found;
    }

    /**
     * The last line with text of the docblock a declaration carries; attributes and comments may stand between the two.
     *
     * @return list<int> one line, or none without a docblock
     */
    private function docblockLastLine(int $declaration): array
    {
        for ($k = $declaration - 1; $k >= 0; $k--) {
            $token = $this->tokens[$k];
            if ($token->is(T_DOC_COMMENT)) {
                $lines = explode("\n", $token->text);
                for ($n = count($lines) - 1; $n >= 0; $n--) {
                    if (self::commentText($lines[$n]) !== '') {
                        return [$token->line + $n];
                    }
                }

                return [];
            }
            if ($token->text === ']') {
                // An attribute group: back to the `#[` that opens it.
                for ($depth = 0; $k >= 0; $k--) {
                    $depth += $this->tokens[$k]->text === ']' ? 1 : ($this->tokens[$k]->text === '[' || $this->tokens[$k]->is(T_ATTRIBUTE) ? -1 : 0);
                    if ($depth === 0) {
                        break;
                    }
                }
                continue;
            }
            if (!$token->is([T_WHITESPACE, T_COMMENT])) {
                break;
            }
        }

        return [];
    }

    /** @return array<int, string> */
    private function comments(): array
    {
        $comments = [];
        foreach ($this->tokens as $token) {
            if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            foreach (explode("\n", $token->text) as $n => $line) {
                $text = self::commentText($line);
                if ($text !== '') {
                    $comments[$token->line + $n] = isset($comments[$token->line + $n]) ? $comments[$token->line + $n] . ' ' . $text : $text;
                }
            }
        }

        return $comments;
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

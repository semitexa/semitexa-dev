<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\GraphExtractor;

/**
 * Finds the project-graph extractor that reads beyond the file it was given
 * (tk-verify-catches-review-no-classes, class 5).
 *
 * An extractor turns ONE parsed file into nodes and edges, and a refresh
 * re-reads only the files that changed. So a fact that also depends on another
 * file (does the template exist, what does that class declare) is right after a
 * full build and stale after a refresh: the other file changes, and nothing
 * re-reads the one that asked about it. What counts as such a read is in
 * {@see CrossFileProbes}.
 *
 * Read: every PHP file under {@see self::DIRECTORIES}, the extractors and the
 * parser that hands them a file, and every class of the package those files
 * import with `use`, followed from import to import. A read an extractor
 * delegates to a helper is the extractor's read. Not followed: a class of the
 * package named only by its full name in the code, or only as a neighbour in
 * its own namespace. A project that does not hold the package there (a
 * consumer) has nothing to read, and passes. A file that is there and cannot
 * be read is a problem, never a file with nothing in it.
 *
 * Accepted with
 *
 *     // verify:accept-cross-file-read <kind>[,<kind>] <TestClass>::<testMethod> <reason>
 *
 * for ONE read site. After code, the marker accepts the reads of its own line.
 * On a line of its own it accepts the reads of the statement that follows: up
 * to its `;`, or to the `{` of the block it opens, never the block. The kinds
 * are {@see CrossFileProbes::KINDS}; a read of a kind the marker does not name
 * is not accepted. The test named is the convergence test that proves a
 * refresh still gives what a full build gives; it must be a public test method
 * (PHPUnit's `#[Test]`, or a name that starts with `test`) that the named class
 * under {@see self::TESTS} declares itself. A marker that names no kind, no test, an unknown one,
 * gives no reason of 20 characters, or accepts a read that is not there is a
 * problem of its own.
 */
final class GraphExtractorScan
{
    public const MARKER = 'verify:accept-cross-file-read';

    /** The package whose extractors are read, relative to the project root. */
    public const PACKAGE = 'packages/semitexa-project-graph';

    /** Where the extractors live, relative to the project root. */
    public const DIRECTORY = self::PACKAGE . '/src/Application/Service/Extractor';

    /** What runs between one file in and its nodes and edges out: the extractors, and the parser whose objects they ask. */
    public const DIRECTORIES = [self::DIRECTORY, self::PACKAGE . '/src/Application/Service/Parser'];

    /** Where a convergence test an accept names must be declared. */
    public const TESTS = self::PACKAGE . '/tests';

    /** The package's namespace, and where PSR-4 looks for it. */
    private const NAMESPACE = 'Semitexa\\ProjectGraph\\';
    private const SOURCE = self::PACKAGE . '/src';

    /** The same floor as {@see \Semitexa\Dev\Application\Service\Ai\Verify\Shell\ShellFallbackScan}: a reason is a sentence, not a word. */
    private const MIN_REASON = 20;

    private const FORM = '`// ' . self::MARKER . ' <kind>[,<kind>] <TestClass>::<testMethod> <reason>`';

    private const WHAT = [
        CrossFileProbes::FILESYSTEM => 'reads the filesystem',
        CrossFileProbes::INCLUDE => 'loads another file',
        CrossFileProbes::REFLECTION => 'reflects on a class, which reads the file that declares it',
        CrossFileProbes::CLASS_PROBE => 'asks the runtime about a class, which reads the file that declares it',
        CrossFileProbes::DYNAMIC_NEW => 'instantiates a class named at run time, which loads the file that declares it',
    ];

    /**
     * Whether a changed file can change what this lint says: PHP of the package's
     * source (an extractor, or a helper one reaches) or of its tests (an accept names one).
     */
    public static function covers(string $path): bool
    {
        $path = ltrim($path, '/');

        return str_ends_with($path, '.php') && (str_starts_with($path, self::SOURCE . '/') || str_starts_with($path, self::TESTS . '/'));
    }

    /** Whether the project holds anything this lint reads: an extractor, or the parser. */
    public static function hasSources(string $projectRoot): bool
    {
        return self::sources($projectRoot) !== [];
    }

    /**
     * @return array{
     *     findings: list<array{line: int, probe: string, kind: string}>,
     *     accepted: list<array{line: int, probe: string, kind: string, test: string, method: string, reason: string}>,
     *     markers: list<array{line: int, problem: string}>,
     * } `markers`: accept markers that are not well formed, or accept a read that is not there; whether the test exists is {@see self::problems()}'s to say
     *
     * @throws \ParseError when the source does not parse
     */
    public static function scan(string $source): array
    {
        $read = CrossFileProbes::read($source, self::MARKER);
        $findings = $accepted = $problems = [];
        /** @var array<int, array{kinds: list<string>, test: string, method: string, reason: string}|null> $markers null: not well formed */
        $markers = [];
        /** @var array<int, array<string, true>> $used marker => the kinds it accepted a read of */
        $used = [];
        foreach ($read['markers'] as $n => $marker) {
            $markers[$n] = null;
            if (preg_match('~^:?\s*(?:([a-z-]+(?:\s*,\s*[a-z-]+)*)\s+)?\\\\?([A-Za-z_][\w\\\\]*)::([A-Za-z_]\w*)(?:\(\))?(?![\w\\\\:])[\s:—-]*(.*)$~u', $marker['text'], $match) !== 1) {
                $problems[$marker['line']] = 'names no test: write it as ' . self::FORM;
                continue;
            }
            $kinds = $match[1] === '' ? [] : array_values(array_unique(preg_split('~\s*,\s*~', $match[1]) ?: []));
            $unknown = array_diff($kinds, CrossFileProbes::KINDS);
            if ($kinds === [] || $unknown !== []) {
                $problems[$marker['line']] = sprintf(
                    '%s: write it as %s, where a kind is one of %s',
                    $kinds === [] ? 'names no kind of read' : 'names no kind of read this lint knows (' . implode(', ', $unknown) . ')',
                    self::FORM,
                    implode(', ', CrossFileProbes::KINDS),
                );
            } elseif (mb_strlen($match[4]) < self::MIN_REASON) {
                $problems[$marker['line']] = sprintf('names %s::%s and gives no reason of %d characters: say why this read is safe after the test name', $match[2], $match[3], self::MIN_REASON);
            } else {
                $markers[$n] = ['kinds' => $kinds, 'test' => $match[2], 'method' => $match[3], 'reason' => $match[4]];
            }
        }
        foreach ($read['probes'] as $probe) {
            $found = ['line' => $probe['line'], 'probe' => $probe['probe'], 'kind' => $probe['kind']];
            $refused = true;
            foreach ($read['markers'] as $n => $marker) {
                if (!($marker['trailing'] ? $marker['line'] === $probe['line'] : $probe['index'] >= $marker['from'] && $probe['index'] <= $marker['to'])) {
                    continue;
                }
                $accept = $markers[$n];
                if ($accept === null) {
                    // The marker is reported; the read it stands at is not reported a second time.
                    $refused = false;
                } elseif (in_array($probe['kind'], $accept['kinds'], true)) {
                    $accepted[] = $found + ['test' => $accept['test'], 'method' => $accept['method'], 'reason' => $accept['reason']];
                    $used[$n][$probe['kind']] = true;
                    $refused = false;
                    break;
                }
            }
            if ($refused) {
                $findings[] = $found;
            }
        }
        foreach ($read['markers'] as $n => $marker) {
            $idle = $markers[$n] === null ? [] : array_values(array_diff($markers[$n]['kinds'], array_keys($used[$n] ?? [])));
            if ($idle !== []) {
                $problems[$marker['line']] = sprintf(
                    'accepts a %s read, and none stands %s: an accept covers one read site. Remove it, or move it to the read',
                    implode(', ', $idle),
                    $marker['trailing'] ? 'on its line' : 'in the statement below it',
                );
            }
        }
        ksort($problems);

        return ['findings' => $findings, 'accepted' => $accepted, 'markers' => array_map(static fn (int $line, string $problem): array => ['line' => $line, 'problem' => $problem], array_keys($problems), $problems)];
    }

    /**
     * Every file the extractors run, or the given files, with what was found in each.
     *
     * @param list<string> $files       paths relative to the project root; empty: every file of {@see self::sources()}.
     *                                  A given file the extractors run is read with every helper it reaches, which a
     *                                  new import may have added. A given file of the package that they do not run
     *                                  (a test, another class) or that is gone is not read itself: it may have changed
     *                                  what an accept names or what is reached, so the project is read. Any other given
     *                                  file is read alone
     * @param bool         $withProject read the project beside the given files
     * @return array<string, list<string>> file, relative to the project => its problems
     */
    public static function problems(string $projectRoot, array $files = [], bool $withProject = false): array
    {
        $root = rtrim($projectRoot, '/');
        $problems = [];
        /** @var array<string, ?string> $tests "Class::method" => what is wrong with it, null when it is there */
        $tests = [];
        // A selection outside the package, without --all, does not involve the project: it is not
        // discovered, so a package file that cannot be read does not fail a clean selection.
        $involvesProject = $files === [] || $withProject;
        foreach ($files as $file) {
            $involvesProject = $involvesProject || str_starts_with(ltrim($file, '/'), self::PACKAGE . '/');
        }
        [$project, $unreadable] = $involvesProject ? self::reach($root, self::seeds($root)) : [[], []];
        $given = $reaching = [];
        foreach ($files as $file) {
            $path = ltrim($file, '/');
            if (in_array($path, $project, true)) {
                $given[] = $reaching[] = $path;
            } elseif (str_ends_with($path, '.php') && str_starts_with($path, self::PACKAGE . '/')) {
                $withProject = true;
            } else {
                $given[] = $file;
            }
        }
        $sources = $files === [] || $withProject ? array_values(array_unique([...$given, ...$project])) : array_values(array_unique([...$given, ...self::reach($root, $reaching)[0]]));
        foreach ($unreadable as $relative) {
            // Found while following imports, for any selection that involves the package: what it imports was not followed.
            $problems[$relative][] = 'cannot be read: the lint did not check it';
            $sources = array_values(array_diff($sources, [$relative]));
        }
        foreach ($sources as $relative) {
            if ($relative === '') {
                // An unset variable in `--file="$path"`: it names nothing, and must not pass as "nothing found".
                $problems['(empty --file)'][] = 'no path was given: the lint checked nothing for it';
                continue;
            }
            if (!str_ends_with($relative, '.php')) {
                continue;
            }
            $source = @file_get_contents($root . '/' . $relative);
            if ($source === false) {
                $problems[$relative][] = 'cannot be read: the lint did not check it';
                continue;
            }
            try {
                $scan = self::scan($source);
            } catch (\ParseError $e) {
                $problems[$relative][] = "could not be parsed (line {$e->getLine()}: {$e->getMessage()}): the lint did not check it";
                continue;
            }
            /** @var array<int, string> $found line => message, in the order of the file */
            $found = [];
            foreach ($scan['findings'] as $finding) {
                $found[$finding['line']] ??= sprintf(
                    'line %d: `%s` %s; a refresh re-reads only the files that changed, so a fact that depends on another file goes stale. Resolve it where the graph is read, or accept it with `// %s %s <TestClass>::<testMethod> <reason>`, naming the convergence test that covers it',
                    $finding['line'],
                    $finding['probe'],
                    self::WHAT[$finding['kind']] ?? 'reads beyond its file',
                    self::MARKER,
                    $finding['kind'],
                );
            }
            foreach ($scan['markers'] as $marker) {
                $found[$marker['line']] = sprintf('line %d: `%s` %s', $marker['line'], self::MARKER, $marker['problem']);
            }
            foreach ($scan['accepted'] as $accept) {
                $key = $accept['test'] . '::' . $accept['method'];
                if (!array_key_exists($key, $tests)) {
                    $tests[$key] = self::testProblem($root, $accept['test'], $accept['method']);
                }
                if ($tests[$key] !== null) {
                    $found[$accept['line']] ??= sprintf('line %d: `%s` accepted with `%s` %s', $accept['line'], $accept['probe'], self::MARKER, $tests[$key]);
                }
            }
            if ($found !== []) {
                ksort($found);
                $problems[$relative] = array_values($found);
            }
        }
        ksort($problems);

        return $problems;
    }

    /**
     * What the extractors run: every PHP file under {@see self::DIRECTORIES}, and every
     * class of the package those import with `use`, followed from import to import.
     *
     * @return list<string> relative to the root, sorted
     */
    public static function sources(string $projectRoot): array
    {
        $root = rtrim($projectRoot, '/');

        return self::reach($root, self::seeds($root))[0];
    }

    /** @return list<string> */
    private static function seeds(string $root): array
    {
        return array_merge(...array_map(static fn (string $directory): array => self::phpFilesUnder($root, $directory), self::DIRECTORIES));
    }

    /**
     * The given files of the package and every class of the package they import, from import to import.
     *
     * @param list<string> $seeds relative to the root
     * @return array{list<string>, list<string>} the files, sorted; those of them that could not be read, so that what they import is unknown
     */
    private static function reach(string $root, array $seeds): array
    {
        $queue = $seeds;
        $seen = array_fill_keys($queue, true);
        $unreadable = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            $source = @file_get_contents($root . '/' . $current);
            if ($source === false) {
                $unreadable[] = $current;
                continue;
            }
            foreach (self::imports($source) as $class) {
                $file = self::SOURCE . '/' . str_replace('\\', '/', substr($class, strlen(self::NAMESPACE))) . '.php';
                if (!isset($seen[$file]) && is_file($root . '/' . $file)) {
                    $seen[$file] = true;
                    $queue[] = $file;
                }
            }
        }
        $found = array_keys($seen);
        sort($found);

        return [$found, $unreadable];
    }

    /**
     * The classes of the package a file imports at namespace level, in a namespace with
     * braces too: `use A\B;`, `use A\B as C;`, `use A\{B, C};`. Read from its tokens.
     *
     * @return list<string>
     */
    private static function imports(string $source): array
    {
        $found = [];
        foreach (self::namespaces($source) as $namespace) {
            foreach ($namespace['imports'] as $class) {
                if (stripos($class, self::NAMESPACE) === 0) {
                    $found[] = self::NAMESPACE . substr($class, strlen(self::NAMESPACE));
                }
            }
        }

        return $found;
    }

    /**
     * Each namespace of a file with its class imports, and the tokens that are code.
     *
     * @return list<array{name: string, imports: array<string, string>, from: int, to: int}> `imports`: alias, lower case => the class;
     *   `from`..`to`: the namespace's tokens in {@see self::code()}
     */
    private static function namespaces(string $source): array
    {
        $tokens = self::code($source);
        $found = [];
        $name = '';
        /** @var array<string, string> $imports */
        $imports = [];
        $from = 0;
        $depth = 0;
        $bracketed = false;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $next = $tokens[$i + 1] ?? null;
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            } elseif ($token->is(T_NAMESPACE) && $next?->is(T_NS_SEPARATOR) !== true) {
                $named = $next !== null && $next->is([T_STRING, T_NAME_QUALIFIED]);
                $bracketed = ($tokens[$i + ($named ? 2 : 1)] ?? null)?->text === '{';
                $found[] = ['name' => $name, 'imports' => $imports, 'from' => $from, 'to' => $i - 1];
                [$name, $imports, $from] = [$named ? $next->text : '', [], $i];
            } elseif ($token->is(T_USE) && $depth === ($bracketed ? 1 : 0) && $next?->text !== '(') {
                $text = '';
                for ($i++; $i < $count && $tokens[$i]->text !== ';'; $i++) {
                    $text .= $tokens[$i]->text . ' ';
                }
                if (preg_match('~^(function|const) ~i', $text) === 1) {
                    continue;
                }
                [$prefix, $names] = preg_match('~^(.*?)\{(.*)\}~s', $text, $group) === 1 ? [str_replace(' ', '', $group[1]), $group[2]] : ['', $text];
                foreach (explode(',', $names) as $item) {
                    $parts = preg_split('~\s+as\s+~i', trim($item)) ?: [];
                    $class = ltrim($prefix . str_replace(' ', '', $parts[0] ?? ''), '\\');
                    if ($class !== '' && preg_match('~^(function|const) ~i', trim($item)) !== 1) {
                        $imports[strtolower(trim($parts[1] ?? substr((string) strrchr('\\' . $class, '\\'), 1)))] = $class;
                    }
                }
            }
        }

        $found[] = ['name' => $name, 'imports' => $imports, 'from' => $from, 'to' => $count - 1];

        return $found;
    }

    /** @return list<\PhpToken> the tokens of a source that are code: no whitespace, no comment. A source that does not parse still has tokens */
    private static function code(string $source): array
    {
        return array_values(array_filter(\PhpToken::tokenize($source), static fn (\PhpToken $t): bool => !$t->isIgnorable()));
    }

    /** @return list<string> relative to the root, sorted */
    private static function phpFilesUnder(string $root, string $directory): array
    {
        if (!is_dir($root . '/' . $directory)) {
            return [];
        }
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $found[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($found);

        return $found;
    }

    /** What is wrong with the test an accept names; null when the class is declared under the tests and declares the method as a public test. */
    private static function testProblem(string $root, string $class, string $method): ?string
    {
        $short = substr((string) strrchr('\\' . $class, '\\'), 1);
        $candidates = array_filter(self::phpFilesUnder($root, self::TESTS), static fn (string $path): bool => basename($path) === $short . '.php');
        $declaredIn = $found = null;
        foreach ($candidates as $path) {
            $source = @file_get_contents($root . '/' . $path);
            if ($source === false) {
                // It may be the one that declares the class: not reading it must not pass for "another file has it".
                return sprintf('names %s::%s, and %s, which may declare it, cannot be read: the lint did not check the test', $class, $method, $path);
            }
            $methods = self::methodsOf($source, $class);
            if ($methods === null) {
                continue;
            }
            if (($methods[$method] ?? null) === 'test') {
                $found = $path;
                continue;
            }
            $declaredIn = [$path, $methods[$method] ?? null];
        }
        if ($found !== null) {
            return null;
        }

        if ($declaredIn === null) {
            return sprintf('names the test class %s, and no class of that name is declared under %s', $class, self::TESTS);
        }

        return match ($declaredIn[1]) {
            'not public' => sprintf('names %s::%s, and in %s that method is not public: PHPUnit runs only a public method', $class, $method, $declaredIn[0]),
            'no test' => sprintf('names %s::%s, and in %s that method is no test: it carries no #[Test] of PHPUnit and its name does not start with `test`', $class, $method, $declaredIn[0]),
            default => sprintf('names %s::%s, and %s declares no method %s', $class, $method, $declaredIn[0], $method),
        };
    }

    /**
     * The methods the named class declares itself in a file, each as `test` (public, and PHPUnit's
     * `#[Test]` or a name that starts with `test`), `not public` or `no test`; null when the file
     * does not declare that class.
     *
     * @param string $class a short name, or a full one whose namespace must then be the file's
     * @return ?array<string, string>
     */
    private static function methodsOf(string $source, string $class): ?array
    {
        $tokens = self::code($source);
        $declared = false;
        $methods = [];
        foreach (self::namespaces($source) as $namespace) {
            $prefix = $namespace['name'] === '' ? '' : $namespace['name'] . '\\';
            $resolve = static function (\PhpToken $name) use ($namespace, $prefix): string {
                if ($name->is(T_NAME_FULLY_QUALIFIED)) {
                    return ltrim($name->text, '\\');
                }
                $parts = explode('\\', $name->text, 2);
                $rest = $parts[1] ?? null;
                $head = $namespace['imports'][strtolower($parts[0])] ?? $prefix . $parts[0];

                return $rest === null ? $head : $head . '\\' . $rest;
            };
            $depth = 0;
            // The depth of the body of the named class while inside it; a class waits for its `{`.
            $body = null;
            $waiting = false;
            // What stands before the declaration being read: PHPUnit's #[Test], a modifier that is not public.
            $marked = $hidden = false;
            $attribute = 0;
            // Parentheses open inside the attribute: a `,` of an argument list separates no attributes.
            $parentheses = 0;
            for ($i = $namespace['from']; $i <= $namespace['to']; $i++) {
                $token = $tokens[$i];
                $next = $tokens[$i + 1] ?? null;
                if ($attribute > 0 || $token->is(T_ATTRIBUTE)) {
                    $attribute += $token->is(T_ATTRIBUTE) || $token->text === '[' ? 1 : ($token->text === ']' ? -1 : 0);
                    $parentheses = $attribute === 0 ? 0 : $parentheses + ($token->text === '(' ? 1 : ($token->text === ')' ? -1 : 0));
                    // The attribute's own name, not one of its arguments: it follows `#[` or a `,` of the group.
                    $names = $token->is(T_ATTRIBUTE) || ($attribute === 1 && $parentheses === 0 && $token->text === ',');
                    $marked = $marked || ($names && $next?->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) === true && strcasecmp($resolve($next), 'PHPUnit\Framework\Attributes\Test') === 0);
                } elseif ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                    [$body, $waiting] = $waiting ? [$depth, false] : [$body, false];
                    $marked = $hidden = false;
                } elseif ($token->text === '}') {
                    $body = $depth === $body ? null : $body;
                    $depth--;
                    $marked = $hidden = false;
                } elseif ($token->text === ';') {
                    $marked = $hidden = false;
                } elseif ($token->is(T_CLASS) && $next?->is(T_STRING) === true && !($tokens[$i - 1] ?? $token)->is([T_DOUBLE_COLON, T_NEW]) && $body === null) {
                    $waiting = in_array($class, [$next->text, $prefix . $next->text], true);
                    $declared = $declared || $waiting;
                } elseif ($token->is([T_PRIVATE, T_PROTECTED])) {
                    $hidden = true;
                } elseif ($token->is(T_FUNCTION) && $next?->is(T_STRING) === true && $depth === $body) {
                    $methods[$next->text] = match (true) {
                        !$marked && stripos($next->text, 'test') !== 0 => 'no test',
                        $hidden => 'not public',
                        default => 'test',
                    };
                }
            }
        }

        return $declared ? $methods : null;
    }
}

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
 * Read: every PHP file under {@see self::DIRECTORY}. A project that does not
 * hold the package there (a consumer) has nothing to read, and passes.
 *
 * Accepted with
 *
 *     // verify:accept-cross-file-read <TestClass>::<testMethod> <reason>
 *
 * on the line of the probe or the line above it, or once for a whole class:
 * on the line above the class declaration, or as the last line of its
 * docblock. The test named is the convergence test that proves a refresh
 * still gives what a full build gives; it must be a class under
 * {@see self::TESTS} that declares that method. A marker that names no test,
 * an unknown one, or gives no reason of 20 characters is a problem of its own.
 */
final class GraphExtractorScan
{
    public const MARKER = 'verify:accept-cross-file-read';

    /** Where the extractors live, relative to the project root. */
    public const DIRECTORY = 'packages/semitexa-project-graph/src/Application/Service/Extractor';

    /** Where a convergence test an accept names must be declared. */
    public const TESTS = 'packages/semitexa-project-graph/tests';

    /** The same floor as {@see \Semitexa\Dev\Application\Service\Ai\Verify\Shell\ShellFallbackScan}: a reason is a sentence, not a word. */
    private const MIN_REASON = 20;

    private const WHAT = [
        CrossFileProbes::FILESYSTEM => 'reads the filesystem',
        CrossFileProbes::INCLUDE => 'loads another file',
        CrossFileProbes::REFLECTION => 'reflects on a class, which reads the file that declares it',
        CrossFileProbes::CLASS_PROBE => 'asks the runtime about a class, which reads the file that declares it',
        CrossFileProbes::DYNAMIC_NEW => 'instantiates a class named at run time, which loads the file that declares it',
    ];

    /** Whether a changed file is one this lint reads: a PHP file under the extractor directory. */
    public static function covers(string $path): bool
    {
        return str_starts_with(ltrim($path, '/'), self::DIRECTORY . '/') && str_ends_with($path, '.php');
    }

    public static function hasExtractors(string $projectRoot): bool
    {
        return is_dir(rtrim($projectRoot, '/') . '/' . self::DIRECTORY);
    }

    /**
     * @return array{
     *     findings: list<array{line: int, probe: string, kind: string}>,
     *     accepted: list<array{line: int, probe: string, kind: string, test: string, method: string, reason: string}>,
     *     markers: list<array{line: int, problem: string}>,
     * } `markers`: accept markers that name no test or give no reason; whether the test exists is {@see self::problems()}'s to say
     *
     * @throws \ParseError when the source does not parse
     */
    public static function scan(string $source): array
    {
        $read = CrossFileProbes::read($source);
        $findings = $accepted = $markers = [];
        foreach ($read['probes'] as $probe) {
            $found = ['line' => $probe['line'], 'probe' => $probe['probe'], 'kind' => $probe['kind']];
            $lines = [$probe['line'], $probe['line'] - 1, ...($probe['classLike'] === null ? [] : $read['classLikes'][$probe['classLike']]['markerLines'])];
            $marker = null;
            foreach ($lines as $line) {
                if (preg_match('~^' . preg_quote(self::MARKER, '~') . '(?![\w-])(.*)$~u', $read['comments'][$line] ?? '', $match) === 1) {
                    $marker = [$line, trim($match[1])];
                    break;
                }
            }
            if ($marker === null) {
                $findings[] = $found;
                continue;
            }
            [$line, $text] = $marker;
            if (preg_match('~^:?\s*\\\\?([A-Za-z_][\w\\\\]*)::([A-Za-z_]\w*)(?:\(\))?(?![\w\\\\:])[\s:—-]*(.*)$~u', $text, $match) !== 1) {
                $markers[$line] = ['line' => $line, 'problem' => 'names no test: write it as `// ' . self::MARKER . ' <TestClass>::<testMethod> <reason>`'];
            } elseif (mb_strlen($match[3]) < self::MIN_REASON) {
                $markers[$line] = ['line' => $line, 'problem' => sprintf('names %s::%s and gives no reason of %d characters: say why this read is safe after the test name', $match[1], $match[2], self::MIN_REASON)];
            } else {
                $accepted[] = $found + ['test' => $match[1], 'method' => $match[2], 'reason' => $match[3]];
            }
        }
        ksort($markers);

        return ['findings' => $findings, 'accepted' => $accepted, 'markers' => array_values($markers)];
    }

    /**
     * Every extractor of the project, or the given files, with what was found in each.
     *
     * @param list<string> $files       paths relative to the project root; empty: every PHP file under the extractor directory
     * @param bool         $withProject read the extractor directory beside the given files
     * @return array<string, list<string>> file, relative to the project => its problems
     */
    public static function problems(string $projectRoot, array $files = [], bool $withProject = false): array
    {
        $root = rtrim($projectRoot, '/');
        $problems = [];
        /** @var array<string, ?string> $tests "Class::method" => what is wrong with it, null when it is there */
        $tests = [];
        $sources = $files === [] || $withProject ? array_values(array_unique([...$files, ...self::extractors($root)])) : $files;
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
                    'line %d: `%s` %s; a refresh re-reads only the files that changed, so a fact that depends on another file goes stale. Resolve it where the graph is read, or accept it with `// %s <TestClass>::<testMethod> <reason>`, naming the convergence test that covers it',
                    $finding['line'],
                    $finding['probe'],
                    self::WHAT[$finding['kind']] ?? 'reads beyond its file',
                    self::MARKER,
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

    /** @return list<string> */
    private static function extractors(string $root): array
    {
        return self::phpFilesUnder($root, self::DIRECTORY);
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

    /** What is wrong with the test an accept names; null when the class is declared under the tests and declares the method. */
    private static function testProblem(string $root, string $class, string $method): ?string
    {
        $short = substr((string) strrchr('\\' . $class, '\\'), 1);
        $candidates = array_filter(self::phpFilesUnder($root, self::TESTS), static fn (string $path): bool => basename($path) === $short . '.php');
        $declaredIn = null;
        foreach ($candidates as $path) {
            $methods = self::methodsOf((string) @file_get_contents($root . '/' . $path), $class);
            if ($methods === null) {
                continue;
            }
            if (in_array($method, $methods, true)) {
                return null;
            }
            $declaredIn = $path;
        }

        return $declaredIn === null
            ? sprintf('names the test class %s, and no class of that name is declared under %s', $class, self::TESTS)
            : sprintf('names %s::%s, and %s declares no method %s', $class, $method, $declaredIn, $method);
    }

    /**
     * The methods a file declares in the named class; null when it does not declare that class.
     *
     * @param string $class a short name, or a full one whose namespace must then be the file's
     * @return ?list<string>
     */
    private static function methodsOf(string $source, string $class): ?array
    {
        try {
            $tokens = array_values(array_filter(\PhpToken::tokenize($source, TOKEN_PARSE), static fn (\PhpToken $t): bool => !$t->isIgnorable()));
        } catch (\ParseError) {
            return null;
        }
        $namespace = '';
        $declared = false;
        $methods = [];
        foreach ($tokens as $i => $token) {
            $next = $tokens[$i + 1] ?? null;
            if ($next === null) {
                break;
            }
            if ($token->is(T_NAMESPACE) && $next->is([T_STRING, T_NAME_QUALIFIED])) {
                $namespace = $next->text . '\\';
            } elseif ($token->is(T_CLASS) && $next->is(T_STRING) && !($tokens[$i - 1] ?? $token)->is(T_DOUBLE_COLON)) {
                $declared = $declared || in_array($class, [$next->text, $namespace . $next->text], true);
            } elseif ($token->is(T_FUNCTION) && $next->is(T_STRING)) {
                $methods[] = $next->text;
            }
        }

        return $declared ? $methods : null;
    }
}

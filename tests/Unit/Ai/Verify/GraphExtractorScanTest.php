<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\GraphExtractor\CrossFileProbes;
use Semitexa\Dev\Application\Service\Ai\Verify\GraphExtractor\GraphExtractorScan;

/**
 * lint:graph-extractors (tk-verify-catches-review-no-classes, class 5): an extractor that reads
 * beyond the file it was given leaves a fact a refresh never corrects. The sources here are
 * strings, and the projects temporary directories: nothing on disk for another scan to find.
 */
final class GraphExtractorScanTest extends TestCase
{
    private const TEST = 'ConvergenceTest::a_refresh_converges';

    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /** @return iterable<string, array{string, string, string}> code, the probe as reported, its kind */
    public static function probes(): iterable
    {
        foreach (['file_get_contents', 'file', 'fopen', 'file_exists', 'is_file', 'is_dir', 'is_link', 'is_readable', 'glob', 'scandir', 'opendir', 'readdir',
            'realpath', 'filemtime', 'filesize', 'stat', 'lstat', 'parse_ini_file', 'md5_file', 'sha1_file', 'hash_file'] as $function) {
            yield $function => ["\$x = {$function}(\$path);", $function, CrossFileProbes::FILESYSTEM];
        }
        foreach (['class_exists', 'interface_exists', 'trait_exists', 'enum_exists', 'method_exists', 'property_exists', 'get_class_methods', 'get_parent_class',
            'class_implements', 'class_parents', 'class_uses', 'is_subclass_of', 'is_a'] as $function) {
            yield $function => ["\$x = {$function}(\$class);", $function, CrossFileProbes::CLASS_PROBE];
        }
        foreach (['SplFileInfo', 'SplFileObject', 'DirectoryIterator', 'RecursiveDirectoryIterator', 'FilesystemIterator', 'GlobIterator'] as $class) {
            yield "new \\{$class}" => ["\$x = new \\{$class}(\$path);", "new {$class}", CrossFileProbes::FILESYSTEM];
        }
        yield 'new Finder, imported' => ['$x = new Finder();', 'new Finder', CrossFileProbes::FILESYSTEM];
        yield 'new Finder, by its full name' => ['$x = new \Symfony\Component\Finder\Finder();', 'new Symfony\Component\Finder\Finder', CrossFileProbes::FILESYSTEM];
        yield 'new Finder, through an imported namespace' => ['$x = new Component\Finder\Finder();', 'new Component\Finder\Finder', CrossFileProbes::FILESYSTEM];
        yield 'Finder::create(), imported' => ['$x = Finder::create()->in($path);', 'Finder::create', CrossFileProbes::FILESYSTEM];
        yield 'Finder::create(), by its full name' => ['$x = \Symfony\Component\Finder\Finder::create();', 'Symfony\Component\Finder\Finder::create', CrossFileProbes::FILESYSTEM];
        yield 'Finder::create(), through an imported namespace' => ['$x = Component\Finder\Finder::create();', 'Component\Finder\Finder::create', CrossFileProbes::FILESYSTEM];
        yield 'Finder::create(), under an alias' => ['$x = Files::create();', 'Files::create', CrossFileProbes::FILESYSTEM];
        yield 'an imported filesystem class' => ['$x = new SplFileInfo($path);', 'new SplFileInfo', CrossFileProbes::FILESYSTEM];
        yield 'a filesystem class under an alias' => ['$x = new Walk($path);', 'new Walk', CrossFileProbes::FILESYSTEM];
        foreach (['include', 'include_once', 'require', 'require_once'] as $form) {
            yield $form => ["\$x = {$form} \$path;", $form, CrossFileProbes::INCLUDE];
        }
        yield 'new ReflectionClass' => ['$x = (new \ReflectionClass($o))->getShortName();', 'new ReflectionClass', CrossFileProbes::REFLECTION];
        yield 'new ReflectionMethod, imported' => ['$x = new ReflectionMethod($o, "m");', 'new ReflectionMethod', CrossFileProbes::REFLECTION];
        yield 'new ReflectionEnum' => ['$x = new \ReflectionEnum($name);', 'new ReflectionEnum', CrossFileProbes::REFLECTION];
        yield 'new $class' => ['$x = new $class(...$arguments);', 'new $class', CrossFileProbes::DYNAMIC_NEW];
        yield 'new ($expression)' => ['$x = new ($this->name())();', 'new (…)', CrossFileProbes::DYNAMIC_NEW];
        yield 'new $$name' => ['$x = new $$name();', 'new $…', CrossFileProbes::DYNAMIC_NEW];
        yield 'a fully qualified call' => ['$x = \is_file($path);', 'is_file', CrossFileProbes::FILESYSTEM];
        yield 'upper case' => ['$x = IS_FILE($path);', 'is_file', CrossFileProbes::FILESYSTEM];
        yield 'the global function, imported' => ['$x = is_readable($path) && filemtime($path) > 0 ? realpath($path) : null;', 'is_readable', CrossFileProbes::FILESYSTEM];
        yield 'a first-class callable' => ['$x = array_filter($paths, is_dir(...));', 'is_dir', CrossFileProbes::FILESYSTEM];
        yield 'inside a closure that uses a variable' => ['$x = static function () use ($path): bool { return is_file($path); };', 'is_file', CrossFileProbes::FILESYSTEM];
    }

    #[Test]
    #[DataProvider('probes')]
    public function a_read_beyond_the_file_is_flagged(string $code, string $probe, string $kind): void
    {
        $scan = GraphExtractorScan::scan(self::extractor($code));

        self::assertSame([], $scan['accepted']);
        self::assertSame([], $scan['markers']);
        self::assertNotSame([], $scan['findings'], $code);
        self::assertSame(['line' => 17, 'probe' => $probe, 'kind' => $kind], $scan['findings'][0], $code);
    }

    /** @return iterable<string, array{string}> */
    public static function harmless(): iterable
    {
        yield 'a method of the same name' => ['$x = $files->glob("*.twig"); $y = $files?->is_file($path); $z = $this->file($path);'];
        yield 'a static method of the same name' => ['$x = Paths::realpath($path); $y = self::is_dir($path); $z = static::file($path);'];
        yield 'a string naming the function' => ['$x = array_map("realpath", $paths); $y = "is_file($path)"; $z = \'class_exists\';'];
        yield 'a function imported from a namespace' => ['$x = is_link($path);'];
        yield 'a function imported under an alias of a probe' => ['$x = stat($path);'];
        yield 'a function imported in a group' => ['$x = lstat($path) + scandir($path);'];
        yield 'a qualified call' => ['$x = Paths\is_file($path); $y = namespace\is_dir($path);'];
        yield 'a function the file declares in its namespace' => ['$x = filesize($path);'];
        yield 'a class that only shares a name' => ['$x = new Local\SplFileInfo($path); $y = new ReflectionHelper(); $z = new \App\ReflectionClass($o);'];
        yield 'a named class, self, static and an anonymous one' => ['$x = new Edge($a, $b); $y = new self(); $z = new static(); $w = new class { public function file(): void {} };'];
        yield 'a class named like a function' => ['$x = new File($path); $y = new \Glob($path);'];
        yield 'create() on another class, and another member of Finder' => ['$x = Edge::create($a); $y = \App\Finder::create(); $z = Local\Finder::create(); $w = Finder::class . Finder::CREATE; $v = Finder::fromPaths($paths); $u = $files::create();'];
        yield 'a constant and a property' => ['$x = self::FILE . $this->file . File::class . $o->is_dir;'];
        yield 'another function' => ['$x = is_string($path) && str_contains(basename($path), ".") ? pathinfo($path, PATHINFO_EXTENSION) : dirname($path);'];
        yield 'a comment' => ['// is_file($path) would be a read: new \ReflectionClass($x), require $path' . "\n" . '$x = 1;'];
        yield 'an attribute' => ['$x = #[File(1), Glob("x")] static fn (): int => 1;'];
    }

    #[Test]
    #[DataProvider('harmless')]
    public function what_only_looks_like_a_read_passes(string $code): void
    {
        self::assertSame([], GraphExtractorScan::scan(self::extractor($code, true))['findings'], $code);
    }

    #[Test]
    public function a_method_declared_with_the_name_of_a_probe_is_no_call(): void
    {
        $scan = GraphExtractorScan::scan("<?php\nnamespace App;\nfinal class Files\n{\n    public function file(string \$p): string { return \$p; }\n    public function &glob(string \$p): array { return \$this->found; }\n    public function include(string \$p): void {}\n}\n");

        self::assertSame([], $scan['findings']);
    }

    #[Test]
    public function an_unqualified_call_in_the_global_namespace_and_one_in_a_bracketed_namespace_are_read(): void
    {
        self::assertSame([[2, 'is_file']], self::flagged("<?php\n\$x = is_file(\$p);\n"));
        // Imports end with their namespace: the second block calls the global function again.
        self::assertSame([[7, 'is_file']], self::flagged(<<<'PHP'
            <?php
            namespace App\A {
                use function App\Paths\is_file;
                $x = is_file($p);
            }
            namespace App\B {
                $x = is_file($p);
            }
            PHP));
    }

    #[Test]
    public function every_probe_of_a_file_is_reported_with_its_line(): void
    {
        self::assertSame(
            [[17, 'is_file'], [18, 'new ReflectionClass'], [19, 'require_once']],
            self::flagged(self::extractor("\$a = is_file(\$p);\n        \$b = new \\ReflectionClass(\$o);\n        \$c = require_once \$p;")),
        );
    }

    #[Test]
    public function an_accept_on_the_line_or_the_line_above_names_the_test(): void
    {
        $onTheLine = GraphExtractorScan::scan(self::extractor('$x = is_file($path); // verify:accept-cross-file-read ' . self::TEST . ' the engine re-reads this file when the target changes'));
        self::assertSame([], $onTheLine['findings']);
        self::assertSame([['line' => 17, 'probe' => 'is_file', 'kind' => CrossFileProbes::FILESYSTEM, 'test' => 'ConvergenceTest', 'method' => 'a_refresh_converges', 'reason' => 'the engine re-reads this file when the target changes']], $onTheLine['accepted']);

        $above = GraphExtractorScan::scan(self::extractor("// verify:accept-cross-file-read \\App\\Tests\\ConvergenceTest::a_refresh_converges() — a refresh re-reads the referrer\n        \$x = is_file(\$path);"));
        self::assertSame([], $above['findings']);
        self::assertSame(['App\Tests\ConvergenceTest', 'a_refresh_converges', 'a refresh re-reads the referrer'], [$above['accepted'][0]['test'], $above['accepted'][0]['method'], $above['accepted'][0]['reason']]);

        // Two lines above is another statement's comment; so is the line below.
        $far = GraphExtractorScan::scan(self::extractor("// verify:accept-cross-file-read " . self::TEST . " a refresh re-reads the referrer\n\n        \$x = is_file(\$path);\n        // verify:accept-cross-file-read " . self::TEST . ' a refresh re-reads the referrer'));
        self::assertSame([[19, 'is_file']], array_map(static fn (array $f): array => [$f['line'], $f['probe']], $far['findings']));
        // Another marker accepts nothing here, nor does prose that mentions this one.
        $other = GraphExtractorScan::scan(self::extractor('$x = is_file($path); // verify:accept-cross-file-reads ' . self::TEST . " a refresh re-reads the referrer\n        \$y = is_dir(\$path); // see verify:accept-cross-file-read " . self::TEST . ' a refresh re-reads the referrer'));
        self::assertCount(2, $other['findings']);
    }

    #[Test]
    public function an_accept_without_a_test_or_without_a_reason_is_a_problem_of_its_own(): void
    {
        $noTest = GraphExtractorScan::scan(self::extractor('$x = is_file($path); // verify:accept-cross-file-read the projection reads it later'));
        self::assertSame([], $noTest['accepted']);
        self::assertSame([['line' => 17, 'problem' => 'names no test: write it as `// verify:accept-cross-file-read <TestClass>::<testMethod> <reason>`']], $noTest['markers']);

        $bare = GraphExtractorScan::scan(self::extractor('$x = is_file($path); // verify:accept-cross-file-read'));
        self::assertSame([], $bare['accepted']);
        self::assertStringStartsWith('names no test', $bare['markers'][0]['problem']);

        // 19 characters is a word or two, not a reason: the floor of lint:shell-fallbacks.
        foreach ([self::TEST, self::TEST . '()', self::TEST . ' :', self::TEST . ' covered', self::TEST . ' — ' . str_repeat('я', 19)] as $marker) {
            $noReason = GraphExtractorScan::scan(self::extractor('$x = is_file($path); // verify:accept-cross-file-read ' . $marker));
            self::assertSame([], $noReason['accepted'], $marker);
            self::assertSame([['line' => 17, 'problem' => 'names ConvergenceTest::a_refresh_converges and gives no reason of 20 characters: say why this read is safe after the test name']], $noReason['markers'], $marker);
        }
        $enough = GraphExtractorScan::scan(self::extractor('$x = is_file($path); // verify:accept-cross-file-read ' . self::TEST . ' ' . str_repeat('я', 20)));
        self::assertSame([], $enough['markers']);
        self::assertSame(str_repeat('я', 20), $enough['accepted'][0]['reason']);
    }

    /** @return iterable<string, array{string, bool}> the lines before `{`, and whether they accept the probes of the class */
    public static function classLevel(): iterable
    {
        $marker = 'verify:accept-cross-file-read ' . self::TEST . ' read when the graph is read';
        yield 'the line above the class' => ["// {$marker}\nfinal class Reader", true];
        yield 'the line above a trait' => ["// {$marker}\ntrait Reader", true];
        yield 'the line above the first modifier' => ["// {$marker}\nfinal readonly class Reader", true];
        yield 'the last line of the docblock' => ["/**\n * Reads the disk.\n *\n * {$marker}\n */\nfinal class Reader", true];
        yield 'the docblock, with an attribute between' => ["/**\n * {$marker}\n */\n#[Service(names: ['a', 'b'])]\n#[Other]\nabstract class Reader", true];
        yield 'a one-line docblock' => ["/** {$marker} */\nclass Reader", true];
        yield 'a docblock line that is not the last' => ["/**\n * {$marker}\n * Reads the disk.\n */\nfinal class Reader", false];
        yield 'two lines above the class' => ["// {$marker}\n\nfinal class Reader", false];
        yield 'a docblock of something else' => ["/**\n * {$marker}\n */\nconst LIMIT = 1;\nfinal class Reader", false];
        yield 'no marker' => ["/** Reads the disk. */\nfinal class Reader", false];
    }

    #[Test]
    #[DataProvider('classLevel')]
    public function an_accept_on_a_class_covers_every_probe_in_it(string $declaration, bool $accepts): void
    {
        $source = "<?php\nnamespace App;\n\n{$declaration}\n{\n    public function a(string \$p): bool { return is_file(\$p); }\n\n    public function b(string \$p): array\n    {\n        return glob(\$p . '/*') ?: [];\n    }\n}\n\nfunction outside(string \$p): bool\n{\n    return is_dir(\$p);\n}\n";
        $scan = GraphExtractorScan::scan($source);

        $probes = static fn (array $rows): array => array_column($rows, 'probe');
        // The function after the class is outside it: a class-level accept never reaches it.
        self::assertSame($accepts ? ['is_dir'] : ['is_file', 'glob', 'is_dir'], $probes($scan['findings']));
        self::assertSame($accepts ? ['is_file', 'glob'] : [], $probes($scan['accepted']));
    }

    #[Test]
    public function a_class_level_accept_with_no_reason_is_one_problem_not_one_per_probe(): void
    {
        $scan = GraphExtractorScan::scan("<?php\n// verify:accept-cross-file-read " . self::TEST . "\nfinal class Reader\n{\n    public function a(string \$p): bool { return is_file(\$p) || is_dir(\$p); }\n}\n");

        self::assertSame([], $scan['findings']);
        self::assertSame([], $scan['accepted']);
        self::assertCount(1, $scan['markers']);
        self::assertSame(2, $scan['markers'][0]['line']);
    }

    #[Test]
    public function a_source_that_does_not_parse_is_refused_not_passed(): void
    {
        $this->expectException(\ParseError::class);

        GraphExtractorScan::scan("<?php\nfinal class {\n");
    }

    #[Test]
    public function an_accept_must_name_a_test_that_is_there(): void
    {
        $accept = static fn (string $test): string => self::extractor('$x = is_file($path); // verify:accept-cross-file-read ' . $test . ' the engine re-reads the referrer');
        $root = $this->project([
            GraphExtractorScan::DIRECTORY . '/Good.php' => $accept('ConvergenceTest::a_refresh_converges'),
            GraphExtractorScan::DIRECTORY . '/GoodByFullName.php' => $accept('Semitexa\ProjectGraph\Tests\Integration\ConvergenceTest::a_refresh_converges'),
            GraphExtractorScan::DIRECTORY . '/Ast/NoSuchClass.php' => $accept('MissingTest::a_refresh_converges'),
            GraphExtractorScan::DIRECTORY . '/Ast/NoSuchMethod.php' => $accept('ConvergenceTest::a_refresh_diverges'),
            GraphExtractorScan::DIRECTORY . '/Ast/WrongNamespace.php' => $accept('App\ConvergenceTest::a_refresh_converges'),
            GraphExtractorScan::DIRECTORY . '/Ast/OutsideTheTests.php' => $accept('ElsewhereTest::a_refresh_converges'),
            GraphExtractorScan::DIRECTORY . '/Ast/MentionedOnly.php' => $accept('MentionedTest::a_refresh_converges'),
            GraphExtractorScan::DIRECTORY . '/Clean.php' => self::extractor('$x = strlen($path);'),
            GraphExtractorScan::DIRECTORY . '/notes.txt' => 'is_file($path)',
            GraphExtractorScan::TESTS . '/Integration/ConvergenceTest.php' => "<?php\nnamespace Semitexa\\ProjectGraph\\Tests\\Integration;\nfinal class ConvergenceTest\n{\n    public function a_refresh_converges(): void {}\n}\n",
            // A file of that name that only mentions the class, and a method named in a string.
            GraphExtractorScan::TESTS . '/Unit/MentionedTest.php' => "<?php\n\$x = MentionedTest::class;\n\$y = 'function a_refresh_converges';\n",
            'packages/semitexa-project-graph/src/ElsewhereTest.php' => "<?php\nfinal class ElsewhereTest\n{\n    public function a_refresh_converges(): void {}\n}\n",
        ]);

        $problems = GraphExtractorScan::problems($root);

        $dir = GraphExtractorScan::DIRECTORY;
        self::assertSame([
            "{$dir}/Ast/MentionedOnly.php" => ['line 17: `is_file` accepted with `verify:accept-cross-file-read` names the test class MentionedTest, and no class of that name is declared under packages/semitexa-project-graph/tests'],
            "{$dir}/Ast/NoSuchClass.php" => ['line 17: `is_file` accepted with `verify:accept-cross-file-read` names the test class MissingTest, and no class of that name is declared under packages/semitexa-project-graph/tests'],
            "{$dir}/Ast/NoSuchMethod.php" => ['line 17: `is_file` accepted with `verify:accept-cross-file-read` names ConvergenceTest::a_refresh_diverges, and packages/semitexa-project-graph/tests/Integration/ConvergenceTest.php declares no method a_refresh_diverges'],
            "{$dir}/Ast/OutsideTheTests.php" => ['line 17: `is_file` accepted with `verify:accept-cross-file-read` names the test class ElsewhereTest, and no class of that name is declared under packages/semitexa-project-graph/tests'],
            "{$dir}/Ast/WrongNamespace.php" => ['line 17: `is_file` accepted with `verify:accept-cross-file-read` names the test class App\ConvergenceTest, and no class of that name is declared under packages/semitexa-project-graph/tests'],
        ], $problems);
    }

    #[Test]
    public function the_problems_of_a_project_say_what_to_do(): void
    {
        $root = $this->project([
            GraphExtractorScan::DIRECTORY . '/Attribute/Probing.php' => self::extractor("\$x = is_file(\$path);\n        \$y = new \$class(); // verify:accept-cross-file-read some day"),
            GraphExtractorScan::DIRECTORY . '/Broken.php' => "<?php\nfinal class {\n",
            'src/Other.php' => self::extractor('$x = is_dir($path);'),
        ]);
        $dir = GraphExtractorScan::DIRECTORY;

        $problems = GraphExtractorScan::problems($root);
        self::assertSame(["{$dir}/Attribute/Probing.php", "{$dir}/Broken.php"], array_keys($problems), 'only the extractor directory is walked, subdirectories included');
        self::assertSame([
            'line 17: `is_file` reads the filesystem; a refresh re-reads only the files that changed, so a fact that depends on another file goes stale. Resolve it where the graph is read, or accept it with `// verify:accept-cross-file-read <TestClass>::<testMethod> <reason>`, naming the convergence test that covers it',
            'line 18: `verify:accept-cross-file-read` names no test: write it as `// verify:accept-cross-file-read <TestClass>::<testMethod> <reason>`',
        ], $problems["{$dir}/Attribute/Probing.php"]);
        self::assertStringStartsWith('could not be parsed (line 2: ', $problems["{$dir}/Broken.php"][0]);
        self::assertStringEndsWith('the lint did not check it', $problems["{$dir}/Broken.php"][0]);

        // A given file is read wherever it lies, and alone; --all reads the directory beside it.
        self::assertSame(['src/Other.php'], array_keys(GraphExtractorScan::problems($root, ['src/Other.php'])));
        self::assertSame(["{$dir}/Attribute/Probing.php", "{$dir}/Broken.php", 'src/Other.php'], array_keys(GraphExtractorScan::problems($root, ['src/Other.php'], true)));
        self::assertSame(['(empty --file)' => ['no path was given: the lint checked nothing for it']], GraphExtractorScan::problems($root, ['']));
        self::assertSame(['src/Gone.php' => ['cannot be read: the lint did not check it']], GraphExtractorScan::problems($root, ['src/Gone.php']));
        self::assertSame([], GraphExtractorScan::problems($root, ['composer.json']), 'a file that is no PHP has nothing to find');
    }

    #[Test]
    public function a_project_without_the_extractors_passes_and_can_say_so(): void
    {
        $root = $this->project(['src/Other.php' => self::extractor('$x = is_dir($path);')]);

        self::assertFalse(GraphExtractorScan::hasExtractors($root));
        self::assertSame([], GraphExtractorScan::problems($root));
        self::assertSame([], GraphExtractorScan::problems($root, [], true));
    }

    #[Test]
    public function only_a_php_file_under_the_extractor_directory_is_covered(): void
    {
        $dir = GraphExtractorScan::DIRECTORY;

        self::assertTrue(GraphExtractorScan::covers("{$dir}/TemplateRenderExtractor.php"));
        self::assertTrue(GraphExtractorScan::covers("{$dir}/Attribute/UiNavigationExtractor.php"));
        self::assertFalse(GraphExtractorScan::covers("{$dir}/README.md"));
        self::assertFalse(GraphExtractorScan::covers("{$dir}Other/A.php"));
        self::assertFalse(GraphExtractorScan::covers('packages/semitexa-project-graph/src/Application/Service/Index/IncrementalEngine.php'));
        self::assertFalse(GraphExtractorScan::covers('packages/semitexa-project-graph/tests/Unit/Extractor/SomeTest.php'));
        self::assertFalse(GraphExtractorScan::covers("vendor/acme/{$dir}/A.php"));
    }

    /**
     * An extractor with the imports the cases name; `$code` starts on line 17.
     *
     * @param bool $shadowed import lstat, scandir, is_link and stat from a namespace and declare filesize in this one: none is then the global function
     */
    private static function extractor(string $code, bool $shadowed = false): string
    {
        [$group, $link, $stat, $size] = $shadowed
            ? ['function lstat, function scandir, Edge', 'is_link', 'status as stat', 'filesize']
            : ['Edge', 'linked', 'status', 'size'];

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Extractor;

            use App\Paths\{{$group}};
            use ReflectionMethod, SplFileInfo;
            use RecursiveDirectoryIterator as Walk, Symfony\\Component\\Finder\\Finder as Files;
            use Symfony\Component;
            use Symfony\Component\Finder\Finder;
            use function App\Paths\{$link};
            use function App\Paths\{$stat};
            use function is_readable, filemtime, realpath;

            function extract(string \$path, object \$o, string \$class, string \$name, array \$arguments, array \$paths, object \$files): mixed {
                    {$code}
                return \$x;
            }

            function {$size}(string \$path): int
            {
                return 0;
            }

            PHP;
    }

    /** @return list<array{int, string}> */
    private static function flagged(string $source): array
    {
        return array_map(static fn (array $f): array => [$f['line'], $f['probe']], GraphExtractorScan::scan($source)['findings']);
    }

    /** @param array<string, string> $files */
    private function project(array $files): string
    {
        $this->root = sys_get_temp_dir() . '/semitexa-graph-extractors-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        file_put_contents($this->root . '/composer.json', '{}');
        foreach ($files as $path => $content) {
            @mkdir(dirname($this->root . '/' . $path), 0777, true);
            file_put_contents($this->root . '/' . $path, $content);
        }

        return $this->root;
    }
}

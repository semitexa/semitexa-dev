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

    /** An accept of a filesystem read, as far as its reason. */
    private const ACCEPT = 'verify:accept-cross-file-read filesystem ' . self::TEST;

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
        foreach (['is_writable', 'readfile', 'filectime', 'simplexml_load_file'] as $function) {
            yield $function => ["\$x = {$function}(\$path);", $function, CrossFileProbes::FILESYSTEM];
        }
        yield 'a global function under an alias' => ['$x = there($path);', 'there (is_file)', CrossFileProbes::FILESYSTEM];
        yield 'a string for call_user_func' => ['$x = call_user_func("is_file", $path);', "call_user_func('is_file')", CrossFileProbes::FILESYSTEM];
        yield 'a string for call_user_func_array' => ['$x = \call_user_func_array(\'\\\\class_exists\', [$class]);', "call_user_func_array('class_exists')", CrossFileProbes::CLASS_PROBE];
        yield 'a string for array_map' => ['$x = array_map("realpath", $paths);', "array_map('realpath')", CrossFileProbes::FILESYSTEM];
        yield 'a callback given by name' => ['$x = array_map(array: $paths, callback: "is_file");', "array_map('is_file')", CrossFileProbes::FILESYSTEM];
        yield 'a callback given by name, first' => ['$x = call_user_func(callback: \'is_dir\', args: $path);', "call_user_func('is_dir')", CrossFileProbes::FILESYSTEM];
        yield 'constant() with its name given by name' => ['$x = constant(name: "App\\\\Routes::LIST");', 'constant', CrossFileProbes::CLASS_PROBE];
        yield 'a callback spelled with a hexadecimal escape' => ['$x = array_map("is_\x66ile", $paths);', "array_map('is_file')", CrossFileProbes::FILESYSTEM];
        yield 'a callback spelled with an octal and a unicode escape' => ['$x = array_map("\151s_\u{64}ir", $paths);', "array_map('is_dir')", CrossFileProbes::FILESYSTEM];
        yield 'a callback with a leading backslash in single quotes' => ['$x = array_map(\'\\\\is_file\', $paths);', "array_map('is_file')", CrossFileProbes::FILESYSTEM];
        yield 'a static call on a reflection class' => ['$x = \ReflectionMethod::createFromMethodName($name);', 'ReflectionMethod::createFromMethodName', CrossFileProbes::REFLECTION];
        yield 'a static call on an imported reflection class' => ['$x = ReflectionMethod::createFromMethodName($name);', 'ReflectionMethod::createFromMethodName', CrossFileProbes::REFLECTION];
        yield 'constant() of a class constant' => ['$x = constant("App\\\\Routes::LIST");', 'constant', CrossFileProbes::CLASS_PROBE];
        yield 'constant() of a name built at run time' => ['$x = constant($class . "::" . $name);', 'constant', CrossFileProbes::CLASS_PROBE];
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
        self::assertSame(['line' => 18, 'probe' => $probe, 'kind' => $kind], $scan['findings'][0], $code);
    }

    /** @return iterable<string, array{string}> */
    public static function harmless(): iterable
    {
        yield 'a method of the same name' => ['$x = $files->glob("*.twig"); $y = $files?->is_file($path); $z = $this->file($path);'];
        yield 'a static method of the same name' => ['$x = Paths::realpath($path); $y = self::is_dir($path); $z = static::file($path);'];
        yield 'a string naming the function' => ['$x = usort($paths, "realpath"); $y = "is_file($path)"; $z = \'class_exists\'; $w = array_map("strlen", $paths); $v = array_map($name, $paths);'];
        yield 'escapes single quotes do not have, and a callback that is another argument' => ['$x = array_map(\'is_\x66ile\', $paths); $y = array_map(array: "is_file", callback: "strlen"); $z = usort(callback: "is_file", array: $paths);'];
        yield 'constant() of a plain global name, and a class constant of a reflection class' => ['$x = constant("PHP_INT_MAX") + constant(\'E_ALL\') + \ReflectionMethod::IS_PUBLIC; $y = ReflectionMethod::class;'];
        yield 'a function called through a variable, a static call on a class named at run time, unserialize: known limits' => ['$f = "is_file"; $x = $f($path); $y = $class::from($name); $z = unserialize($path);'];
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
            [[18, 'is_file'], [19, 'new ReflectionClass'], [20, 'require_once']],
            self::flagged(self::extractor("\$a = is_file(\$p);\n        \$b = new \\ReflectionClass(\$o);\n        \$c = require_once \$p;")),
        );
    }

    #[Test]
    public function an_accept_on_the_line_or_above_the_statement_names_the_kind_and_the_test(): void
    {
        $onTheLine = GraphExtractorScan::scan(self::extractor('$x = is_file($path); // ' . self::ACCEPT . ' the engine re-reads this file when the target changes'));
        self::assertSame([], $onTheLine['findings']);
        self::assertSame([], $onTheLine['markers']);
        self::assertSame([['line' => 18, 'probe' => 'is_file', 'kind' => CrossFileProbes::FILESYSTEM, 'test' => 'ConvergenceTest', 'method' => 'a_refresh_converges', 'reason' => 'the engine re-reads this file when the target changes']], $onTheLine['accepted']);

        $above = GraphExtractorScan::scan(self::extractor("// verify:accept-cross-file-read filesystem \\App\\Tests\\ConvergenceTest::a_refresh_converges() — a refresh re-reads the referrer\n        \$x = is_file(\$path);"));
        self::assertSame([], $above['findings']);
        self::assertSame(['App\Tests\ConvergenceTest', 'a_refresh_converges', 'a refresh re-reads the referrer'], [$above['accepted'][0]['test'], $above['accepted'][0]['method'], $above['accepted'][0]['reason']]);

        // The statement may start further down and run over several lines; the marker below it accepts nothing and says so.
        $far = GraphExtractorScan::scan(self::extractor("// " . self::ACCEPT . " a refresh re-reads the referrer\n\n        \$x = strlen(\n            (string) is_file(\$path),\n        );\n        // " . self::ACCEPT . ' a refresh re-reads the referrer'));
        self::assertSame([], $far['findings']);
        self::assertSame([[21, 'is_file']], array_map(static fn (array $f): array => [$f['line'], $f['probe']], $far['accepted']));
        self::assertSame([23], array_column($far['markers'], 'line'));
        // Another marker accepts nothing here, nor does prose that mentions this one.
        $other = GraphExtractorScan::scan(self::extractor('$x = is_file($path); // verify:accept-cross-file-reads filesystem ' . self::TEST . " a refresh re-reads the referrer\n        \$y = is_dir(\$path); // see " . self::ACCEPT . ' a refresh re-reads the referrer'));
        self::assertCount(2, $other['findings']);
        self::assertSame([], $other['markers']);
    }

    /** Review of f2808af: a trailing marker also accepted the read on the next line. */
    #[Test]
    public function a_trailing_accept_does_not_reach_the_next_line(): void
    {
        $scan = GraphExtractorScan::scan(self::extractor('$x = is_file($path); // ' . self::ACCEPT . " a refresh re-reads the referrer\n        \$y = is_dir(\$path);"));

        self::assertSame([[18, 'is_file']], array_map(static fn (array $f): array => [$f['line'], $f['probe']], $scan['accepted']));
        self::assertSame([[19, 'is_dir']], array_map(static fn (array $f): array => [$f['line'], $f['probe']], $scan['findings']));

        // Nor does an accept above a statement reach into the body of the closure it starts: the read is refused, and the accept accepts nothing.
        $closure = GraphExtractorScan::scan(self::extractor('// ' . self::ACCEPT . " a refresh re-reads the referrer\n        \$x = static function () use (\$path) {\n            return is_file(\$path);\n        };"));
        self::assertSame(['is_file'], array_column($closure['findings'], 'probe'));
        self::assertSame([18], array_column($closure['markers'], 'line'));
    }

    /** @return iterable<string, array{string, list<string>, list<string>}> code from line 18, the probes refused, the probes accepted */
    public static function oneReadSite(): iterable
    {
        $accept = '// ' . self::ACCEPT . ' a refresh re-reads the referrer';
        yield 'the statement after the one accepted' => ["{$accept}\n        \$x = is_file(\$path);\n        \$y = is_dir(\$path);", ['is_dir'], ['is_file']];
        yield 'the block a statement opens' => ["{$accept}\n        if (is_file(\$path)) {\n            \$x = glob(\$path);\n        }", ['glob'], ['is_file']];
        yield 'the next element of a list' => ["\$x = [\n            {$accept}\n            is_file(\$path),\n            is_dir(\$path),\n        ];", ['is_dir'], ['is_file']];
        yield 'the next argument of a call' => ["\$x = max(\n            {$accept}\n            (int) is_file(\$path),\n            (int) is_dir(\$path),\n        );", ['is_dir'], ['is_file']];
        yield 'the body of a closure among the arguments of the statement' => ["{$accept}\n        \$x = max(is_dir(\$path), run(static function () use (\$path) {\n            return is_file(\$path);\n        }));", ['is_file'], ['is_dir']];
        yield 'a call over several lines, with no block' => ["{$accept}\n        \$x = max(\n            1,\n            (int) is_file(\$path),\n            fn (): bool => is_dir(\$path),\n        );", [], ['is_file', 'is_dir']];
        yield 'a statement with a comma, in a closure that is an argument' => ["\$x = array_map(static function (string \$p) {\n            {$accept}\n            echo '', is_file(\$p);\n            return is_dir(\$p);\n        }, \$paths);", ['is_dir'], ['is_file']];
        yield 'a read of a kind the accept does not name' => ["{$accept}\n        \$x = is_file(\$path) ? new \\ReflectionClass(\$o) : require \$path;", ['new ReflectionClass', 'require'], ['is_file']];
        yield 'a read of another kind on the line of a trailing accept' => ["\$x = is_file(\$path) && class_exists(\$class); {$accept}", ['class_exists'], ['is_file']];
        yield 'two kinds named' => ["// verify:accept-cross-file-read filesystem, class-probe " . self::TEST . " a refresh re-reads the referrer\n        \$x = is_file(\$path) && class_exists(\$class) ? new \$class() : null;", ['new $class'], ['is_file', 'class_exists']];
        yield 'two accepts above one statement' => ["{$accept}\n        // verify:accept-cross-file-read reflection " . self::TEST . " the class is PHP's own\n        \$x = is_file(\$path) ? new \\ReflectionClass(\$o) : null;", [], ['is_file', 'new ReflectionClass']];
    }

    /**
     * Review of f2808af: one marker above a class accepted glob, include and new ReflectionClass together.
     *
     * @param list<string> $refused
     * @param list<string> $accepted
     */
    #[Test]
    #[DataProvider('oneReadSite')]
    public function an_accept_covers_one_read_site_and_the_kinds_it_names(string $code, array $refused, array $accepted): void
    {
        $scan = GraphExtractorScan::scan(self::extractor($code));

        self::assertSame($refused, array_column($scan['findings'], 'probe'));
        self::assertSame($accepted, array_column($scan['accepted'], 'probe'));
        self::assertSame([], $scan['markers']);
    }

    /** @return iterable<string, array{string}> the lines before `{` */
    public static function classLevel(): iterable
    {
        $marker = self::ACCEPT . ' read when the graph is read';
        yield 'the line above the class' => ["// {$marker}\nfinal class Reader"];
        yield 'the last line of the docblock' => ["/**\n * Reads the disk.\n *\n * {$marker}\n */\nfinal class Reader"];
        yield 'the docblock, with an attribute between' => ["/**\n * {$marker}\n */\n#[Service(names: ['a', 'b'])]\nabstract class Reader"];
        yield 'a one-line docblock' => ["/** {$marker} */\nclass Reader"];
    }

    #[Test]
    #[DataProvider('classLevel')]
    public function an_accept_on_a_class_accepts_nothing_in_it_and_is_a_problem(string $declaration): void
    {
        $scan = GraphExtractorScan::scan("<?php\nnamespace App;\n\n{$declaration}\n{\n    public function a(string \$p): bool { return is_file(\$p); }\n\n    public function b(string \$p): array\n    {\n        return glob(\$p . '/*') ?: [];\n    }\n}\n");

        self::assertSame(['is_file', 'glob'], array_column($scan['findings'], 'probe'));
        self::assertSame([], $scan['accepted']);
        self::assertSame(['accepts a filesystem read, and none stands in the statement below it: an accept covers one read site. Remove it, or move it to the read'], array_column($scan['markers'], 'problem'));
    }

    /** Review of f2808af: an accept stayed when its read was gone, and read as a reviewed exception. */
    #[Test]
    public function an_accept_with_no_read_of_its_kind_is_a_problem(): void
    {
        $trailing = GraphExtractorScan::scan(self::extractor('$x = strlen($path); // ' . self::ACCEPT . ' a refresh re-reads the referrer'));
        self::assertSame([['line' => 18, 'problem' => 'accepts a filesystem read, and none stands on its line: an accept covers one read site. Remove it, or move it to the read']], $trailing['markers']);

        // One of two kinds is there: the other is named for nothing.
        $half = GraphExtractorScan::scan(self::extractor("// verify:accept-cross-file-read filesystem,reflection,include " . self::TEST . " a refresh re-reads the referrer\n        \$x = is_file(\$path);"));
        self::assertSame(['is_file'], array_column($half['accepted'], 'probe'));
        self::assertSame([['line' => 18, 'problem' => 'accepts a reflection, include read, and none stands in the statement below it: an accept covers one read site. Remove it, or move it to the read']], $half['markers']);
    }

    #[Test]
    public function an_accept_without_a_kind_a_test_or_a_reason_is_a_problem_of_its_own(): void
    {
        $form = '`// verify:accept-cross-file-read <kind>[,<kind>] <TestClass>::<testMethod> <reason>`';
        $noTest = GraphExtractorScan::scan(self::extractor('$x = is_file($path); // verify:accept-cross-file-read the projection reads it later'));
        self::assertSame([], $noTest['accepted']);
        self::assertSame([], $noTest['findings'], 'the marker is the problem; the read it stands at is not reported twice');
        self::assertSame([['line' => 18, 'problem' => "names no test: write it as {$form}"]], $noTest['markers']);

        $bare = GraphExtractorScan::scan(self::extractor('$x = is_file($path); // verify:accept-cross-file-read'));
        self::assertSame([], $bare['accepted']);
        self::assertStringStartsWith('names no test', $bare['markers'][0]['problem']);

        // The form before the review of f2808af: a test and a reason, for whatever stands there.
        $noKind = GraphExtractorScan::scan(self::extractor('$x = is_file($path); // verify:accept-cross-file-read ' . self::TEST . ' a refresh re-reads the referrer'));
        self::assertSame([], $noKind['accepted']);
        self::assertSame([['line' => 18, 'problem' => "names no kind of read: write it as {$form}, where a kind is one of filesystem, include, reflection, class-probe, dynamic-new"]], $noKind['markers']);
        $unknown = GraphExtractorScan::scan(self::extractor('$x = is_file($path); // verify:accept-cross-file-read filesystem,disk ' . self::TEST . ' a refresh re-reads the referrer'));
        self::assertSame([], $unknown['accepted']);
        self::assertStringStartsWith('names no kind of read this lint knows (disk): ', $unknown['markers'][0]['problem']);

        // 19 characters is a word or two, not a reason: the floor of lint:shell-fallbacks.
        foreach ([self::TEST, self::TEST . '()', self::TEST . ' :', self::TEST . ' covered', self::TEST . ' — ' . str_repeat('я', 19)] as $marker) {
            $noReason = GraphExtractorScan::scan(self::extractor('$x = is_file($path); // verify:accept-cross-file-read filesystem ' . $marker));
            self::assertSame([], $noReason['accepted'], $marker);
            self::assertSame([['line' => 18, 'problem' => 'names ConvergenceTest::a_refresh_converges and gives no reason of 20 characters: say why this read is safe after the test name']], $noReason['markers'], $marker);
        }
        $enough = GraphExtractorScan::scan(self::extractor('$x = is_file($path); // ' . self::ACCEPT . ' ' . str_repeat('я', 20)));
        self::assertSame([], $enough['markers']);
        self::assertSame(str_repeat('я', 20), $enough['accepted'][0]['reason']);
    }

    #[Test]
    public function a_source_that_does_not_parse_is_refused_not_passed(): void
    {
        $this->expectException(\ParseError::class);

        GraphExtractorScan::scan("<?php\nfinal class {\n");
    }

    #[Test]
    public function an_accept_must_name_a_public_test_the_class_declares(): void
    {
        $accept = static fn (string $test): string => self::extractor('$x = is_file($path); // verify:accept-cross-file-read filesystem ' . $test . ' the engine re-reads the referrer');
        $dir = GraphExtractorScan::DIRECTORY;
        $tests = GraphExtractorScan::TESTS;
        $good = ['a_refresh_converges', 'testARefreshConverges', 'grouped', 'aliased', 'byFullName'];
        $bad = [
            'a_refresh_diverges' => "{$tests}/Integration/ConvergenceTest.php declares no method a_refresh_diverges",
            // Review, semitexa-dev#316: the method of another class of the file passed for this one's.
            'elsewhere' => "{$tests}/Integration/ConvergenceTest.php declares no method elsewhere",
            'fixture' => "in {$tests}/Integration/ConvergenceTest.php that method is no test: it carries no #[Test] of PHPUnit and its name does not start with `test`",
            'edits' => "in {$tests}/Integration/ConvergenceTest.php that method is no test: it carries no #[Test] of PHPUnit and its name does not start with `test`",
            // Review, semitexa-dev#316: any attribute called Test counted, and PHPUnit's under an alias did not.
            'unrelated' => "in {$tests}/Integration/ConvergenceTest.php that method is no test: it carries no #[Test] of PHPUnit and its name does not start with `test`",
            'local' => "in {$tests}/Integration/ConvergenceTest.php that method is no test: it carries no #[Test] of PHPUnit and its name does not start with `test`",
            // Review, semitexa-dev#316: PHPUnit runs only public methods.
            'hidden' => "in {$tests}/Integration/ConvergenceTest.php that method is not public: PHPUnit runs only a public method",
            'testHidden' => "in {$tests}/Integration/ConvergenceTest.php that method is not public: PHPUnit runs only a public method",
        ];
        $files = [];
        foreach ([...$good, ...array_keys($bad)] as $method) {
            $files["{$dir}/M_{$method}.php"] = $accept("ConvergenceTest::{$method}");
        }
        $root = $this->project($files + [
            "{$dir}/GoodByFullName.php" => $accept('Semitexa\ProjectGraph\Tests\Integration\ConvergenceTest::a_refresh_converges'),
            "{$dir}/Ast/NoSuchClass.php" => $accept('MissingTest::a_refresh_converges'),
            "{$dir}/Ast/WrongNamespace.php" => $accept('App\ConvergenceTest::a_refresh_converges'),
            "{$dir}/Ast/OutsideTheTests.php" => $accept('ElsewhereTest::a_refresh_converges'),
            "{$dir}/Ast/MentionedOnly.php" => $accept('MentionedTest::a_refresh_converges'),
            "{$dir}/Clean.php" => self::extractor('$x = strlen($path);'),
            "{$dir}/notes.txt" => 'is_file($path)',
            "{$tests}/Integration/ConvergenceTest.php" => <<<'PHP'
                <?php
                namespace Semitexa\ProjectGraph\Tests\Integration;

                use PHPUnit\Framework\Attributes\Test;
                use PHPUnit\Framework\Attributes\{DataProvider, Test as VerifyCase};
                use PHPUnit\Framework;

                final class Other
                {
                    #[Test]
                    public function elsewhere(): void {}
                }

                final class ConvergenceTest
                {
                    private int $runs = 0;

                    #[Test]
                    #[DataProvider('edits')]
                    public function a_refresh_converges(): void {}
                    public function testARefreshConverges(): void { $x = new class { private function hidden(): void {} }; }
                    #[Group('slow'), \PHPUnit\Framework\Attributes\Test]
                    public function grouped(): void {}
                    #[VerifyCase]
                    public function aliased(): void {}
                    #[Framework\Attributes\Test]
                    public function byFullName(): void {}
                    private function fixture(): void {}
                    #[Covers(Test::class, 'Test')]
                    public static function edits(): array { return []; }
                    #[\App\Attributes\Test]
                    public function unrelated(): void {}
                    #[Attributes\Test]
                    public function local(): void {}
                    #[Test]
                    private function hidden(): void {}
                    protected function testHidden(): void {}
                }
                PHP,
            // A file of that name that only mentions the class, and a method named in a string.
            "{$tests}/Unit/MentionedTest.php" => "<?php\n\$x = MentionedTest::class;\n\$y = 'function a_refresh_converges';\n",
            GraphExtractorScan::PACKAGE . '/src/ElsewhereTest.php' => "<?php\nfinal class ElsewhereTest\n{\n    public function testIt(): void {}\n}\n",
        ]);

        $expected = [
            "{$dir}/Ast/MentionedOnly.php" => ["names the test class MentionedTest, and no class of that name is declared under {$tests}"],
            "{$dir}/Ast/NoSuchClass.php" => ["names the test class MissingTest, and no class of that name is declared under {$tests}"],
            "{$dir}/Ast/OutsideTheTests.php" => ["names the test class ElsewhereTest, and no class of that name is declared under {$tests}"],
            "{$dir}/Ast/WrongNamespace.php" => ["names the test class App\ConvergenceTest, and no class of that name is declared under {$tests}"],
        ];
        foreach ($bad as $method => $problem) {
            $expected["{$dir}/M_{$method}.php"] = ["names ConvergenceTest::{$method}, and {$problem}"];
        }
        ksort($expected);
        $found = [];
        foreach (GraphExtractorScan::problems($root) as $file => $problems) {
            $found[$file] = array_map(static fn (string $problem): string => str_replace('line 18: `is_file` accepted with `verify:accept-cross-file-read` ', '', $problem), $problems);
        }
        self::assertSame($expected, $found);
    }

    /** Review, semitexa-dev#316: a candidate that could not be read was skipped, and another file of that name cleared the accept. */
    #[Test]
    public function a_test_file_or_a_source_that_cannot_be_read_is_a_problem_not_a_file_with_nothing_in_it(): void
    {
        // A link to nothing is a file the directory lists and nobody can read, root included: no mode to change, nothing to skip.
        $dir = GraphExtractorScan::DIRECTORY;
        $tests = GraphExtractorScan::TESTS;
        $test = "<?php\nfinal class ConvergenceTest\n{\n    public function testIt(): void {}\n}\n";
        $root = $this->project([
            "{$dir}/Accepted.php" => self::extractor('$x = is_file($path); // verify:accept-cross-file-read filesystem ConvergenceTest::testIt the engine re-reads the referrer'),
            "{$dir}/Clean.php" => self::extractor('$x = strlen($path);'),
            "{$tests}/Integration/ConvergenceTest.php" => $test,
            "{$tests}/Unit/ConvergenceTest.php" => $test,
        ]);
        self::assertSame([], GraphExtractorScan::problems($root));

        unlink("{$root}/{$tests}/Integration/ConvergenceTest.php");
        symlink("{$root}/gone/ConvergenceTest.php", "{$root}/{$tests}/Integration/ConvergenceTest.php");
        self::assertSame(
            ["{$dir}/Accepted.php" => ["line 18: `is_file` accepted with `verify:accept-cross-file-read` names ConvergenceTest::testIt, and {$tests}/Integration/ConvergenceTest.php, which may declare it, cannot be read: the lint did not check the test"]],
            GraphExtractorScan::problems($root),
        );
        unlink("{$root}/{$tests}/Integration/ConvergenceTest.php");
        self::assertSame([], GraphExtractorScan::problems($root), 'the other file of that name declares it');

        // A source that discovery cannot read is reported whatever was asked for: what it imports was not followed.
        symlink("{$root}/gone/Closed.php", "{$root}/{$dir}/Closed.php");
        $closed = ["{$dir}/Closed.php" => ['cannot be read: the lint did not check it']];
        self::assertSame($closed, GraphExtractorScan::problems($root, ["{$dir}/Clean.php"]));
        self::assertSame($closed, GraphExtractorScan::problems($root));
        self::assertSame($closed, GraphExtractorScan::problems($root, ['src/NotOfThePackage.txt']));
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
            'line 18: `is_file` reads the filesystem; a refresh re-reads only the files that changed, so a fact that depends on another file goes stale. Resolve it where the graph is read, or accept it with `// verify:accept-cross-file-read filesystem <TestClass>::<testMethod> <reason>`, naming the convergence test that covers it',
            'line 19: `verify:accept-cross-file-read` names no test: write it as `// verify:accept-cross-file-read <kind>[,<kind>] <TestClass>::<testMethod> <reason>`',
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

        self::assertFalse(GraphExtractorScan::hasSources($root));
        self::assertSame([], GraphExtractorScan::problems($root));
        self::assertSame([], GraphExtractorScan::problems($root, [], true));
    }

    /**
     * Review of f2808af: ReferenceExtractor's reads went through Parser\ClassDeclarationReader,
     * outside the extractor directory, and the lint saw none of them.
     */
    #[Test]
    public function a_read_in_a_helper_an_extractor_reaches_is_the_extractors_read(): void
    {
        $src = GraphExtractorScan::PACKAGE . '/src';
        $helper = static fn (string $namespace, string $class, string $uses, string $body): string => "<?php\nnamespace Semitexa\\ProjectGraph\\{$namespace};\n{$uses}\nfinal class {$class}\n{\n    public function read(string \$p): mixed { return {$body}; }\n}\n";
        $root = $this->project([
            GraphExtractorScan::DIRECTORY . '/Ast/ReferenceExtractor.php' => $helper('Application\Service\Extractor\Ast', 'ReferenceExtractor', "use Semitexa\\ProjectGraph\\Application\\Service\\Graph\\{NodeId, EdgeType as Type};\nuse Semitexa\\ProjectGraph\\Missing\\Gone;\nuse App\\Other\\Thing;", 'strlen($p)'),
            // The parser's directory is read whole, imported or not.
            "{$src}/Application/Service/Parser/ClassDeclarationReader.php" => $helper('Application\Service\Parser', 'ClassDeclarationReader', '', 'is_file($p)'),
            // Imported in a group, and under an alias.
            "{$src}/Application/Service/Graph/NodeId.php" => $helper('Application\Service\Graph', 'NodeId', 'use Semitexa\ProjectGraph\Domain\Model\Edge;', 'strlen($p)'),
            "{$src}/Application/Service/Graph/EdgeType.php" => $helper('Application\Service\Graph', 'EdgeType', '', 'class_exists($p)'),
            // Imported by what an extractor imports.
            "{$src}/Domain/Model/Edge.php" => $helper('Domain\Model', 'Edge', '', 'new \ReflectionClass($p)'),
            // Imported by nothing an extractor runs: the engine, a query.
            "{$src}/Application/Service/Index/IncrementalEngine.php" => $helper('Application\Service\Index', 'IncrementalEngine', 'use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;', 'is_dir($p)'),
            // Review, semitexa-dev#316: an import inside `namespace X { }` is indented, and was not seen. A trait's `use` and a closure's are no imports.
            GraphExtractorScan::DIRECTORY . '/Braced.php' => "<?php\nnamespace Semitexa\\ProjectGraph\\Application\\Service\\Extractor {\n    use Semitexa\\ProjectGraph\\Application\\Service\\Coverage\\Gap;\n    use function Semitexa\\ProjectGraph\\Application\\Service\\Index\\IncrementalEngine;\n    final class Braced\n    {\n        use \\Semitexa\\ProjectGraph\\Application\\Service\\Index\\IncrementalEngine;\n        public function read(string \$p): mixed { return static function () use (\$p) { return strlen(\$p); }; }\n    }\n}\n",
            "{$src}/Application/Service/Coverage/Gap.php" => $helper('Application\Service\Coverage', 'Gap', '', 'glob($p)'),
            'src/Other.php' => self::extractor('$x = is_dir($path);'),
        ]);

        self::assertSame([
            "{$src}/Application/Service/Coverage/Gap.php",
            GraphExtractorScan::DIRECTORY . '/Ast/ReferenceExtractor.php',
            GraphExtractorScan::DIRECTORY . '/Braced.php',
            "{$src}/Application/Service/Graph/EdgeType.php",
            "{$src}/Application/Service/Graph/NodeId.php",
            "{$src}/Application/Service/Parser/ClassDeclarationReader.php",
            "{$src}/Domain/Model/Edge.php",
        ], GraphExtractorScan::sources($root));
        $probes = [];
        foreach (GraphExtractorScan::problems($root) as $file => $found) {
            $probes[$file] = (string) preg_replace('~^line \d+: `([^`]+)`.*$~s', '$1', $found[0]);
        }
        self::assertSame([
            "{$src}/Application/Service/Coverage/Gap.php" => 'glob',
            "{$src}/Application/Service/Graph/EdgeType.php" => 'class_exists',
            "{$src}/Application/Service/Parser/ClassDeclarationReader.php" => 'is_file',
            "{$src}/Domain/Model/Edge.php" => 'new ReflectionClass',
        ], $probes);

        // Review, semitexa-dev#316: a changed extractor is read with the helpers it reaches, which its change may have
        // added; nothing else of the project is, and a given file outside the package is read alone.
        $extractor = GraphExtractorScan::DIRECTORY . '/Ast/ReferenceExtractor.php';
        self::assertSame(["{$src}/Application/Service/Graph/EdgeType.php", "{$src}/Domain/Model/Edge.php"], array_keys(GraphExtractorScan::problems($root, [$extractor])));
        self::assertSame(["{$src}/Domain/Model/Edge.php"], array_keys(GraphExtractorScan::problems($root, ["{$src}/Application/Service/Graph/NodeId.php"])));
        self::assertSame(['src/Other.php'], array_keys(GraphExtractorScan::problems($root, ['src/Other.php'])));
    }

    /**
     * Review of f2808af: an accept names a test, and nothing read the accepts again when that test
     * changed. A file of the package that no extractor runs is not read itself: every extractor is.
     */
    #[Test]
    public function a_changed_test_or_another_class_of_the_package_reads_every_extractor(): void
    {
        $dir = GraphExtractorScan::DIRECTORY;
        $test = GraphExtractorScan::TESTS . '/Integration/ConvergenceTest.php';
        $engine = GraphExtractorScan::PACKAGE . '/src/Application/Service/Index/IncrementalEngine.php';
        $root = $this->project([
            "{$dir}/Accepted.php" => self::extractor('$x = is_file($path); // ' . self::ACCEPT . ' the engine re-reads the referrer'),
            "{$dir}/Clean.php" => self::extractor('$x = strlen($path);'),
            $test => "<?php\nuse PHPUnit\\Framework\\Attributes\\Test;\nfinal class ConvergenceTest\n{\n    #[Test]\n    public function a_refresh_converges(): void { is_file(__FILE__); }\n}\n",
            $engine => self::extractor('$x = is_dir($path);'),
        ]);
        self::assertSame([], GraphExtractorScan::problems($root, [$test]));
        self::assertSame([], GraphExtractorScan::problems($root, [$engine]), 'the engine reads the disk, and is no extractor');
        self::assertSame([], GraphExtractorScan::problems($root, ["{$dir}/Clean.php"]));

        // The test loses the method the accept names: the extractor did not change, and is the one reported.
        file_put_contents("{$root}/{$test}", "<?php\nfinal class ConvergenceTest\n{\n    #[Test]\n    public function a_refresh_diverges(): void {}\n}\n");
        self::assertSame(["{$dir}/Accepted.php"], array_keys(GraphExtractorScan::problems($root, [$test])));
        self::assertSame(["{$dir}/Accepted.php"], array_keys(GraphExtractorScan::problems($root, [$engine, "{$dir}/Clean.php"])));
        self::assertSame([], GraphExtractorScan::problems($root, ["{$dir}/Clean.php"]), 'a given extractor is still read alone');

        // Review, semitexa-dev#316: the test deleted or renamed away is a path with no file, and the same event.
        unlink("{$root}/{$test}");
        self::assertSame(["{$dir}/Accepted.php"], array_keys(GraphExtractorScan::problems($root, [$test])));
        self::assertSame(["{$dir}/Accepted.php"], array_keys(GraphExtractorScan::problems($root, ["{$dir}/Deleted.php"])), 'so is an extractor that is gone');
    }

    #[Test]
    public function php_of_the_package_and_of_its_tests_is_covered(): void
    {
        $dir = GraphExtractorScan::DIRECTORY;

        self::assertTrue(GraphExtractorScan::covers("{$dir}/TemplateRenderExtractor.php"));
        self::assertTrue(GraphExtractorScan::covers("{$dir}/Attribute/UiNavigationExtractor.php"));
        self::assertTrue(GraphExtractorScan::covers('packages/semitexa-project-graph/src/Application/Service/Parser/ClassDeclarationReader.php'));
        self::assertTrue(GraphExtractorScan::covers('packages/semitexa-project-graph/src/Application/Service/Index/IncrementalEngine.php'));
        self::assertTrue(GraphExtractorScan::covers('packages/semitexa-project-graph/tests/Integration/UiFlowRefreshConvergenceTest.php'));
        self::assertFalse(GraphExtractorScan::covers("{$dir}/README.md"));
        self::assertFalse(GraphExtractorScan::covers('packages/semitexa-project-graph/tests/Fixture/notes.txt'));
        self::assertFalse(GraphExtractorScan::covers('packages/semitexa-project-graph/composer.json'));
        self::assertFalse(GraphExtractorScan::covers('packages/semitexa-project-graph-extra/src/A.php'));
        self::assertFalse(GraphExtractorScan::covers('packages/semitexa-dev/src/Application/Service/Extractor/A.php'));
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
            use function is_file as there;

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

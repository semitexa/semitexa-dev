<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\SourceSliceReader;
use Semitexa\Dev\Tests\Fixtures\Source\SlicedFixture;

/**
 * The rules a source view has to keep: exact method bounds, the class when the
 * method is not there, nothing at all for a name that is not a class or a file
 * that is not ours.
 */
final class SourceSliceReaderTest extends TestCase
{
    /** @var list<string> temp files to remove */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
    }

    #[Test]
    public function a_method_slice_has_reflection_exact_bounds(): void
    {
        $slice = (new SourceSliceReader())->slice(SlicedFixture::class, 'target');

        self::assertNotNull($slice);
        $ref = new \ReflectionMethod(SlicedFixture::class, 'target');
        self::assertSame($ref->getStartLine(), $slice->startLine);
        self::assertSame($ref->getEndLine(), $slice->endLine);
        self::assertSame('target', $slice->method);
        self::assertSame('reflection', $slice->origin);
        self::assertFalse($slice->truncated);
        self::assertCount($ref->getEndLine() - $ref->getStartLine() + 1, $slice->lines);
        self::assertStringContainsString('FIXTURE_TARGET_BODY', $slice->text());
        self::assertStringNotContainsString('FIXTURE_OTHER_BODY', $slice->text(), 'a method slice stops at its own closing brace');
    }

    #[Test]
    public function the_file_is_relative_to_the_project_root(): void
    {
        $slice = (new SourceSliceReader())->slice(SlicedFixture::class, 'target');

        self::assertNotNull($slice);
        self::assertStringStartsNotWith('/', $slice->file);
        self::assertStringEndsWith('tests/Fixtures/Source/SlicedFixture.php', $slice->file);
    }

    #[Test]
    public function no_method_means_the_whole_class(): void
    {
        $slice = (new SourceSliceReader())->slice(SlicedFixture::class);

        self::assertNotNull($slice);
        self::assertNull($slice->method);
        $ref = new \ReflectionClass(SlicedFixture::class);
        self::assertSame($ref->getStartLine(), $slice->startLine);
        self::assertSame($ref->getEndLine(), $slice->endLine);
        self::assertStringContainsString('FIXTURE_TARGET_BODY', $slice->text());
        self::assertStringContainsString('FIXTURE_OTHER_BODY', $slice->text());
    }

    #[Test]
    public function an_unknown_method_falls_back_to_the_class(): void
    {
        $slice = (new SourceSliceReader())->slice(SlicedFixture::class, 'doesNotExist');

        self::assertNotNull($slice);
        self::assertNull($slice->method, 'the record says what is shown, not what was asked');
        self::assertStringContainsString('FIXTURE_OTHER_BODY', $slice->text());
    }

    #[Test]
    public function an_inherited_method_is_read_from_the_file_that_declares_it(): void
    {
        $slice = (new SourceSliceReader())->slice(SlicedFixture::class, 'inherited');

        self::assertNotNull($slice);
        self::assertSame('inherited', $slice->method);
        self::assertStringEndsWith('SlicedFixtureParent.php', $slice->file);
        self::assertStringContainsString('FIXTURE_INHERITED_BODY', $slice->text());
    }

    #[Test]
    public function a_long_run_is_cut_at_the_cap_and_says_so(): void
    {
        $slice = (new SourceSliceReader())->slice(SlicedFixture::class, null, 3);

        self::assertNotNull($slice);
        self::assertTrue($slice->truncated);
        self::assertCount(3, $slice->lines);
        self::assertSame($slice->startLine + 2, $slice->endLine, 'endLine reflects the cut, not the declaration');
    }

    #[Test]
    public function a_name_that_is_not_a_class_never_reaches_the_autoloader(): void
    {
        $reader = new SourceSliceReader();

        self::assertNull($reader->slice('../../etc/passwd'));
        self::assertNull($reader->slice('NoNamespace'));
        self::assertNull($reader->slice('App\\Has Space'));
        self::assertNull($reader->slice('App\\Missing\\ClassThatDoesNotExist'));
    }

    #[Test]
    public function an_internal_class_has_no_source_to_show(): void
    {
        self::assertNull((new SourceSliceReader())->slice(\ArrayObject::class, 'count'));
    }

    #[Test]
    public function a_file_outside_the_project_root_is_refused(): void
    {
        $file = sys_get_temp_dir() . '/semitexa-slice-' . uniqid() . '.php';
        $this->tmp[] = $file;
        $class = 'SemitexaSliceTmp\\Outside' . str_replace('.', '', uniqid());
        file_put_contents($file, "<?php\nnamespace SemitexaSliceTmp;\nfinal class " . substr($class, strlen('SemitexaSliceTmp\\')) . " {\n    public function x(): int { return 1; }\n}\n");
        require $file;

        self::assertTrue(class_exists($class, false), 'fixture class is loaded from outside the root');
        self::assertNull((new SourceSliceReader())->slice($class, 'x'));
    }

    #[Test]
    public function the_array_form_carries_every_field(): void
    {
        $slice = (new SourceSliceReader())->slice(SlicedFixture::class, 'target');

        self::assertNotNull($slice);
        $array = $slice->toArray();
        self::assertSame(['fqcn', 'method', 'file', 'startLine', 'endLine', 'truncated', 'origin', 'lines'], array_keys($array));
        self::assertSame($slice->lines, $array['lines']);
    }

    #[Test]
    public function slice_any_shows_the_first_candidate_that_exists(): void
    {
        $slice = (new SourceSliceReader())->sliceAny(SlicedFixture::class, ['handle', 'target', 'other']);

        self::assertNotNull($slice);
        self::assertSame('target', $slice->method);
    }

    #[Test]
    public function slice_any_falls_back_to_the_class_when_no_candidate_exists(): void
    {
        $slice = (new SourceSliceReader())->sliceAny(SlicedFixture::class, ['handle', '__invoke']);

        self::assertNotNull($slice);
        self::assertNull($slice->method);
        self::assertStringContainsString('FIXTURE_OTHER_BODY', $slice->text());
    }

    #[Test]
    public function slice_any_with_no_candidates_is_the_class(): void
    {
        $slice = (new SourceSliceReader())->sliceAny(SlicedFixture::class, []);

        self::assertNotNull($slice);
        self::assertNull($slice->method);
    }

    #[Test]
    public function a_name_that_maps_to_a_functions_file_is_never_included(): void
    {
        // Composer PSR-4 maps Twig\Resources\core to twig/src/Resources/core.php,
        // a file of functions already loaded via autoload_files. Including it
        // again is an uncatchable redeclaration fatal. The reader must refuse
        // it on inspection, before the autoloader gets a say.
        if (!is_file(dirname(__DIR__, 5) . '/vendor/twig/twig/src/Resources/core.php')) {
            self::markTestSkipped('twig not installed in this workspace');
        }

        self::assertNull((new SourceSliceReader())->slice('Twig\\Resources\\core', 'x'));
        self::assertTrue(true, 'still alive');
    }
}

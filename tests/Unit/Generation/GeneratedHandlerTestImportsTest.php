<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Generation\Builder\TestPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Support\NameInflector;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateResolver;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;

/**
 * The generated handler test ends with one instruction — uncomment this line —
 * and that line named two classes the file did not import. So the first thing
 * anyone does with the scaffold produced two undefined classes.
 *
 * A scaffold whose only instruction does not work is worse than one that says
 * nothing, because it teaches the reader to distrust the generator.
 */
final class GeneratedHandlerTestImportsTest extends TestCase
{
    private function handlerTest(): string
    {
        $plan = (new TestPlanBuilder(new NameInflector(), new TemplateResolver(), new TemplateRenderer()))->build([
            'module' => 'AuditModule',
            'name' => 'AuditCheck',
            'dryRun' => true,
        ]);

        foreach ($plan->files as $file) {
            if (str_contains($file->path, '/tests/Unit/')) {
                return $file->content;
            }
        }

        self::fail('make:test planned no Unit handler test');
    }

    #[Test]
    public function the_payload_and_the_response_are_imported(): void
    {
        $content = $this->handlerTest();

        self::assertStringContainsString(
            'use Semitexa\\Modules\\AuditModule\\Application\\Payload\\Request\\AuditCheckPayload;',
            $content,
        );
        self::assertStringContainsString(
            'use Semitexa\\Modules\\AuditModule\\Application\\Resource\\Response\\AuditCheckResponse;',
            $content,
        );
    }

    /**
     * The real assertion: every short class name the TODO line mentions has an
     * import that ends with it. Checking the two names above would pass a
     * template that changed its example; this follows whatever the line says.
     */
    #[Test]
    public function every_class_the_todo_line_names_is_imported(): void
    {
        $content = $this->handlerTest();

        preg_match('/^\s*\/\/\s*\$resource = .*$/m', $content, $todo);
        self::assertNotEmpty($todo, 'the scaffold lost its worked example');

        preg_match_all('/new ([A-Z][A-Za-z0-9_]*)\(/', $todo[0], $names);
        self::assertNotEmpty($names[1], 'the example constructs nothing');

        foreach ($names[1] as $short) {
            self::assertMatchesRegularExpression(
                '/^use .*\\\\' . preg_quote($short, '/') . ';$/m',
                $content,
                "the example constructs {$short}, which the file does not import",
            );
        }
    }

    /** Imports stay sorted, so a later addition does not reshuffle the file. */
    #[Test]
    public function the_import_block_is_sorted(): void
    {
        preg_match_all('/^use .*;$/m', $this->handlerTest(), $matches);

        $imports = $matches[0];
        $sorted = $imports;
        sort($sorted);

        self::assertSame($sorted, $imports);
    }
}

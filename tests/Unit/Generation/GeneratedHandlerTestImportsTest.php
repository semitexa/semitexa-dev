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

    // verify:accept-test-change a new module is generated under App\Modules, the canonical prefix (operator decision 18.10)
    #[Test]
    public function the_payload_and_the_response_are_imported(): void
    {
        $content = $this->handlerTest();

        self::assertStringContainsString(
            'use App\\Modules\\AuditModule\\Application\\Payload\\Request\\AuditCheckPayload;',
            $content,
        );
        self::assertStringContainsString(
            'use App\\Modules\\AuditModule\\Application\\Resource\\Response\\AuditCheckResponse;',
            $content,
        );
    }

    /**
     * The real assertion: every class the generated test constructs has an
     * import that ends with it. The example used to be a commented TODO; since
     * bench-make-page-placeholder-tests it is the test's own code, so the same
     * rule now holds for what runs.
     */
    // verify:accept-test-change the TODO example became the test body (bench-make-page-placeholder-tests); the import rule now covers every `new` the test runs
    #[Test]
    public function every_class_the_test_constructs_is_imported(): void
    {
        $content = $this->handlerTest();
        self::assertStringNotContainsString('TODO', $content, 'no instruction left to follow by hand');

        preg_match_all('/new ([A-Z][A-Za-z0-9_]*)\(/', $content, $names);
        self::assertGreaterThanOrEqual(3, count(array_unique($names[1])), 'it constructs the handler, its payload and its resource');

        foreach (array_unique($names[1]) as $short) {
            self::assertMatchesRegularExpression(
                '/^use .*\\\\' . preg_quote($short, '/') . ';$/m',
                $content,
                "the test constructs {$short}, which the file does not import",
            );
        }
    }
}

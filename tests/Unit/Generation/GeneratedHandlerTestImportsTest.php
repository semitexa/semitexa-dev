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

    /**
     * make:test on a handler it cannot see runs nothing it cannot set up: the
     * handler may read services the container injects (review of dev#189), so
     * the test asserts the contract and says what to set. make:page's own
     * handler injects nothing, so its test runs handle().
     */
    #[Test]
    public function a_handler_it_cannot_see_is_held_to_its_contract_and_a_fresh_one_is_run(): void
    {
        $contract = $this->handlerTest();
        self::assertStringContainsString('_is_a_typed_handler', $contract);
        self::assertStringContainsString('// Set the services it injects on the handler', $contract);
        self::assertStringNotContainsString('$handler->handle(', $contract);

        $plan = (new TestPlanBuilder(new NameInflector(), new TemplateResolver(), new TemplateRenderer()))->build([
            'module' => 'AuditModule', 'name' => 'AuditCheck', 'target' => TestPlanBuilder::TARGET_HANDLER, 'freshHandler' => true, 'dryRun' => true,
        ]);
        $fresh = $plan->files[0]->content;
        self::assertStringContainsString('_answers_with_its_resource', $fresh);
        self::assertStringContainsString('self::assertInstanceOf(AuditCheckResponse::class, $handler->handle(new AuditCheckPayload(), new AuditCheckResponse())', $fresh);
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

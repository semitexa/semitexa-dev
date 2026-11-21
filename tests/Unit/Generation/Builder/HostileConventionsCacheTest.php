<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Generation\Builder;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Convention\ConventionMiner;
use Semitexa\Dev\Application\Service\Ai\Convention\ConventionStore;
use Semitexa\Dev\Application\Service\Ai\Convention\ModuleConventions;
use Semitexa\Dev\Application\Service\Generation\Builder\HandlerPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Support\NameInflector;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateResolver;

/**
 * make:handler copies the conventions cache (var/cache/ai/conventions.json)
 * into the handler it writes: a `use` line, an attribute, a property. The cache
 * is a file, so whoever can write it chose those lines. A `type` of
 * "Acme\X;\nsystem('id');//" rendered
 *
 *     use Acme\X;
 *     system('id');//;
 *
 * in a file `php -l` accepted, and loading it ran the call
 * (tk-handler-generator-trusts-the-conventions-cache).
 *
 * The real builder is run over a hostile cache file: it refuses the file,
 * naming the field, and what the miner itself writes still generates.
 */
final class HostileConventionsCacheTest extends TestCase
{
    private const PLAIN = [
        'type' => 'Acme\\Service\\CatalogService',
        'short_type' => 'CatalogService',
        'attribute' => 'InjectAsReadonly',
        'property' => 'catalog',
        'frequency' => 7,
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-hostile-conventions-' . uniqid();
        mkdir($this->root . '/var/cache/ai', 0755, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function hostileFields(): iterable
    {
        yield 'type ends the use line and calls a function' => ['type', "Acme\\X;\nsystem('id');//"];
        yield 'type ends the use line on one line' => ['type', "Acme\\X; system('id'); use Acme\\Y"];
        yield 'type is not a string' => ['type', ['Acme\\X']];
        yield 'short_type closes the property and adds a constructor' => ['short_type', "CatalogService \$a; public function __construct() { system('id'); } protected CatalogService"];
        yield 'short_type is another class than its type' => ['short_type', 'Closure'];
        yield 'attribute leaves the attribute and the class' => ['attribute', "InjectAsReadonly] public int \$a; } system('id'); final class Pwned { #[X"];
        yield 'attribute ends the use line' => ['attribute', "InjectAsReadonly;\nsystem('id');//"];
        yield 'attribute is not an injection attribute' => ['attribute', 'AsCommand'];
        yield 'property carries a default that runs code' => ['property', "catalog = null; public function __destruct() { system('id'); } public \$b"];
        yield 'frequency is not a number' => ['frequency', '7'];
    }

    #[Test]
    #[DataProvider('hostileFields')]
    public function a_cache_field_that_is_not_what_the_miner_writes_is_refused_by_name(string $field, mixed $value): void
    {
        $this->writeCache([[...self::PLAIN, $field => $value]]);

        try {
            $content = $this->generate();
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString("modules.Demo.handler_injections[0].{$field} ", $refusal->getMessage());
            self::assertStringContainsString('var/cache/ai/conventions.json', $refusal->getMessage());
            self::assertStringNotContainsString("\n", $refusal->getMessage(), 'one line, whatever the value held');

            return;
        }

        self::fail(sprintf(
            "make:handler generated from a cache whose %s is not what the miner writes. Calls in the file: [%s]\n%s",
            $field,
            implode(', ', self::calledFunctions($content)),
            $content,
        ));
    }

    /** The cache's own keys are text too: a map where the miner writes a list is refused without quoting them. */
    #[Test]
    public function injections_keyed_by_the_files_own_text_are_refused_on_one_line(): void
    {
        $this->writeCache(["x\n\e[31mFAKE" => self::PLAIN]);

        try {
            $this->generate();
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('modules.Demo.handler_injections is array, and it must be a list of injections', $refusal->getMessage());
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $refusal->getMessage(), 'a refusal prints no control character the file held');

            return;
        }

        self::fail('make:handler read injections keyed by the file\'s own text');
    }

    /** Payload attributes keyed by the file's own text are refused the same way (review semitexa-dev#305). */
    #[Test]
    public function payload_attributes_keyed_by_the_files_own_text_are_refused_on_one_line(): void
    {
        file_put_contents($this->root . '/var/cache/ai/conventions.json', json_encode([
            'schema' => 'semitexa.ai-conventions/v1',
            'modules' => ['Demo' => ['handlers_sampled' => 9, 'handler_injections' => [self::PLAIN], 'payload_attributes' => ["bad\n\e[31m" => false]]],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->generate();
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('modules.Demo.payload_attributes is array, and it must be a list of names', $refusal->getMessage());
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $refusal->getMessage(), 'a refusal prints no control character the file held');

            return;
        }

        self::fail('make:handler read payload attributes keyed by the file\'s own text');
    }

    /** Every part of a refused field's path is the file's own text when readAll() passes a module name: none reaches the terminal raw. */
    #[Test]
    public function a_module_name_from_the_file_is_printed_without_its_control_characters(): void
    {
        try {
            ModuleConventions::fromArray(['module' => "Demo\n\e[31mFAKE", 'handler_injections' => 'none']);
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('modules.Demo  [31mFAKE.handler_injections is "none", and it must be a list of injections', $refusal->getMessage());
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $refusal->getMessage());

            return;
        }

        self::fail('a module whose injections are a string was read');
    }

    /** A module name in the cache that is not a map is quoted back as a value, on one line. */
    #[Test]
    public function a_hostile_module_name_is_quoted_back_on_one_line(): void
    {
        file_put_contents($this->root . '/var/cache/ai/conventions.json', json_encode([
            'schema' => 'semitexa.ai-conventions/v1',
            'modules' => ['Demo' => ['handlers_sampled' => 9, 'handler_injections' => [self::PLAIN]], "x\n\e[31mFAKE" => 'no'],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->generate();
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('modules["x  [31mFAKE"] is string, and it must be a map of conventions', $refusal->getMessage());
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $refusal->getMessage(), 'a refusal prints no control character the file held');

            return;
        }

        self::fail('make:handler read a cache with a module that is not a map');
    }

    #[Test]
    public function a_row_that_is_not_a_map_is_refused(): void
    {
        $this->writeCache(['InjectAsReadonly']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('modules.Demo.handler_injections[0] is "InjectAsReadonly", and it must be an injection');

        $this->generate();
    }

    #[Test]
    public function what_the_miner_writes_from_real_handlers_still_generates(): void
    {
        $handlers = $this->root . '/src/modules/Demo/src/Application/Handler/PayloadHandler';
        mkdir($handlers, 0755, true);
        foreach (['First', 'Second'] as $name) {
            file_put_contents("{$handlers}/{$name}Handler.php", <<<PHP
                <?php

                namespace App\\Modules\\Demo\\Application\\Handler\\PayloadHandler;

                use Acme\\Service\\CatalogService;
                use Semitexa\\Core\\Attribute\\InjectAsReadonly;

                final class {$name}Handler
                {
                    #[InjectAsReadonly]
                    protected ?CatalogService \$catalog;
                }
                PHP);
        }
        (new ConventionStore($this->root))->writeAll((new ConventionMiner($this->root))->mineAll());

        $content = $this->generate();

        self::assertStringContainsString("use Acme\\Service\\CatalogService;\n", $content);
        self::assertStringContainsString("    #[InjectAsReadonly]\n    protected CatalogService \$catalog;\n", $content);
        self::assertSame([], self::calledFunctions($content));
    }

    /** @param array<array-key, mixed> $injections */
    private function writeCache(array $injections): void
    {
        file_put_contents($this->root . '/var/cache/ai/conventions.json', json_encode([
            'schema' => 'semitexa.ai-conventions/v1',
            'modules' => ['Demo' => ['handlers_sampled' => 9, 'handler_injections' => $injections]],
        ], JSON_THROW_ON_ERROR));
    }

    private function generate(): string
    {
        $builder = new HandlerPlanBuilder(new NameInflector(), new TemplateResolver(), new TemplateRenderer(), new ConventionStore($this->root));

        return $builder->build(['module' => 'demo', 'name' => 'thing', 'payload' => 'thing', 'resource' => 'thing', 'dryRun' => true])->files[0]->content;
    }

    /**
     * The functions a PHP file calls, by PHP's own tokens: a name followed by "(" that no declaration or `->`/`::`/`new` introduces.
     *
     * @return list<string>
     */
    private static function calledFunctions(string $php): array
    {
        $tokens = array_values(array_filter(
            token_get_all($php),
            static fn (array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $calls = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            if (is_array($before) && in_array($before[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_ATTRIBUTE], true)) {
                continue;
            }
            $calls[] = $token[1];
        }

        return $calls;
    }
}

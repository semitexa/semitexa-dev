<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Generation\Builder;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Generation\Builder\CommandPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\ContractPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\EventListenerPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\FormPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\HandlerPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\ModulePlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\PagePlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\PayloadPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\ResourcePlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\ServicePlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\TestPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationPlan;
use Semitexa\Dev\Application\Service\Generation\Support\NameInflector;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateResolver;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\Token;

/**
 * A generator option is text from outside (a command line, an MCP client), and
 * it used to reach the generated file by plain substitution:
 * `--command-name="a')] final class Pwned {} system('id'); __halt_compiler();//"`
 * rendered the caller's PHP at the top level of a class file the console loads
 * at boot (tk-generator-option-values-reach-php-unescaped).
 *
 * Each plan builder, called as its command calls it, either refuses a hostile
 * value by name or renders it as a string literal and nothing else: the file's
 * code is token for token what a plain value produces.
 */
final class HostileInputTest extends TestCase
{
    /** Leaves a string literal and an attribute, declares a class, runs a call, ends the file. */
    private const BREAKS_OUT_OF_A_STRING = "a')] final class Pwned {} system('id'); __halt_compiler();//";
    /** Is a class name until the brace: toStudly keeps every character of a single token. */
    private const BREAKS_OUT_OF_A_NAME = "Foo{}system('id');__halt_compiler();";
    private const BREAKS_OUT_OF_A_COMMENT = "x */ system('id'); /*";
    private const LEAVES_THE_MODULE = '../../../etc';

    private const PLAIN = [
        'make:command' => ['module' => 'Shop', 'name' => 'ImportUsers', 'commandName' => 'users:import', 'description' => 'Import users.', 'dryRun' => true],
        'make:contract' => ['module' => 'Shop', 'name' => 'PaymentGateway', 'implementation' => 'StripeGateway', 'dryRun' => true],
        'make:event-listener' => ['module' => 'Shop', 'name' => 'SendReceipt', 'event' => 'OrderPlaced', 'execution' => 'Async', 'dryRun' => true],
        'make:form' => ['module' => 'Shop', 'name' => 'ContactUs', 'fields' => [['name' => 'email', 'type' => 'email', 'required' => true]], 'submitText' => 'Send it', 'dryRun' => true],
        'make:handler' => ['module' => 'Shop', 'name' => 'Cart', 'payload' => 'Cart', 'resource' => 'Cart', 'dryRun' => true],
        'make:module' => ['name' => 'Shop', 'dryRun' => true],
        'make:page' => ['module' => 'Shop', 'name' => 'Cart', 'path' => '/cart/{id}', 'method' => 'GET', 'access' => 'public', 'withAssets' => true, 'dryRun' => true],
        'make:payload' => ['module' => 'Shop', 'name' => 'Cart', 'path' => '/cart', 'method' => 'POST', 'response' => 'Cart', 'access' => 'public', 'graphql' => true, 'graphqlField' => 'cart', 'dryRun' => true],
        'make:resource' => ['module' => 'Shop', 'name' => 'Cart', 'handle' => 'cart', 'template' => '@project-layouts-Shop/pages/cart.html.twig', 'withTemplate' => true, 'withAssets' => true, 'dryRun' => true],
        'make:service' => ['module' => 'Shop', 'name' => 'CartTotals', 'dryRun' => true],
        'make:test' => ['module' => 'Shop', 'name' => 'Cart', 'dryRun' => true],
    ];

    /** @return iterable<string, array{string, string, mixed}> */
    public static function refusedValues(): iterable
    {
        $cases = [
            'make:command' => ['module' => [self::BREAKS_OUT_OF_A_NAME, self::LEAVES_THE_MODULE], 'name' => [self::BREAKS_OUT_OF_A_NAME], 'commandName' => [self::BREAKS_OUT_OF_A_STRING]],
            'make:contract' => ['module' => [self::BREAKS_OUT_OF_A_NAME], 'name' => [self::BREAKS_OUT_OF_A_NAME], 'implementation' => [self::BREAKS_OUT_OF_A_NAME]],
            'make:event-listener' => [
                'module' => [self::BREAKS_OUT_OF_A_NAME],
                'name' => [self::BREAKS_OUT_OF_A_NAME],
                'event' => [self::BREAKS_OUT_OF_A_NAME, "Shop\\Event\\OrderPlaced; system('id'); use Shop\\X"],
                'execution' => ["Sync)]system('id');#[X(1"],
            ],
            'make:form' => [
                'module' => [self::BREAKS_OUT_OF_A_NAME],
                'name' => [self::BREAKS_OUT_OF_A_NAME, self::BREAKS_OUT_OF_A_COMMENT],
                'fields' => [[['name' => "a'.system('id').'", 'type' => 'text', 'required' => false]], [['name' => 'email', 'type' => 'of', 'required' => false]]],
            ],
            'make:handler' => ['module' => [self::BREAKS_OUT_OF_A_NAME], 'name' => [self::BREAKS_OUT_OF_A_NAME], 'payload' => [self::BREAKS_OUT_OF_A_NAME], 'resource' => [self::BREAKS_OUT_OF_A_NAME]],
            'make:module' => ['name' => [self::BREAKS_OUT_OF_A_NAME, self::LEAVES_THE_MODULE]],
            'make:page' => [
                'module' => [self::BREAKS_OUT_OF_A_NAME],
                'name' => [self::BREAKS_OUT_OF_A_NAME, self::BREAKS_OUT_OF_A_COMMENT],
                'path' => [self::BREAKS_OUT_OF_A_STRING, "/cart'"],
                'method' => ["GET'], responseWith: Pwned::class)] final class Pwned {} system('id'); __halt_compiler();//"],
            ],
            'make:payload' => [
                'module' => [self::BREAKS_OUT_OF_A_NAME],
                'name' => [self::BREAKS_OUT_OF_A_NAME],
                'response' => [self::BREAKS_OUT_OF_A_NAME],
                'path' => [self::BREAKS_OUT_OF_A_STRING, 'cart', "/cart\n"],
                'method' => ["GET'], responseWith: Pwned::class)] final class Pwned {} system('id'); __halt_compiler();//", 'GET,POST'],
                'graphqlField' => [self::BREAKS_OUT_OF_A_STRING, 'cart"field'],
            ],
            'make:resource' => [
                'module' => [self::BREAKS_OUT_OF_A_NAME],
                'name' => [self::BREAKS_OUT_OF_A_NAME, self::BREAKS_OUT_OF_A_COMMENT],
                'handle' => [self::BREAKS_OUT_OF_A_STRING],
                'template' => [self::BREAKS_OUT_OF_A_STRING, '@project-layouts-Shop/../../secret.twig'],
            ],
            'make:service' => ['module' => [self::BREAKS_OUT_OF_A_NAME], 'name' => [self::BREAKS_OUT_OF_A_NAME]],
            'make:test' => ['module' => [self::BREAKS_OUT_OF_A_NAME], 'name' => [self::BREAKS_OUT_OF_A_NAME]],
        ];
        foreach ($cases as $generator => $params) {
            foreach ($params as $param => $values) {
                foreach ($values as $i => $value) {
                    yield "{$generator} {$param} #{$i}" => [$generator, $param, $value];
                }
            }
        }
    }

    #[Test]
    #[DataProvider('refusedValues')]
    public function a_value_that_is_not_what_its_option_means_is_refused_before_anything_renders(string $generator, string $param, mixed $value): void
    {
        try {
            $plan = self::build($generator, [$param => $value] + self::PLAIN[$generator]);
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('is refused: it must be', $e->getMessage(), 'the refusal says what the value has to be');

            return;
        }

        self::fail(sprintf(
            "%s rendered %s=%s into:\n%s",
            $generator,
            $param,
            var_export($value, true),
            implode("\n", array_map(static fn ($f): string => $f->path . "\n" . $f->content, $plan->files)),
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function freeText(): iterable
    {
        yield 'breaks out of the literal' => [self::BREAKS_OUT_OF_A_STRING];
        yield 'quotes, a backslash and a new line' => ["it's a \\ \"test\"\nsecond line \\' end\\"];
        yield 'a placeholder of the template' => ['{{className}} {{namespace}}'];
    }

    #[Test]
    #[DataProvider('freeText')]
    public function a_command_description_is_only_ever_a_string_literal(string $description): void
    {
        $plain = self::build('make:command', self::PLAIN['make:command'])->files[0]->content;
        $hostile = self::build('make:command', ['description' => $description] + self::PLAIN['make:command'])->files[0]->content;

        self::assertSame(self::code($plain), self::code($hostile), 'the description changes no token of the file except string literals');
        self::assertSame(
            ['users:import', $description, 'users:import', $description, 'Done.'],
            self::literals($hostile),
            'and it is read back, whole, from the two literals the template has for it',
        );
        self::assertSame(1, self::occurrences(self::code($hostile), 'class'));
        self::assertSame(0, self::occurrences(self::code($hostile), '__halt_compiler'));
    }

    #[Test]
    public function a_plain_command_renders_as_it_did(): void
    {
        $php = self::build('make:command', self::PLAIN['make:command'])->files[0]->content;

        self::assertStringContainsString("#[AsCommand(name: 'users:import', description: 'Import users.')]", $php);
        self::assertStringContainsString("\$this->setName('users:import')\n            ->setDescription('Import users.');", $php);
    }

    #[Test]
    #[DataProvider('freeText')]
    public function a_form_s_submit_text_is_only_ever_a_twig_string(string $text): void
    {
        $plain = self::build('make:form', self::PLAIN['make:form'])->files[1]->content;
        $hostile = self::build('make:form', ['submitText' => $text] + self::PLAIN['make:form'])->files[1]->content;

        self::assertStringContainsString("    submitText: 'Send it',\n", $plain, 'a plain value renders as it did');
        // Twig's own lexer says what the partial is: the same tokens as the plain
        // one, the submit text a single string token holding the text given.
        $expected = array_map(
            static fn (array $token): array => $token === [Token::STRING_TYPE, 'Send it'] ? [Token::STRING_TYPE, $text] : $token,
            self::twigTokens($plain),
        );
        self::assertContains([Token::STRING_TYPE, $text], $expected);
        self::assertSame($expected, self::twigTokens($hostile));
    }

    /** @return list<array{int, mixed}> each token of the template as [type, value] */
    private static function twigTokens(string $twig): array
    {
        $stream = (new Environment(new ArrayLoader()))->tokenize(new Source($twig, 'partial.html.twig'));
        $tokens = [];
        while (!$stream->isEOF()) {
            $token = $stream->next();
            $tokens[] = [$token->getType(), $token->getValue()];
        }

        return $tokens;
    }

    #[Test]
    public function every_file_a_plain_run_plans_is_one_declaration_of_valid_php(): void
    {
        foreach (self::PLAIN as $generator => $params) {
            foreach (self::build($generator, $params)->files as $file) {
                if (!str_ends_with($file->path, '.php')) {
                    continue;
                }
                $code = self::code($file->content);
                self::assertSame(
                    1,
                    self::occurrences($code, 'class') + self::occurrences($code, 'interface') - self::occurrences($code, '::class'),
                    $file->path,
                );
            }
        }
    }

    /** @param array<string, mixed> $params */
    private static function build(string $generator, array $params): GenerationPlan
    {
        $inflector = new NameInflector();
        $resolver = new TemplateResolver();
        $renderer = new TemplateRenderer();

        // Each builder declares the shape its command passes; what is under test
        // is a caller that passes something else.
        /** @var array<string, \Closure(array<string, mixed>): GenerationPlan> $builders */
        $builders = [
            'make:command' => (new CommandPlanBuilder($inflector, $resolver, $renderer))->build(...),
            'make:contract' => (new ContractPlanBuilder($inflector, $resolver, $renderer))->build(...),
            'make:event-listener' => (new EventListenerPlanBuilder($inflector, $resolver, $renderer))->build(...),
            'make:form' => (new FormPlanBuilder($inflector, $resolver, $renderer))->build(...),
            'make:handler' => (new HandlerPlanBuilder($inflector, $resolver, $renderer))->build(...),
            'make:module' => (new ModulePlanBuilder($inflector))->build(...),
            'make:page' => (new PagePlanBuilder($inflector, $resolver, $renderer))->build(...),
            'make:payload' => (new PayloadPlanBuilder($inflector, $resolver, $renderer))->build(...),
            'make:resource' => (new ResourcePlanBuilder($inflector, $resolver, $renderer))->build(...),
            'make:service' => (new ServicePlanBuilder($inflector, $resolver, $renderer))->build(...),
            'make:test' => (new TestPlanBuilder($inflector, $resolver, $renderer))->build(...),
        ];

        return ($builders[$generator] ?? throw new \LogicException("No generator {$generator}."))($params);
    }

    /**
     * The file's code: every token PHP runs, a string literal reduced to the
     * fact that it is one. Parsing is part of it — invalid PHP throws here.
     *
     * @return list<string>
     */
    private static function code(string $php): array
    {
        $code = [];
        foreach (\PhpToken::tokenize($php, TOKEN_PARSE) as $token) {
            if (!$token->isIgnorable()) {
                $code[] = $token->id === T_CONSTANT_ENCAPSED_STRING ? "'…'" : $token->text;
            }
        }

        return $code;
    }

    /** @return list<string> the value of each single-quoted string literal, in order */
    private static function literals(string $php): array
    {
        $values = [];
        foreach (\PhpToken::tokenize($php, TOKEN_PARSE) as $token) {
            if ($token->id === T_CONSTANT_ENCAPSED_STRING && $token->text[0] === "'") {
                $values[] = strtr(substr($token->text, 1, -1), ['\\\\' => '\\', "\\'" => "'"]);
            }
        }

        return $values;
    }

    /** @param list<string> $code */
    private static function occurrences(array $code, string $what): int
    {
        if ($what === '::class') {
            $n = 0;
            foreach ($code as $i => $text) {
                $n += $text === 'class' && ($code[$i - 1] ?? '') === '::' ? 1 : 0;
            }

            return $n;
        }

        return count(array_keys($code, $what, true));
    }
}

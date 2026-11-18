<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Generation\Support\GenerationInput;

/**
 * The rules a generator option is held to: what people really pass keeps
 * working, and what is not a name, a path or a command name is told so.
 */
final class GenerationInputTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function accepted(): iterable
    {
        foreach ([
            GenerationInput::NAME => ['sync', 'ImportUsers', 'sync-command', 'sync_command', 'SYNC_COMMAND', 'User Import', 'Step2'],
            GenerationInput::IDENTIFIER => ['Shop', '_Internal', 'Step2Handler'],
            GenerationInput::CLASS_NAME => ['OrderPlaced', 'App\\Modules\\Shop\\Domain\\Event\\OrderPlaced', '\\Semitexa\\Core\\Event\\Booted'],
            GenerationInput::CLASS_OR_NAME => ['order_placed', 'App\\Modules\\Shop\\Domain\\Event\\OrderPlaced'],
            GenerationInput::COMMAND_NAME => ['users:import', 'cache:clear', 'ai:review-graph:impact', 'about', 'app.sync_all'],
            GenerationInput::ROUTE_PATH => ['/', '/cart', '/catalog/products/{id}', '/docs/request-flow.md', '/~user/a_b', 'env::CATALOG_ROUTE_PATH::/catalog'],
            GenerationInput::HTTP_METHOD => ['GET', 'post', 'Delete'],
            GenerationInput::PERMISSION => ['products', 'content.publish', 'orders:read'],
            GenerationInput::HANDLE => ['cart', 'product-list', 'admin.users'],
            GenerationInput::TEMPLATE => ['@project-layouts-Shop/pages/cart.html.twig', 'layouts/one-column.html.twig', 'one-column'],
            GenerationInput::GRAPHQL_FIELD => ['cart', '_cartItems2', '  ', ' cart '],
            GenerationInput::FIELD_NAME => ['email', 'contactEmail2'],
        ] as $rule => $values) {
            foreach ($values as $value) {
                yield "{$rule}: {$value}" => [$rule, $value];
            }
        }
    }

    #[Test]
    #[DataProvider('accepted')]
    public function a_value_people_really_pass_is_accepted(string $rule, string $value): void
    {
        self::assertNull(GenerationInput::problem($rule, $value));
        self::assertSame($value, GenerationInput::valid($rule, $value, 'The value'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        foreach ([
            GenerationInput::NAME => ['', '2Step', 'Foo{}', 'Foo;', "Foo\nBar", 'Foo\\Bar', '../Foo', 'Foo/Bar', "Foo'", 'Фу'],
            GenerationInput::IDENTIFIER => ['', '2Step', 'Foo Bar', 'Foo-Bar', 'Foo{}', "Foo\n"],
            GenerationInput::CLASS_NAME => ['', 'App\\', 'App\\\\Evt', 'App\\Evt;', 'App\\2Evt', 'App\\Evt::class'],
            GenerationInput::CLASS_OR_NAME => ["OrderPlaced; system('id')", 'App\\Evt{}'],
            GenerationInput::COMMAND_NAME => ['', ':import', 'users:', 'users::import', "users:import'", 'users import', "users:import\n"],
            GenerationInput::ROUTE_PATH => ['', 'cart', "/cart'", '/cart?x=1', '/cart ', "/cart\n", '/a\\b', 'env::X::cart', 'env::X Y::/cart', '/{id:\\d+}'],
            GenerationInput::HTTP_METHOD => ['', 'GET,POST', "GET'", 'FETCH', "GET\n"],
            GenerationInput::PERMISSION => ['', 'a b', 'a*/', "a'"],
            GenerationInput::HANDLE => ['', "cart'", 'a b', 'a/b'],
            GenerationInput::TEMPLATE => ['', "cart.twig'", '@a/../b.twig', '../b.twig', '/etc/passwd', 'a b.twig', '@@a'],
            GenerationInput::GRAPHQL_FIELD => ['2cart', 'cart-items', "cart'", 'cart"field', 'a b'],
            GenerationInput::FIELD_NAME => ['', 'Email', 'contact_email', "a'"],
        ] as $rule => $values) {
            foreach ($values as $i => $value) {
                yield "{$rule} #{$i}" => [$rule, $value];
            }
        }
    }

    #[Test]
    #[DataProvider('refused')]
    public function a_value_that_is_something_else_is_refused_and_told_what_to_be(string $rule, string $value): void
    {
        self::assertIsString(GenerationInput::problem($rule, $value));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The value ' . GenerationInput::shown($value) . ' is refused: it must be ' . GenerationInput::problem($rule, $value) . '.');
        GenerationInput::valid($rule, $value, 'The value');
    }

    #[Test]
    public function a_refused_value_is_quoted_back_short_on_one_line_and_as_valid_utf8(): void
    {
        self::assertSame('"a b"', GenerationInput::shown("a\nb"));
        self::assertSame('"' . str_repeat('x', 60) . '…"', GenerationInput::shown(str_repeat('x', 200)));
        $shown = GenerationInput::shown(str_repeat('ї', 40) . "\xFF\xFE");
        self::assertTrue(mb_check_encoding($shown, 'UTF-8'), 'it goes into a JSON envelope');
        self::assertSame('"' . str_repeat('ї', 30) . '…"', $shown);
    }

    /**
     * Words php.net lists as keywords, compile-time constants and reserved type
     * names, with words it only soft-reserves or does not reserve mixed in:
     * which is which is asked of PHP below, not written here.
     */
    private const WORDS = [
        '__halt_compiler', 'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone',
        'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare',
        'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'eval', 'exit', 'extends', 'final', 'finally', 'fn',
        'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once', 'instanceof',
        'insteadof', 'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or', 'print', 'private', 'protected',
        'public', 'readonly', 'require', 'require_once', 'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset',
        'use', 'var', 'while', 'xor', 'yield', 'from',
        '__class__', '__dir__', '__file__', '__function__', '__line__', '__method__', '__namespace__', '__property__', '__trait__',
        'enum', 'resource', 'numeric', 'integer', 'boolean', 'double', 'get', 'set', 'cart', 'order',
    ];

    /** Type names PHP refuses when it compiles the declaration, which a parse alone does not show. */
    private const RESERVED_TYPE_NAMES = ['self', 'parent', 'int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void', 'iterable', 'object', 'mixed', 'never'];

    #[Test]
    public function a_declared_name_is_refused_exactly_when_php_refuses_to_parse_it_as_a_class_an_interface_or_an_enum(): void
    {
        $refused = 0;
        foreach (self::WORDS as $word) {
            foreach ([$word, strtoupper($word), ucfirst($word)] as $name) {
                foreach (['class', 'interface', 'enum'] as $kind) {
                    try {
                        \PhpToken::tokenize("<?php {$kind} {$name} {}", TOKEN_PARSE);
                        $parses = true;
                    } catch (\ParseError) {
                        $parses = false;
                    }
                    self::assertSame($parses, GenerationInput::problem(GenerationInput::DECLARED_NAME, $name) === null, "{$kind} {$name}");
                    $refused += $parses ? 0 : 1;
                }
            }
        }
        self::assertGreaterThan(600, $refused, 'the list is mostly words PHP refuses');
        self::assertNull(GenerationInput::problem(GenerationInput::DECLARED_NAME, 'Enum'), 'a soft-reserved word declares');
    }

    #[Test]
    public function a_declared_name_is_not_a_type_name_php_reserves_in_any_case(): void
    {
        foreach (self::RESERVED_TYPE_NAMES as $word) {
            foreach ([$word, strtoupper($word), ucfirst($word)] as $name) {
                self::assertNotNull(GenerationInput::problem(GenerationInput::DECLARED_NAME, $name), $name);
                self::assertNull(GenerationInput::problem(GenerationInput::DECLARED_NAME, $name . 'Value'), $name . 'Value');
            }
        }
    }

    #[Test]
    public function a_reserved_word_is_still_an_identifier_where_no_class_is_declared(): void
    {
        // A module is a namespace segment and a directory: PHP 8 takes App\Modules\List.
        self::assertSame('List', GenerationInput::valid(GenerationInput::IDENTIFIER, 'List', 'The module'));
        self::assertNotNull(GenerationInput::problem(GenerationInput::DECLARED_NAME, 'Foo{}'), 'and a declared name is an identifier first');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The service class "List" is refused: it must be a PHP identifier');
        GenerationInput::valid(GenerationInput::DECLARED_NAME, 'List', 'The service class');
    }

    /** @return iterable<string, array{string}> */
    public static function texts(): iterable
    {
        yield 'plain' => ['Import users.'];
        yield 'quotes and backslashes' => ["it's a \\ \"test\" \\' end\\"];
        yield 'leaves the literal' => ["a')] final class Pwned {} system('id'); __halt_compiler();//"];
        yield 'new lines and a dollar' => ["line one\nline \$two {\$three}"];
    }

    #[Test]
    #[DataProvider('texts')]
    public function a_php_string_literal_is_one_token_that_evaluates_to_the_text(string $text): void
    {
        $literal = GenerationInput::phpString($text);
        $tokens = array_slice(\PhpToken::tokenize('<?php ' . $literal . ';', TOKEN_PARSE), 1);

        self::assertSame([T_CONSTANT_ENCAPSED_STRING, ';'], [$tokens[0]->id, $tokens[1]->text], $literal);
        self::assertCount(2, $tokens);
        self::assertSame($text, strtr(substr($tokens[0]->text, 1, -1), ['\\\\' => '\\', "\\'" => "'"]));
    }

    #[Test]
    public function a_plain_php_string_reads_as_hand_written(): void
    {
        self::assertSame("'users:import'", GenerationInput::phpString('users:import'));
        self::assertSame("'Send'", GenerationInput::twigString('Send'));
    }
}

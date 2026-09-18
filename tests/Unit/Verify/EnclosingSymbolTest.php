<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\AcceptedViolations;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\EnclosingSymbol;

/**
 * An accepted violation is accepted at a SITE. The file and the rule were the
 * whole key, so deleting the blessed call and writing a different one elsewhere
 * in the same class kept the gate green with somebody else's reason attached —
 * and the textual ratchet saw an unchanged occurrence count either way. Raised
 * in review of dev#83.
 *
 * The line is not the fingerprint: it moves whenever anything above it does.
 * The message is not one either — `semitexa.staticContainerAccess` names the
 * class, not the method. The enclosing method is what is recorded, and this is
 * what has to find it.
 *
 * And when there is no enclosing method — a rule that reports on the class
 * itself — the class is what is recorded. That half returned null until
 * 2026-09-18, so a class-level entry could be written, could look right, and
 * accepted nothing.
 */
final class EnclosingSymbolTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        EnclosingSymbol::reset();
        $this->file = sys_get_temp_dir() . '/semitexa-symbol-' . uniqid('', true) . '.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        EnclosingSymbol::reset();
    }

    private function write(string $body): string
    {
        file_put_contents($this->file, $body);

        return $this->file;
    }

    /** @return array<string, int> line of each `// HERE:<name>` marker */
    private function markers(string $source): array
    {
        $out = [];
        foreach (explode("\n", $source) as $i => $line) {
            if (preg_match('/\/\/ HERE:(\w+)/', $line, $m) === 1) {
                $out[$m[1]] = $i + 1;
            }
        }

        return $out;
    }

    #[Test]
    public function a_line_is_attributed_to_the_method_it_is_in(): void
    {
        $source = <<<'PHP'
        <?php
        class A {
            public function first(): void { $x = 1; /* HERE:first */ }

            private function second(): void
            {
                $y = 2; // HERE:second
            }
        }
        PHP;

        $file = $this->write($source);
        $lines = $this->markers($source);

        self::assertSame('second', EnclosingSymbol::at($file, $lines['second']));
    }

    /**
     * The case that broke the first draft: `"{$x}"` OPENS with a token and
     * CLOSES with a plain `}`, so one interpolation anywhere unbalanced the
     * brace depth and every method after it was attributed to nothing.
     */
    #[Test]
    public function string_interpolation_does_not_unbalance_the_walk(): void
    {
        $source = <<<'PHP'
        <?php
        class A {
            public function noisy(string $x): string
            {
                return "a {$x} b {$x}";
            }

            public function after(): void
            {
                $y = 2; // HERE:after
            }
        }
        PHP;

        $file = $this->write($source);
        $lines = $this->markers($source);

        self::assertSame('after', EnclosingSymbol::at($file, $lines['after']));
    }

    /**
     * A violation inside a callback belongs to the method that wrote the
     * callback: that is the thing a reader recognises, and the thing the reason
     * was written about.
     */
    #[Test]
    public function a_closure_is_transparent(): void
    {
        $source = <<<'PHP'
        <?php
        class A {
            public function outer(): void
            {
                $this->run(function (): void {
                    $z = 3; // HERE:inside
                });
            }
        }
        PHP;

        $file = $this->write($source);
        $lines = $this->markers($source);

        self::assertSame('outer', EnclosingSymbol::at($file, $lines['inside']));
    }

    #[Test]
    public function a_method_with_no_body_does_not_claim_the_next_one(): void
    {
        $source = <<<'PHP'
        <?php
        abstract class A {
            abstract public function declared(): void;

            public function real(): void
            {
                $q = 1; // HERE:real
            }
        }
        PHP;

        $file = $this->write($source);
        $lines = $this->markers($source);

        self::assertSame('real', EnclosingSymbol::at($file, $lines['real']));
    }

    /**
     * Some rules report on the class, not inside a method —
     * `semitexa.domainModelEncapsulation` names the mapper and reports at its
     * declaration. This returned null there, null never equalled a site, and
     * the entry that should have accepted it silently did nothing.
     */
    #[Test]
    public function a_line_outside_every_method_belongs_to_its_class(): void
    {
        $source = <<<'PHP'
        <?php
        class A { // HERE:classLine
            private int $field = 1; // HERE:property

            public function only(): void
            {
            }
        }
        PHP;

        $file = $this->write($source);
        $lines = $this->markers($source);

        self::assertSame('A', EnclosingSymbol::at($file, $lines['classLine']));
        self::assertSame('A', EnclosingSymbol::at($file, $lines['property']));
    }

    /**
     * THE CASE THAT MATTERS AND THE ONE A FIXTURE WITHOUT ATTRIBUTES HIDES.
     * PHPStan reports a class error at the node's start line, and a node with
     * attributes starts at the first `#[` — one line ABOVE the `class` keyword.
     * WebhookInboxMapper.php reports at line 14, which is `#[AsMapper(...)]`.
     * A range that began at the keyword would miss every attributed class in
     * the codebase, which is nearly all of them.
     */
    #[Test]
    public function an_attributed_class_owns_the_line_its_attribute_is_on(): void
    {
        $source = <<<'PHP'
        <?php
        #[AsMapper(resourceModel: RowModel::class, domainModel: Thing::class)] // HERE:attribute
        final class Mapper
        { // HERE:brace
            public function toDomain(object $row): object
            {
                return new Thing(); // HERE:inside
            }
        }
        PHP;

        $file = $this->write($source);
        $lines = $this->markers($source);

        self::assertSame('Mapper', EnclosingSymbol::at($file, $lines['attribute']));
        self::assertSame('Mapper', EnclosingSymbol::at($file, $lines['brace']));
        self::assertSame('toDomain', EnclosingSymbol::at($file, $lines['inside']), 'a method still wins over its class');
    }

    #[Test]
    public function a_run_of_attribute_groups_starts_at_the_first_one(): void
    {
        $source = <<<'PHP'
        <?php
        #[First] // HERE:first
        #[Second]
        class Decorated
        {
        }
        PHP;

        $file = $this->write($source);
        $lines = $this->markers($source);

        self::assertSame('Decorated', EnclosingSymbol::at($file, $lines['first']));
    }

    /**
     * `Foo::class` is a constant, not a declaration, and it appears in the
     * arguments of nearly every attribute in this codebase — including the one
     * on the mapper this whole fallback exists for.
     */
    #[Test]
    public function a_class_constant_does_not_open_a_range(): void
    {
        $source = <<<'PHP'
        <?php
        class Holder
        {
            public function name(): string
            {
                return Other::class; // HERE:constant
            }
        }
        PHP;

        $file = $this->write($source);
        $lines = $this->markers($source);

        self::assertSame('name', EnclosingSymbol::at($file, $lines['constant']));
    }

    /**
     * An anonymous class belongs to whatever wrote it, for the same reason a
     * closure does: it is not a thing a reader can name in a registry entry.
     */
    #[Test]
    public function an_anonymous_class_is_transparent(): void
    {
        $source = <<<'PHP'
        <?php
        class Outer
        {
            public function build(): object
            {
                return new class extends Base {
                    public $field = 1; // HERE:inside
                };
            }
        }
        PHP;

        $file = $this->write($source);
        $lines = $this->markers($source);

        self::assertSame('build', EnclosingSymbol::at($file, $lines['inside']));
    }

    #[Test]
    public function an_interface_a_trait_and_an_enum_are_named_too(): void
    {
        $source = <<<'PHP'
        <?php
        interface Contract
        {
            public function run(): void; // HERE:interfaceLine
        }

        trait Helper
        {
            private int $helped = 0; // HERE:traitLine
        }

        enum Status: string
        {
            case Open = 'open'; // HERE:enumLine
        }
        PHP;

        $file = $this->write($source);
        $lines = $this->markers($source);

        self::assertSame('Contract', EnclosingSymbol::at($file, $lines['interfaceLine']));
        self::assertSame('Helper', EnclosingSymbol::at($file, $lines['traitLine']));
        self::assertSame('Status', EnclosingSymbol::at($file, $lines['enumLine']));
    }

    #[Test]
    public function a_line_in_no_class_and_no_function_belongs_to_none(): void
    {
        $source = <<<'PHP'
        <?php

        declare(strict_types=1); // HERE:header

        class A
        {
        }
        PHP;

        $file = $this->write($source);
        $lines = $this->markers($source);

        self::assertNull(EnclosingSymbol::at($file, $lines['header']));
    }

    #[Test]
    public function a_file_that_cannot_be_read_places_nothing(): void
    {
        self::assertNull(EnclosingSymbol::at('/no/such/file.php', 10));
    }

    /**
     * A by-reference method keeps its name.
     *
     * PHP 8.1 stopped emitting the `&` of `public function &items()` as a plain
     * character and gives T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, so the
     * walk read it as "this declaration has no name of its own", the method got
     * no range, and a line inside it resolved to the enclosing CLASS — where it
     * could match a class-level accepted site and consume an allowance written
     * for something else.
     */
    #[Test]
    public function a_by_reference_method_is_still_a_named_method(): void
    {
        $source = <<<'PHP'
        <?php
        class A
        {
            public function &items(): array
            {
                $x = []; // HERE:inside

                return $x;
            }

            public function after(): void
            {
                $y = 1; // HERE:after
            }
        }
        PHP;

        $file = $this->write($source);
        $lines = $this->markers($source);

        self::assertSame('items', EnclosingSymbol::at($file, $lines['inside']));
        self::assertSame('after', EnclosingSymbol::at($file, $lines['after']), 'and the next method is unaffected');
    }

    /**
     * Every registry entry must name a symbol that exists where it says, or the
     * allowance silently stops applying and the gate goes red for a reason
     * nobody wrote down.
     *
     * Asked through the resolver the gate itself uses, rather than by grepping
     * for `function <site>(`: that pattern was the check, and it could not see
     * a class site at all — which is how an entry that looked right did
     * nothing. A site is real when some line of that file resolves to it.
     */
    #[Test]
    public function every_accepted_entry_names_a_symbol_the_resolver_finds(): void
    {
        $root = dirname(__DIR__, 5);

        foreach (AcceptedViolations::all() as $path => $rules) {
            $absolute = $root . '/' . $path;
            if (!is_file($absolute)) {
                continue; // a package that is not installed here
            }

            $lines = substr_count((string) file_get_contents($absolute), "\n") + 1;

            foreach ($rules as $rule => $entry) {
                self::assertArrayHasKey('site', $entry, "{$path} / {$rule} accepts without naming a site");

                $found = false;
                for ($line = 1; $line <= $lines; $line++) {
                    if (EnclosingSymbol::at($absolute, $line) === $entry['site']) {
                        $found = true;
                        break;
                    }
                }

                self::assertTrue(
                    $found,
                    "{$path} accepts {$rule} at '{$entry['site']}', which no line of that file resolves to",
                );
            }
        }
    }
}

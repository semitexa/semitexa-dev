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

    #[Test]
    public function a_line_outside_every_method_belongs_to_none(): void
    {
        $source = <<<'PHP'
        <?php
        class A { // HERE:classLine
            public function only(): void
            {
            }
        }
        PHP;

        $file = $this->write($source);
        $lines = $this->markers($source);

        self::assertNull(EnclosingSymbol::at($file, $lines['classLine']));
    }

    #[Test]
    public function a_file_that_cannot_be_read_places_nothing(): void
    {
        self::assertNull(EnclosingSymbol::at('/no/such/file.php', 10));
    }

    /**
     * Every registry entry must name a method that exists where it says, or the
     * allowance silently stops applying and the gate goes red for a reason
     * nobody wrote down.
     */
    #[Test]
    public function every_accepted_entry_names_a_real_method_in_its_file(): void
    {
        $root = dirname(__DIR__, 5);

        foreach (AcceptedViolations::all() as $path => $rules) {
            $absolute = $root . '/' . $path;
            if (!is_file($absolute)) {
                continue; // a package that is not installed here
            }

            foreach ($rules as $rule => $entry) {
                self::assertArrayHasKey('site', $entry, "{$path} / {$rule} accepts without naming a site");
                self::assertMatchesRegularExpression(
                    '/function\s+' . preg_quote($entry['site'], '/') . '\s*\(/',
                    (string) file_get_contents($absolute),
                    "{$path} accepts {$rule} in {$entry['site']}(), which is not in that file",
                );
            }
        }
    }
}

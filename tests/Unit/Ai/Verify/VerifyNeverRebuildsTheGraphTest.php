<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Class 3 of the review verdicts of 18-19.11: a full graph rebuild where a refresh was meant
 * (var/docs/design/2026-11-19-verify-checks-from-review-verdicts.md). ai:verify runs on every
 * change, so a check that rebuilds the project graph turns a run of seconds into minutes. Code
 * under Ai\Verify reads the graph and refreshes it only through updateUnlessBusy() behind an
 * isCurrentBuild() guard, as UiFlowCheck does; it never calls IncrementalEngine::fullBuild(), and
 * never update(), which rebuilds the graph whole when another version of the extractors built it,
 * nor uses AutoRefreshesProjectGraph, whose refreshProjectGraph() calls update().
 *
 * The rule reads one file at a time: update() counts only in a file that names IncrementalEngine,
 * so an engine held in a property typed in another file passes it. Today UiFlowCheck is the only
 * engine user under Ai\Verify.
 */
final class VerifyNeverRebuildsTheGraphTest extends TestCase
{
    #[Test]
    public function no_verify_class_rebuilds_the_project_graph(): void
    {
        $root = dirname(__DIR__, 4) . '/src/Application/Service/Ai/Verify';
        self::assertDirectoryExists($root);
        $found = [];
        $read = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source, $file->getPathname() . ' cannot be read');
            $read++;
            foreach (self::rebuilds($source) as $call) {
                $found[] = substr($file->getPathname(), strlen($root) + 1) . ': ' . $call;
            }
        }

        self::assertGreaterThan(50, $read, 'the verify classes were read');
        self::assertSame([], $found, 'refresh the graph through updateUnlessBusy() behind isCurrentBuild(), as UiFlowCheck does');
    }

    #[Test]
    public function a_full_build_or_an_engine_update_is_found_and_nothing_else_is(): void
    {
        $engine = "<?php\nuse Semitexa\\ProjectGraph\\Application\\Service\\Index\\IncrementalEngine;\n";

        self::assertSame(['line 3: ->fullBuild('], self::rebuilds($engine . "\$engine->fullBuild(\$root);\n"));
        self::assertSame(['line 3: ->update('], self::rebuilds($engine . "\$engine?->update(\$root);\n"));
        self::assertSame(['line 2: ->fullBuild('], self::rebuilds("<?php\n\$graph->fullBuild(\$root);\n"), 'a full build is refused whatever the receiver');
        self::assertSame(['line 2: ->update('], self::rebuilds("<?php\n(new \\Semitexa\\ProjectGraph\\Application\\Service\\Index\\IncrementalEngine(\$a, \$b))->update(\$root);\n"), 'and the engine named by its full name');
        self::assertSame(['line 2: AutoRefreshesProjectGraph'], self::rebuilds("<?php\nuse Semitexa\\ProjectGraph\\Application\\Service\\Support\\AutoRefreshesProjectGraph;\nfinal class Check\n{\n    use AutoRefreshesProjectGraph;\n}\n"), 'the trait whose refresh calls update()');
        // Review, semitexa-dev#317: a grouped import names the trait as a bare name.
        self::assertSame(['line 2: AutoRefreshesProjectGraph'], self::rebuilds("<?php\nuse Semitexa\\ProjectGraph\\Application\\Service\\Support\\{AutoRefreshesProjectGraph};\nfinal class Check\n{\n    use AutoRefreshesProjectGraph;\n}\n"), 'and imported in a group');
        self::assertSame([], self::rebuilds("<?php\nfinal class Check\n{\n    private string \$why = 'AutoRefreshesProjectGraph';\n}\n"), 'its name in a string is no use of it');

        self::assertSame([], self::rebuilds($engine . "if (\$engine->isCurrentBuild()) {\n    \$engine->updateUnlessBusy(\$root);\n}\n"));
        self::assertSame([], self::rebuilds("<?php\n\$receipts->update(\$id, \$row);\n"), 'update() of anything in a file that names no engine is not the engine\'s');
        self::assertSame([], self::rebuilds($engine . "// \$engine->fullBuild(\$root) is minutes\n\$why = '->update( rebuilds';\n"), 'a comment or a string calls nothing');
    }

    /**
     * The calls in $source that rebuild the graph: fullBuild() on anything, and update() in a file
     * that names IncrementalEngine, each as "line N: ->name(".
     *
     * @return list<string>
     */
    private static function rebuilds(string $source): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
        $namesTheEngine = false;
        $found = [];
        foreach ($tokens as $token) {
            $namesTheEngine = $namesTheEngine || ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && str_ends_with($token->text, 'IncrementalEngine'));
            // Its refreshProjectGraph() calls update(). Named by its import (grouped ones too), its
            // full name or its short name; reported once a file.
            $trait = $token->is(T_STRING) ? $token->text === 'AutoRefreshesProjectGraph' : str_ends_with($token->text, '\\AutoRefreshesProjectGraph');
            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && $trait && $found === []) {
                $found[] = sprintf('line %d: AutoRefreshesProjectGraph', $token->line);
            }
        }
        foreach ($tokens as $i => $token) {
            $name = $tokens[$i + 1] ?? null;
            $open = $tokens[$i + 2] ?? null;
            if (!$token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) || $name === null || !$name->is(T_STRING) || $open?->text !== '(') {
                continue;
            }
            if (strcasecmp($name->text, 'fullBuild') === 0 || ($namesTheEngine && strcasecmp($name->text, 'update') === 0)) {
                $found[] = sprintf('line %d: ->%s(', $name->line, $name->text);
            }
        }

        return $found;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Context;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Context\ContextPacker;
use Semitexa\Dev\Application\Service\Ai\Recipe\Recipe;

/**
 * Prior-art discovery, over a fixture tree rather than the real repository.
 *
 * Written against two bugs that both produced the same symptom — ai:context
 * reporting greenfield next to a repository full of prior art:
 *
 *   1. Only `src/modules` was walked. In the framework workspace, where all the
 *      code lives under `packages/*`, that is nearly the whole answer missing.
 *   2. Signals are written in NAMESPACE form (`Application\Console`) and were
 *      matched against PATHS, so any signal containing a separator matched
 *      nothing at all. `add_cli_command` scored ONE file in the whole
 *      repository before this; ten after.
 *
 * A fixture tree, not the repository: an assertion about real files silently
 * changes meaning as the repository does, and the point here is to pin
 * behaviour, not to re-measure the workspace.
 */
final class ContextPackerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/context-packer-' . bin2hex(random_bytes(6));

        $this->write('src/modules/Shop/src/Application/Console/Command/ShopSyncCommand.php');
        $this->write('src/modules/Shop/src/Domain/Service/ShopPricer.php');
        $this->write('packages/semitexa-mail/src/Application/Console/Command/MailWorkCommand.php');
        $this->write('packages/semitexa-mail/src/Domain/Service/MailSender.php');
        // A package's own tests are not prior art for writing new code.
        $this->write('packages/semitexa-mail/tests/Unit/Application/Console/Command/MailWorkCommandTest.php');
        // Nor is anything outside a package's src/.
        $this->write('packages/semitexa-mail/resources/Application/Console/notes.php');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->removeTree($this->root);
        }
    }

    /**
     * The bug the epic was filed for: in a workspace whose code lives under
     * packages/, walking only src/modules answers "greenfield".
     */
    #[Test]
    public function prior_art_is_found_in_packages_as_well_as_application_modules(): void
    {
        $paths = $this->pathsFor(['Domain\\Service']);

        self::assertContains('packages/semitexa-mail/src/Domain/Service/MailSender.php', $paths);
        self::assertContains('src/modules/Shop/src/Domain/Service/ShopPricer.php', $paths);
    }

    /**
     * A signal written the way the recipe registry writes it — with namespace
     * separators — has to match a path. Every multi-segment signal in the
     * registry matched nothing until it did.
     */
    #[Test]
    public function a_namespace_shaped_signal_matches_a_path(): void
    {
        $paths = $this->pathsFor(['Application\\Console']);

        self::assertContains('packages/semitexa-mail/src/Application/Console/Command/MailWorkCommand.php', $paths);
        self::assertContains('src/modules/Shop/src/Application/Console/Command/ShopSyncCommand.php', $paths);
    }

    /**
     * A package's own test suite would otherwise outrank the implementation it
     * tests, and its resources are not code to imitate either.
     */
    #[Test]
    public function only_a_packages_production_source_counts_as_prior_art(): void
    {
        $paths = $this->pathsFor(['Application\\Console']);

        self::assertNotContains(
            'packages/semitexa-mail/tests/Unit/Application/Console/Command/MailWorkCommandTest.php',
            $paths,
        );
        self::assertNotContains('packages/semitexa-mail/resources/Application/Console/notes.php', $paths);
    }

    /**
     * The module hint has to reach a package. An agent naming "mail" means the
     * package whose directory is semitexa-mail; requiring the exact directory
     * name would make the hint useless in the workspace it matters in.
     */
    #[Test]
    public function the_module_hint_reaches_a_package_under_either_name(): void
    {
        foreach (['mail', 'semitexa-mail'] as $hint) {
            $items = (new ContextPacker($this->root))->pack($this->recipe(['Domain\\Service']), $hint);
            $hinted = array_values(array_filter(
                $items,
                static fn (object $i): bool => $i->path === 'packages/semitexa-mail/src/Domain/Service/MailSender.php',
            ));

            self::assertCount(1, $hinted, "hint '{$hint}' did not reach the package");
            self::assertStringContainsString('in module ' . $hint, $hinted[0]->why);
            self::assertGreaterThan(3, $hinted[0]->score, "hint '{$hint}' did not raise the score");
        }
    }

    /**
     * Nothing found and stopped looking are different answers. A cap that
     * quietly shortens the result is indistinguishable from an empty
     * repository, which is the failure this whole class keeps producing.
     */
    #[Test]
    public function a_complete_scan_reports_no_truncation(): void
    {
        $packer = new ContextPacker($this->root);
        $packer->pack($this->recipe(['Domain\\Service']));

        self::assertSame([], $packer->truncatedRoots());
    }

    /** @return list<string> */
    private function pathsFor(array $signals): array
    {
        return array_map(
            static fn (object $item): string => $item->path,
            (new ContextPacker($this->root))->pack($this->recipe($signals)),
        );
    }

    /** @param list<string> $signals */
    private function recipe(array $signals): Recipe
    {
        return new Recipe(
            id: 'fixture',
            label: 'fixture',
            summary: 'fixture recipe used only by this test',
            keywords: [],
            verbs: [],
            generator_chain: [],
            context_signals: $signals,
            arg_hints: [],
        );
    }

    private function write(string $relative): void
    {
        $path = $this->root . '/' . $relative;
        @mkdir(dirname($path), 0o777, true);
        file_put_contents($path, "<?php\n");
    }

    private function removeTree(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

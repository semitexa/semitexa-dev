<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\VerifyReceipts;
use Semitexa\Dev\Application\Service\Ai\Verify\UnselectedChanges;

/**
 * A pass over `--files=A` is not a pass over B beside it. Measured
 * 2026-11-02: with a file reading an undefined variable left next to the
 * selection, the verdict was `pass` and the receipt "held for the tree in
 * front of you". Against real repositories, as the dirty scan is tested.
 */
final class UnselectedChangesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        // git ships in the image this runs in: no skip for a runtime we never ship.
        $this->root = sys_get_temp_dir() . '/semitexa-unselected-' . uniqid('', true);
        foreach (['one', 'two'] as $package) {
            $dir = $this->root . '/packages/semitexa-' . $package;
            mkdir($dir . '/src/Deep', 0777, true);
            file_put_contents($dir . '/src/Kept.php', "<?php\n");
            $q = escapeshellarg($dir);
            exec("git -C {$q} init -q 2>&1");
            exec("git -C {$q} -c safe.directory={$q} add -A 2>&1");
            exec("git -C {$q} -c safe.directory={$q} -c user.email=probe@example.com -c user.name=Probe -c commit.gpgsign=false commit -q -m base 2>&1");
        }
        // Changed after the base commit: the selection, one beside it, one in
        // another repository, one under a selected directory, and a file of the
        // unversioned project root.
        $this->write('packages/semitexa-one/src/Selected.php');
        $this->write('packages/semitexa-one/src/Beside.php');
        $this->write('packages/semitexa-one/src/Deep/Inner.php');
        $this->write('packages/semitexa-two/src/Elsewhere.php');
        $this->write('src/modules/App/Root.php');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function write(string $relative): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, "<?php\n");
    }

    #[Test]
    public function the_changes_beside_a_selection_in_its_repository_are_named_and_no_others(): void
    {
        self::assertSame(
            [['path' => 'packages/semitexa-one/src/Beside.php', 'status' => 'A'], ['path' => 'packages/semitexa-one/src/Deep/Inner.php', 'status' => 'A']],
            UnselectedChanges::beside($this->root, ['packages/semitexa-one/src/Selected.php']),
        );
    }

    #[Test]
    public function a_selected_directory_covers_what_is_under_it(): void
    {
        self::assertSame(
            [['path' => 'packages/semitexa-one/src/Beside.php', 'status' => 'A'], ['path' => 'packages/semitexa-one/src/Selected.php', 'status' => 'A']],
            UnselectedChanges::beside($this->root, ['packages/semitexa-one/src/Deep/']),
        );
    }

    /** The authoring workspace's root is no repository: nothing there can be asked, or named. */
    #[Test]
    public function a_selection_no_repository_holds_names_nothing(): void
    {
        self::assertSame([], UnselectedChanges::beside($this->root, ['src/modules/App/Root.php']));
        self::assertSame([], UnselectedChanges::beside($this->root, []));
    }

    #[Test]
    public function the_headline_says_what_the_pass_does_not_cover(): void
    {
        $gap = UnselectedChanges::annotate(['headline' => '3 checks over 1 changed file', 'unchecked_files' => []], [['path' => 'packages/semitexa-one/src/Beside.php', 'status' => 'A']]);

        self::assertSame('3 checks over 1 changed file; 1 other uncommitted change in the same repository was not selected, so no check read it: packages/semitexa-one/src/Beside.php', $gap['headline']);
        self::assertSame([['path' => 'packages/semitexa-one/src/Beside.php', 'status' => 'A']], $gap['unselected_changes']);
        self::assertSame('x', UnselectedChanges::annotate(['headline' => 'x', 'unchecked_files' => []], [])['headline'], 'nothing left out, nothing added');
    }

    /** The receipt check answers for the tree as it stands: what is beside the checked files now. */
    #[Test]
    public function a_receipt_check_names_the_changes_beside_the_files_it_checked(): void
    {
        $receipts = new VerifyReceipts($this->root);
        $envelope = $receipts->attach([
            'generated_at' => '2026-11-02T06:00:00+00:00',
            'verdict' => 'pass',
            'changed_files' => [['path' => 'packages/semitexa-one/src/Selected.php']],
            'results' => [],
        ], []);
        $id = $envelope['receipt']['id'] ?? null;
        self::assertIsString($id);

        $check = $receipts->check($id);
        self::assertSame([], $check['changed_since']);
        self::assertSame(['packages/semitexa-one/src/Beside.php', 'packages/semitexa-one/src/Deep/Inner.php'], $check['not_checked']);
    }
}

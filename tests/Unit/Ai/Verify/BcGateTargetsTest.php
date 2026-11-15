<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\BcGateTargets;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;

/**
 * A change to a package's @api file runs the release's BC gate at the edit
 * (tk-stab-bc-check-gate): one target per package, chosen by @api in the file
 * now or at HEAD, in the framework workspace only.
 */
final class BcGateTargetsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/bc-gate-targets-' . bin2hex(random_bytes(4));
        mkdir(dirname($this->root . '/' . BcGateTargets::GATE_SCRIPT), 0777, true);
        file_put_contents($this->root . '/' . BcGateTargets::GATE_SCRIPT, '<?php');
        $this->file('packages/semitexa-core/src/Request.php', "<?php\n/** @api */\nfinal class Request {}\n");
        $this->file('packages/semitexa-core/src/Auth/Guard.php', "<?php\ninterface Guard {\n    /** @api */\n    public function check(): bool;\n}\n");
        $this->file('packages/semitexa-core/src/Internal.php', "<?php\nfinal class Internal {}\n");
        $this->file('packages/semitexa-orm/src/Query.php', "<?php\n/** @api */\nfinal class Query {}\n");
        $this->file('packages/semitexa-orm/tests/QueryTest.php', "<?php\n/** @api */\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function one_target_per_package_whose_changed_file_declares_api(): void
    {
        $this->commitPackage('semitexa-core');
        $this->commitPackage('semitexa-orm');
        $targets = $this->targets([
            'packages/semitexa-core/src/Request.php',
            'packages/semitexa-core/src/Auth/Guard.php',
            'packages/semitexa-core/src/Internal.php',
            'packages/semitexa-orm/src/Query.php',
            'packages/semitexa-orm/tests/QueryTest.php',
        ]);

        self::assertSame(['bc_gate:semitexa-core', 'bc_gate:semitexa-orm'], array_map(static fn (VerificationTarget $t): string => $t->id, $targets));
        self::assertSame(VerificationTarget::TYPE_BC_GATE, $targets[0]->type);
        self::assertSame('packages/semitexa-core', $targets[0]->filePath);
        self::assertSame(['packages/semitexa-core/src/Request.php', 'packages/semitexa-core/src/Auth/Guard.php'], $targets[0]->triggeredBy, 'a file without @api triggers nothing');
        self::assertSame(['packages/semitexa-orm/src/Query.php'], $targets[1]->triggeredBy, 'a test is not the package surface');
    }

    #[Test]
    public function a_file_that_lost_its_api_or_was_deleted_still_runs_the_gate(): void
    {
        $package = $this->root . '/packages/semitexa-core';
        $this->commitPackage('semitexa-core');
        $this->file('packages/semitexa-core/src/Request.php', "<?php\nfinal class Request {}\n");
        unlink($package . '/src/Auth/Guard.php');

        $targets = BcGateTargets::of([
            new ChangedFile('packages/semitexa-core/src/Request.php', ChangedFile::KIND_PHP_OTHER),
            new ChangedFile('packages/semitexa-core/src/Auth/Guard.php', ChangedFile::KIND_PHP_OTHER, ChangedFile::STATUS_DELETED),
            new ChangedFile('packages/semitexa-core/src/Internal.php', ChangedFile::KIND_PHP_OTHER),
        ], VerificationPlan::SCOPE_STANDARD, $this->root);

        self::assertCount(1, $targets);
        self::assertSame(['packages/semitexa-core/src/Request.php', 'packages/semitexa-core/src/Auth/Guard.php'], $targets[0]->triggeredBy);
    }

    /** Review, dev#265: the old path of a rename may be the one that carried @api, in another package. */
    #[Test]
    public function a_rename_is_judged_at_its_old_path_too(): void
    {
        $this->commitPackage('semitexa-core');
        rename($this->root . '/packages/semitexa-core/src/Request.php', $this->root . '/packages/semitexa-core/src/LegacyRequest.php');
        $this->file('packages/semitexa-core/src/LegacyRequest.php', "<?php\nfinal class LegacyRequest {}\n");

        $targets = BcGateTargets::of([
            new ChangedFile('packages/semitexa-core/src/LegacyRequest.php', ChangedFile::KIND_PHP_OTHER, ChangedFile::STATUS_RENAMED, 'packages/semitexa-core/src/Request.php'),
        ], VerificationPlan::SCOPE_STANDARD, $this->root);

        self::assertSame(['bc_gate:semitexa-core'], array_map(static fn (VerificationTarget $t): string => $t->id, $targets));
        self::assertSame(['packages/semitexa-core/src/LegacyRequest.php'], $targets[0]->triggeredBy);
    }

    /** Review, dev#265: a HEAD git cannot read is not "no @api at HEAD"; the gate runs and says what it could not check. */
    #[Test]
    public function a_head_git_cannot_read_runs_the_gate(): void
    {
        // packages/semitexa-core is no git repository here: git show fails for another reason than a missing path.
        $targets = BcGateTargets::of([
            new ChangedFile('packages/semitexa-core/src/Internal.php', ChangedFile::KIND_PHP_OTHER),
        ], VerificationPlan::SCOPE_STANDARD, $this->root);
        self::assertSame(['bc_gate:semitexa-core'], array_map(static fn (VerificationTarget $t): string => $t->id, $targets));

        // A path HEAD simply does not have is a new file: nothing it could break.
        $this->commitPackage('semitexa-core');
        $this->file('packages/semitexa-core/src/Fresh.php', "<?php\nfinal class Fresh {}\n");
        self::assertSame([], BcGateTargets::of([
            new ChangedFile('packages/semitexa-core/src/Fresh.php', ChangedFile::KIND_PHP_OTHER, ChangedFile::STATUS_ADDED),
            new ChangedFile('packages/semitexa-core/src/Internal.php', ChangedFile::KIND_PHP_OTHER),
        ], VerificationPlan::SCOPE_STANDARD, $this->root));
    }

    #[Test]
    public function nothing_outside_the_workspace_or_at_the_minimal_scope(): void
    {
        $files = [new ChangedFile('packages/semitexa-core/src/Request.php', ChangedFile::KIND_PHP_OTHER)];

        self::assertCount(1, BcGateTargets::of($files, VerificationPlan::SCOPE_STANDARD, $this->root), 'the precondition: this change is gated');
        self::assertSame([], BcGateTargets::of($files, VerificationPlan::SCOPE_MINIMAL, $this->root));
        unlink($this->root . '/' . BcGateTargets::GATE_SCRIPT);
        self::assertSame([], BcGateTargets::of($files, VerificationPlan::SCOPE_STANDARD, $this->root), 'a consumer project has no gate script');
    }

    /**
     * @param list<string> $paths
     * @return list<VerificationTarget>
     */
    private function targets(array $paths): array
    {
        return BcGateTargets::of(
            array_map(static fn (string $p): ChangedFile => new ChangedFile($p, ChangedFile::KIND_PHP_OTHER), $paths),
            VerificationPlan::SCOPE_STANDARD,
            $this->root,
        );
    }

    private function commitPackage(string $package): void
    {
        $dir = escapeshellarg($this->root . '/packages/' . $package);
        exec("git -C {$dir} init -q && git -C {$dir} -c user.email=t@t -c user.name=t add -A && git -C {$dir} -c user.email=t@t -c user.name=t commit -qm init", $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }

    private function file(string $path, string $content): void
    {
        @mkdir(dirname($this->root . '/' . $path), 0777, true);
        file_put_contents($this->root . '/' . $path, $content);
    }
}

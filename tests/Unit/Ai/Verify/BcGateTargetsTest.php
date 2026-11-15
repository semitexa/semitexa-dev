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
 * A change to a package that promised @api at its last tag runs the release's
 * BC gate at the edit (tk-stab-bc-check-gate): one target per package, for any
 * src PHP or composer.json change — an @api class breaks through an unmarked
 * parent or trait too (independent review, dev#265) — in the framework
 * workspace only.
 */
final class BcGateTargetsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/bc-gate-targets-' . bin2hex(random_bytes(4));
        mkdir(dirname($this->root . '/' . BcGateTargets::GATE_SCRIPT), 0777, true);
        file_put_contents($this->root . '/' . BcGateTargets::GATE_SCRIPT, '<?php');
        // Promised @api at its last tag; Base.php carries no marker.
        $this->package('semitexa-core', ['src/Request.php' => "<?php\n/** @api */\nfinal class Request extends Base {}\n", 'src/Base.php' => "<?php\nclass Base { public function read(): void {} }\n"], tag: '2026.11.01.0000');
        // Tagged, but with no @api in src at that tag: nothing promised.
        $this->package('semitexa-orm', ['src/Query.php' => "<?php\nfinal class Query {}\n"], tag: '2026.11.01.0000');
        // @api written, never tagged: nothing promised yet.
        $this->package('semitexa-ssr', ['src/View.php' => "<?php\n/** @api */\nfinal class View {}\n"], tag: null);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    // verify:accept-test-change the trigger is no longer @api in the changed file but @api at the package's last tag (independent review, dev#265); the per-file cases it replaced judged the old rule
    #[Test]
    public function any_source_change_of_a_package_that_promised_api_runs_its_gate(): void
    {
        $targets = $this->targets([
            'packages/semitexa-core/src/Base.php',
            'packages/semitexa-core/composer.json',
            'packages/semitexa-core/tests/RequestTest.php',
            'packages/semitexa-orm/src/Query.php',
            'packages/semitexa-ssr/src/View.php',
        ]);

        self::assertSame(['bc_gate:semitexa-core'], array_map(static fn (VerificationTarget $t): string => $t->id, $targets), 'orm promised nothing at its tag, ssr was never tagged');
        self::assertSame(VerificationTarget::TYPE_BC_GATE, $targets[0]->type);
        self::assertSame('packages/semitexa-core', $targets[0]->filePath);
        self::assertSame(['packages/semitexa-core/src/Base.php', 'packages/semitexa-core/composer.json'], $targets[0]->triggeredBy, 'an unmarked parent and the autoload count; a test does not');
    }

    #[Test]
    public function a_rename_out_of_a_package_is_judged_where_it_came_from(): void
    {
        $targets = BcGateTargets::of([
            new ChangedFile('packages/semitexa-ssr/src/Base.php', ChangedFile::KIND_PHP_OTHER, ChangedFile::STATUS_RENAMED, 'packages/semitexa-core/src/Base.php'),
        ], VerificationPlan::SCOPE_STANDARD, $this->root);

        self::assertSame(['bc_gate:semitexa-core'], array_map(static fn (VerificationTarget $t): string => $t->id, $targets));
        self::assertSame(['packages/semitexa-ssr/src/Base.php'], $targets[0]->triggeredBy);
    }

    #[Test]
    public function a_package_whose_tags_git_cannot_read_runs_the_gate(): void
    {
        mkdir($this->root . '/packages/semitexa-loose/src', 0777, true);
        file_put_contents($this->root . '/packages/semitexa-loose/src/A.php', "<?php\nfinal class A {}\n");

        $targets = $this->targets(['packages/semitexa-loose/src/A.php']);

        self::assertSame(['bc_gate:semitexa-loose'], array_map(static fn (VerificationTarget $t): string => $t->id, $targets), 'no git repository: the gate says what it could not check');
    }

    #[Test]
    public function nothing_outside_the_workspace_or_at_the_minimal_scope(): void
    {
        $files = [new ChangedFile('packages/semitexa-core/src/Base.php', ChangedFile::KIND_PHP_OTHER)];

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

    /** @param array<string, string> $files */
    private function package(string $name, array $files, ?string $tag): void
    {
        $dir = $this->root . '/packages/' . $name;
        foreach ($files as $path => $content) {
            @mkdir(dirname($dir . '/' . $path), 0777, true);
            file_put_contents($dir . '/' . $path, $content);
        }
        $git = 'git -C ' . escapeshellarg($dir) . ' -c user.email=t@t -c user.name=t ';
        exec($git . 'init -q && ' . $git . 'add -A && ' . $git . 'commit -qm init' . ($tag === null ? '' : ' && ' . $git . 'tag ' . escapeshellarg($tag)), $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFileClassifier;
use Semitexa\Dev\Application\Service\Ai\Verify\ContractMoveResolver;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlanner;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;

/**
 * Target selection pinned END TO END: a real path in, the lint set it must
 * trigger out.
 *
 * The two halves were already covered separately — ChangedFileClassifierTest
 * pins path → kind, VerificationPlannerTest pins kind → lints — but not for
 * every kind, and never joined up. That split is how the Application/Service
 * defect survived: each half looked tested, and the seam between them was
 * where a service diff selected no lint:di at all and two commits shipped
 * against a green 11/11 with zero skipped.
 *
 * The failure mode has no symptom. A rule that stops matching does not error;
 * it produces a smaller plan, and a smaller plan passes faster. So this walks
 * the whole KIND_LINT_MAP from the outside, and the coverage assertion below
 * fails when a kind is added to the map without a case here — otherwise the
 * next kind is unpinned in exactly the same silence.
 */
final class VerifyTargetSelectionEndToEndTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-verify-e2e-' . uniqid();
        mkdir($this->root . '/tests', 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            foreach ((array) scandir($this->root) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $this->root . '/' . $entry;
                is_dir($path) ? @rmdir($path) : @unlink($path);
            }
            @rmdir($this->root);
        }
    }

    /**
     * Every path is written twice — once under a package source tree, once
     * under `src/modules` — because the framework's own code lives in the first and
     * an application's in the second, and a rule that quietly matched only one
     * of them would leave half the tree unverified.
     *
     * @return array<string, array{string, string, list<string>}>
     */
    public static function selectionProvider(): array
    {
        $cases = [
            'handler' => [
                'Application/Handler/PayloadHandler/GetThingHandler.php',
                ChangedFile::KIND_HANDLER,
                ['lint:di', 'lint:handlers'],
            ],
            'domain listener' => [
                'Application/Handler/DomainListener/ThingChangedListener.php',
                ChangedFile::KIND_LISTENER,
                ['lint:di', 'lint:scoping'],
            ],
            'server lifecycle listener' => [
                // Ordering-sensitive: this fragment must stay ABOVE the generic
                // Application/Service/ rule or a lifecycle listener silently
                // becomes a service and loses nothing visible — both kinds
                // select lint:di, so only lint:scoping vs lint:handlers would
                // ever have shown it.
                'Application/Service/Server/Lifecycle/WireThingListener.php',
                ChangedFile::KIND_LISTENER,
                ['lint:di', 'lint:scoping'],
            ],
            'payload' => [
                'Application/Payload/Request/GetThingPayload.php',
                ChangedFile::KIND_PAYLOAD,
                ['lint:di', 'lint:responses'],
            ],
            'resource' => [
                'Application/Resource/Response/ThingResource.php',
                ChangedFile::KIND_RESOURCE,
                ['lint:responses'],
            ],
            'application service' => [
                'Application/Service/Thing/ThingCatalog.php',
                ChangedFile::KIND_SERVICE,
                ['lint:di', 'lint:scoping'],
            ],
            'domain service' => [
                'Domain/Service/ThingPolicy.php',
                ChangedFile::KIND_SERVICE,
                ['lint:di', 'lint:scoping'],
            ],
            'contract' => [
                // A contract change auto-expands the scope to broad, so it
                // selects EVERY lint rather than the ['lint:di'] its map entry
                // names. Pinned at the widened set on purpose: the risk with a
                // contract is a plan that narrows, and narrowing is exactly what
                // would not be noticed.
                'Domain/Contract/ThingRepositoryInterface.php',
                ChangedFile::KIND_CONTRACT,
                [
                    'lint:deferred-twig',
                    'lint:di',
                    'lint:handlers',
                    'lint:mechanisms',
                    'lint:responses',
                    'lint:scoping',
                    'lint:templates',
                ],
            ],
        ];

        $out = [];
        foreach ($cases as $name => [$suffix, $kind, $lints]) {
            $out[$name . ' (package)'] = ['packages/semitexa-ssr/src/' . $suffix, $kind, $lints];
            $out[$name . ' (module)'] = ['src/modules/Foo/src/' . $suffix, $kind, $lints];
        }

        return $out;
    }

    /**
     * @param list<string> $expectedLints
     */
    #[Test]
    #[DataProvider('selectionProvider')]
    public function a_path_selects_the_lints_its_kind_is_meant_to_trigger(
        string $path,
        string $expectedKind,
        array $expectedLints,
    ): void {
        $classified = (new ChangedFileClassifier())->classify($path);
        self::assertSame($expectedKind, $classified->kind, "classification of {$path}");

        $plan = $this->planner()->plan([$classified], VerificationPlan::SCOPE_STANDARD);

        $commands = $this->lintCommandNames($plan);
        sort($commands);

        self::assertSame($expectedLints, $commands, "lints selected for {$path}");
    }

    #[Test]
    public function a_client_script_selects_the_mechanism_lint(): void
    {
        // The only kind decided by extension rather than by directory, and the
        // only one whose whole point is catching a framework mechanism rebuilt
        // by hand in the browser. Unpinned on both sides until now.
        $classified = (new ChangedFileClassifier())->classify('packages/semitexa-ssr/src/Application/Static/js/disclosure.js');
        self::assertSame(ChangedFile::KIND_CLIENT_SCRIPT, $classified->kind);

        $plan = $this->planner()->plan([$classified], VerificationPlan::SCOPE_STANDARD);

        self::assertSame(['lint:mechanisms'], $this->lintCommandNames($plan));
    }

    #[Test]
    public function every_kind_in_the_lint_map_is_covered_here(): void
    {
        $map = new \ReflectionClassConstant(VerificationPlanner::class, 'KIND_LINT_MAP');
        /** @var array<string, list<string>> $lintMap */
        $lintMap = $map->getValue();

        $covered = [ChangedFile::KIND_CLIENT_SCRIPT];
        foreach (self::selectionProvider() as [$_path, $kind, $_lints]) {
            $covered[] = $kind;
        }
        // Templates are pinned by their own dedicated suite, which knows about
        // deferred-twig; duplicating that here would drift from it.
        $covered[] = ChangedFile::KIND_TEMPLATE;

        $missing = array_values(array_diff(array_keys($lintMap), array_unique($covered)));

        self::assertSame([], $missing, sprintf(
            'KIND_LINT_MAP gained %s with no end-to-end case. An unpinned kind cannot fail loudly — '
            . 'a rule that stops matching just makes the plan smaller, and a smaller plan passes faster.',
            implode(', ', $missing),
        ));
    }

    private function planner(): VerificationPlanner
    {
        return new VerificationPlanner(
            $this->root,
            new ChangedFileClassifier(),
            null,
            new ContractMoveResolver($this->root),
        );
    }

    /** @return list<string> */
    private function lintCommandNames(VerificationPlan $plan): array
    {
        $names = [];
        foreach ($plan->targets as $target) {
            if ($target->type === VerificationTarget::TYPE_LINT && $target->commandName !== null) {
                $names[] = $target->commandName;
            }
        }

        return array_values(array_unique($names));
    }
}

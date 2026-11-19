<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\UiFlow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Console\Command\AiVerifyCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\CoverageGap;
use Semitexa\Dev\Application\Service\Ai\Verify\UiFlowCheck;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationExecutor;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlanner;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationResult;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;
use Semitexa\Dev\Application\Service\Ai\Verify\VerifyReportSerializer;
use Semitexa\Dev\Tests\Support\JsonRead;
use Semitexa\Dev\Tests\Support\UiFlowShop;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Symfony\Component\Console\Application;

/**
 * `lint:ui-flow` in ai:verify (tk-ui-flow-pr2-panel-and-verify): planned when
 * a template, a payload or a handler changes, reported for the changed
 * modules alone, and never able to fail the run.
 */
final class UiFlowWarningTest extends TestCase
{
    private UiFlowShop $shop;

    protected function setUp(): void
    {
        $this->shop = UiFlowShop::built();
    }

    protected function tearDown(): void
    {
        $this->shop->remove();
    }

    #[Test]
    public function a_template_a_payload_or_a_handler_change_plans_it_and_nothing_else_does(): void
    {
        $planner = new VerificationPlanner($this->shop->root);
        $planned = static function (ChangedFile $file, string $scope = VerificationPlan::SCOPE_STANDARD) use ($planner): array {
            $found = [];
            foreach ($planner->plan([$file], $scope)->targets as $target) {
                if ($target->id === 'lint:ui-flow') {
                    $found[] = [$target->type, $target->triggeredBy, $target->commandName];
                }
            }

            return $found;
        };

        foreach ([
            UiFlowShop::ORDERS_TEMPLATE => ChangedFile::KIND_TEMPLATE,
            'src/modules/Shop/Application/Payload/Request/OrdersPayload.php' => ChangedFile::KIND_PAYLOAD,
            'src/modules/Shop/Application/Handler/PayloadHandler/OrdersHandler.php' => ChangedFile::KIND_HANDLER,
        ] as $path => $kind) {
            self::assertSame([['ui_flow', [$path], null]], $planned(new ChangedFile($path, $kind)), $kind);
        }
        // Removing a route is how a link elsewhere goes dead.
        self::assertCount(1, $planned(new ChangedFile(UiFlowShop::ORDERS_TEMPLATE, ChangedFile::KIND_TEMPLATE, ChangedFile::STATUS_DELETED)));

        // A template renamed to something that is none of the three took its links and forms with it:
        // the path it had is a trigger, and a move between modules names both.
        self::assertSame(
            [['ui_flow', [UiFlowShop::ORDERS_TEMPLATE], null]],
            $planned(new ChangedFile('src/modules/Shop/notes.txt', ChangedFile::KIND_NON_PHP, ChangedFile::STATUS_RENAMED, UiFlowShop::ORDERS_TEMPLATE)),
        );
        self::assertSame(
            [['ui_flow', [UiFlowShop::BLOG_TEMPLATE, UiFlowShop::ORDERS_TEMPLATE], null]],
            $planned(new ChangedFile(UiFlowShop::BLOG_TEMPLATE, ChangedFile::KIND_TEMPLATE, ChangedFile::STATUS_RENAMED, UiFlowShop::ORDERS_TEMPLATE)),
        );
        self::assertSame([], $planned(new ChangedFile('src/modules/Shop/b.txt', ChangedFile::KIND_NON_PHP, ChangedFile::STATUS_RENAMED, 'src/modules/Shop/a.txt')));

        self::assertSame([], $planned(new ChangedFile('src/modules/Shop/Application/Service/Pricing.php', ChangedFile::KIND_SERVICE)));
        // It reads the project graph: with the lints, not in a minimal run.
        self::assertSame([], $planned(new ChangedFile(UiFlowShop::ORDERS_TEMPLATE, ChangedFile::KIND_TEMPLATE), VerificationPlan::SCOPE_MINIMAL));
    }

    #[Test]
    public function it_warns_about_the_changed_module_alone_and_the_run_still_passes(): void
    {
        $result = $this->warn([UiFlowShop::ORDERS_TEMPLATE]);

        self::assertSame([VerificationResult::STATUS_PASS, 0, false], [$result->status, $result->exitCode, $result->required]);
        // Shop's dead link and Shop's unreached POST; the Blog's /gone and /blog/draft are not this change's.
        self::assertSame(
            [
                ['form-to-missing-route', 'ui-flow.form-to-missing-route', 'warning', 'ui_flow', UiFlowShop::ORDERS_TEMPLATE, 1, 'Shop', 'GET /nowhere'],
                ['orphan-post', 'ui-flow.orphan-post', 'warning', 'ui_flow', UiFlowShop::SHOP_SOURCE, 15, 'Shop', 'POST /orders/archive'],
            ],
            array_map(static fn (array $d): array => [$d['rule'], $d['identifier'], $d['severity'], $d['check'], $d['file'], $d['line'], $d['module'], $d['subject']], $result->warnings),
        );
        // Warnings are not the run's violations: ai:verify lists a result's diagnostics as those.
        self::assertSame([], $result->diagnostics);
        self::assertStringContainsString('2 warning(s) in Shop, none fails the run', $result->signal);
        // What the project decided about is beside the warnings, with its reason.
        self::assertSame(
            [['orphan-post', 'POST /orders/import', 'Posted by the import script on the orders page.']],
            array_map(static fn (array $a): array => [$a['rule'], $a['subject'], $a['reason']], $result->accepted),
        );

        // The verdict of a run that holds these warnings and nothing else that failed.
        $other = new VerificationResult(new VerificationTarget('syntax', 'syntax:a', 'test', []), VerificationResult::STATUS_PASS, 0, 'clean');
        $verdict = new \ReflectionMethod(AiVerifyCommand::class, 'verdict');
        self::assertSame('pass', $verdict->invoke(new AiVerifyCommand(), [$other, $result]));
        self::assertSame('pass', $verdict->invoke(new AiVerifyCommand(), [$result]));
        $row = (new VerifyReportSerializer())->serializeResult($result);
        self::assertSame(['lint:ui-flow', 'pass', false, 2], [$row['id'], $row['status'], $row['required'], count(JsonRead::rows($row['warnings']))]);
        self::assertArrayNotHasKey('diagnostics_count', $row);
        $violations = new \ReflectionMethod(AiVerifyCommand::class, 'collectViolations');
        self::assertSame([], $violations->invoke(new AiVerifyCommand(), [$other, $result]));
        self::assertSame('Posted by the import script on the orders page.', JsonRead::rows($row['accepted'])[0]['reason']);
    }

    #[Test]
    public function another_modules_change_reports_that_module(): void
    {
        $result = $this->warn([UiFlowShop::BLOG_TEMPLATE]);

        self::assertSame(
            ['GET /gone', 'POST /blog/draft'],
            array_column($result->warnings, 'subject'),
        );
        self::assertSame([], $result->accepted);
    }

    /** Removing a route is how a link goes dead: the file is gone, its module is still the one to report. */
    #[Test]
    public function a_deleted_file_still_names_its_module(): void
    {
        unlink($this->shop->root . '/' . UiFlowShop::BLOG_SOURCE);
        clearstatcache();

        $result = $this->warn([UiFlowShop::BLOG_SOURCE]);

        self::assertSame(VerificationResult::STATUS_PASS, $result->status);
        self::assertStringContainsString('in Blog', $result->signal);
        // The blog's unreached POST went with the file; its template's dead link is still there.
        self::assertSame(['GET /gone'], array_column($result->warnings, 'subject'));
    }

    /**
     * A graph other extraction code built is rebuilt whole by a refresh:
     * minutes on a real project, and any change of semitexa/project-graph's
     * own source is enough. A check that only warns does not start that.
     */
    #[Test]
    public function a_graph_of_another_build_is_not_rebuilt_inside_verify(): void
    {
        $this->shop->storage->setMeta('build_version', 'built-by-older-extractors');
        $nodes = $this->shop->storage->nodes->countAll();
        $this->shop->write('src/modules/Shop/Application/More.php', "<?php\nnamespace Campaign\\Shop;\nfinal class More {}\n");

        $result = $this->warn([UiFlowShop::ORDERS_TEMPLATE]);

        self::assertSame([VerificationResult::STATUS_SKIPPED, 0, false], [$result->status, $result->exitCode, $result->required]);
        self::assertStringContainsString('ai:review-graph:generate', $result->signal);
        // Nothing was read or written: the new class is not in the graph, the version is as it was.
        self::assertSame($nodes, $this->shop->storage->nodes->countAll());
        self::assertNull($this->shop->storage->nodes->findById('class:Campaign\\Shop\\More'));
        self::assertSame('built-by-older-extractors', $this->shop->storage->getMeta('build_version'));
    }

    /** `ai:review-graph:generate` holds the graph's lock for minutes; every verify run would wait at this target. */
    #[Test]
    public function a_graph_another_process_is_writing_is_not_waited_for(): void
    {
        mkdir($this->shop->root . '/var/tmp', 0777, true);
        $held = fopen($this->shop->root . '/var/tmp/.project-graph.lock', 'c');
        self::assertIsResource($held);
        self::assertTrue(flock($held, LOCK_EX));
        try {
            $started = microtime(true);
            $result = $this->warn([UiFlowShop::ORDERS_TEMPLATE]);
            $waited = microtime(true) - $started;
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }

        self::assertSame([VerificationResult::STATUS_SKIPPED, false], [$result->status, $result->required]);
        self::assertStringContainsString('another process is writing the project graph', $result->signal);
        self::assertLessThan(5.0, $waited);

        // Released, the same check reads the graph.
        self::assertSame(VerificationResult::STATUS_PASS, $this->warn([UiFlowShop::ORDERS_TEMPLATE])->status);
    }

    /** Review, project-graph#39: a lock file this process cannot open is a setup to fix, said with its path, and still never fails the run. */
    #[Test]
    public function a_lock_that_cannot_be_opened_is_a_named_skip_not_a_failure(): void
    {
        mkdir($this->shop->root . '/var/tmp', 0777, true);
        symlink($this->shop->root . '/var/no-such-directory/lock', $this->shop->root . '/var/tmp/.project-graph.lock');

        $result = $this->warn([UiFlowShop::ORDERS_TEMPLATE]);

        self::assertSame([VerificationResult::STATUS_SKIPPED, false], [$result->status, $result->required]);
        self::assertStringContainsString('.project-graph.lock', $result->signal);
        self::assertStringNotContainsString('another process is writing', $result->signal);
    }

    #[Test]
    public function a_link_written_after_the_graph_was_built_is_read(): void
    {
        $this->shop->write(UiFlowShop::ORDERS_TEMPLATE, '<a href="/written-a-moment-ago">New</a>' . "\n");

        self::assertSame(['GET /written-a-moment-ago', 'POST /orders/archive'], array_column($this->warn([UiFlowShop::ORDERS_TEMPLATE])->warnings, 'subject'));
    }

    #[Test]
    public function whatever_goes_wrong_reading_the_graph_is_a_skip_that_fails_nothing(): void
    {
        $target = $this->target([UiFlowShop::ORDERS_TEMPLATE]);
        $verdict = new \ReflectionMethod(AiVerifyCommand::class, 'verdict');
        $other = new VerificationResult(new VerificationTarget('syntax', 'syntax:a', 'test', []), VerificationResult::STATUS_PASS, 0, 'clean');

        $cases = [
            'the graph cannot be opened' => [new UiFlowCheck(open: static fn (): GraphStorage => throw new \RuntimeException('SQLSTATE: unable to open database file'), projectRoot: $this->shop->root), 'unable to open database file'],
            'no connection in this context' => [new UiFlowCheck(projectRoot: $this->shop->root), 'no database connection'],
            'no check was given to the executor' => [null, 'no project graph connection'],
        ];
        foreach ($cases as $case => [$check, $says]) {
            $results = (new VerificationExecutor(new Application(), $this->shop->root, uiFlow: $check))->execute($this->plan([$target]));
            self::assertSame([VerificationResult::STATUS_SKIPPED, 0, false], [$results[0]->status, $results[0]->exitCode, $results[0]->required], $case);
            self::assertStringContainsString($says, $results[0]->signal, $case);
            self::assertSame('pass', $verdict->invoke(new AiVerifyCommand(), [$other, $results[0]]), $case);
        }

        // A graph never built is not built by a verify run.
        $this->shop->storage->setMeta('total_nodes', '0');
        $unbuilt = $this->warn([UiFlowShop::ORDERS_TEMPLATE]);
        self::assertSame([VerificationResult::STATUS_SKIPPED, false], [$unbuilt->status, $unbuilt->required]);
        self::assertStringContainsString('ai:review-graph:generate', $unbuilt->signal);
    }

    #[Test]
    public function a_file_only_this_warning_read_is_not_counted_as_checked(): void
    {
        $this->shop->write('notes.txt', 'x');
        $target = new VerificationTarget(VerificationTarget::TYPE_UI_FLOW, 'lint:ui-flow', 'r', ['notes.txt']);
        $plan = new VerificationPlan(VerificationPlan::SCOPE_STANDARD, VerificationPlan::SCOPE_STANDARD, [new ChangedFile('notes.txt', ChangedFile::KIND_NON_PHP)], [$target]);
        $passed = new VerificationResult($target, VerificationResult::STATUS_PASS, 0, 'ui flow: nothing to look at', required: false);

        self::assertSame(['notes.txt'], array_map(static fn (ChangedFile $file): string => $file->path, CoverageGap::of($plan, [$passed], $this->shop->root)->unchecked));
    }

    /** @param list<string> $changed */
    private function warn(array $changed): VerificationResult
    {
        $check = new UiFlowCheck(open: fn (): GraphStorage => $this->shop->storage, projectRoot: $this->shop->root);
        $results = (new VerificationExecutor(new Application(), $this->shop->root, uiFlow: $check))->execute($this->plan([$this->target($changed)]));
        self::assertCount(1, $results);

        return $results[0];
    }

    /** @param list<string> $changed */
    private function target(array $changed): VerificationTarget
    {
        return new VerificationTarget(VerificationTarget::TYPE_UI_FLOW, 'lint:ui-flow', 'a template changed', $changed);
    }

    /** @param list<VerificationTarget> $targets */
    private function plan(array $targets): VerificationPlan
    {
        return new VerificationPlan(
            scope: VerificationPlan::SCOPE_STANDARD,
            effectiveScope: VerificationPlan::SCOPE_STANDARD,
            changedFiles: [new ChangedFile(UiFlowShop::ORDERS_TEMPLATE, ChangedFile::KIND_TEMPLATE)],
            targets: $targets,
        );
    }
}

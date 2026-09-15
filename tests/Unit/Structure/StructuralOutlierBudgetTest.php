<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A ratchet on the biggest classes in the monorepo.
 *
 * The god-class work has been declared done more than once while the class was
 * still the largest thing in the repository. MEASURED 2026-09-06, unchanged
 * since the 2026-09-02 re-measurement: SseServer is 102 methods and 2082 lines,
 * against 59 and 1126 for the next class by methods — 1.7x and 1.85x. A record
 * saying otherwise was not lying on purpose; nothing was counting.
 *
 * So this counts, and it moves in one direction. A class that grows past its
 * recorded size fails. A class that SHRINKS past it also fails, and says to
 * lower the number — otherwise a real refactor leaves behind a budget with room
 * to regrow into, which is how the last one came back.
 *
 * No complexity tool is installed and this is deliberately not one. Method and
 * line counts are crude, but they are reproducible by anyone with grep, which
 * is what a number in a backlog entry has to be.
 */
final class StructuralOutlierBudgetTest extends TestCase
{
    /** Classes at or above 30 methods or 700 lines: path => [methods, lines]. */
    private const BUDGETS = [
        // 2082 -> 2087 on 2026-09-06: serveResourceStream()'s initial frame may
        // now arrive as a Closure resolved AFTER the connection caps, so an
        // over-cap attempt no longer pays for a full graphql document execution
        // before being answered 429. The union type, the resolution line and the
        // comment saying why it sits below the caps are the whole increase.
        // +1/+2/+3 lines on 2026-09-13, adopting Semitexa\Core\Support\Row:
        // reading a loosely-typed row (a Swoole\Table row, a backtrace frame, a
        // DB row) went from `(string) ($row['col'] ?? '')` at the call site to
        // one narrowing that can be read and tested. That was the largest
        // phpstan cluster in the project — 83 "Cannot cast mixed to X" — and it
        // also removed a fatal: casting an ARRAY value that way raises "Array
        // to string conversion" rather than yielding the default, so a
        // malformed row took the worker down. NO NEW METHODS in any of the three.
        'semitexa-ssr/src/Application/Service/Async/SseServer.php' => [102, 2088],
        'semitexa-orm/src/Query/ResourceModelQuery.php' => [59, 1128],
        'semitexa-orm/src/OrmManager.php' => [41, 917],
        // A UI skill can now be raised AT a record: handleUiSkill takes the
        // planner's arguments, and the pipeline path keeps which step the first
        // UI skill came from instead of discarding it. Methods are unchanged —
        // the four decisions that came with it (which arguments may reach the
        // entry URL, what that URL becomes, what the window is called, whether
        // one onto the same thing is already open) went to UiSkillDialog rather
        // than in here, which is what this budget caught them doing.
        // +6 from a review finding: the DialogExists branch returned neither
        // arguments nor pipeline while the OpenDialog branch beside it did, so
        // a caller learned that SOMETHING was already open and not which
        // record. Two constructor arguments and the sentence saying why.
        'semitexa-os/src/Application/Service/SkillLoopRunner.php' => [35, 1272],
        'semitexa-webhooks/src/Domain/Model/OutboundDelivery.php' => [35, 155],
        'semitexa-core/src/Discovery/AttributeDiscovery.php' => [33, 932],
        'semitexa-media/src/Domain/Model/MediaVariant.php' => [33, 261],
        'semitexa-weave/src/Application/Service/GraphStore.php' => [32, 685],
        'semitexa-webhooks/src/Domain/Model/InboundDelivery.php' => [32, 122],
        'semitexa-platform-settings/src/Application/Service/SettingsStore.php' => [31, 473],
        'semitexa-core/src/Request.php' => [31, 443],
        // 206 -> 207: one @param line for the same Closure-or-array contract.
        // +6/+2 lines on 2026-09-13, ep-phpstan-baseline-burndown: `@param
        // array<...>` on methods that had none. These are DOCBLOCKS, and they
        // pay for themselves at a 2:1 rate — AsyncResourceSseServer is six thin
        // delegates whose targets already declared the shape, so each missing
        // annotation was TWO errors: the annotation itself and the argument
        // type at the delegation. Six lines, twelve errors, no new methods.
        'semitexa-ssr/src/Application/Service/Async/AsyncResourceSseServer.php' => [31, 213],
        'semitexa-ssr/src/Application/Handler/PayloadHandler/AbstractSseFeedHandler.php' => [29, 762],
        // Newly recorded on 2026-09-11, at 24/709: it crossed the 700-line
        // threshold by nine lines, and every one of them is the guard that
        // stops a partial write from RESURRECTING a deleted row. Table::set()
        // creates a row on a key it does not have, all four writers here check
        // and write as two steps, and no lock spans the two — so a remove()
        // landing between them used to leave a fragment holding a slot in a
        // fixed-size table forever.
        //
        // Recorded rather than trimmed to fit. The class is a genuine outlier
        // and extracting the row lifecycle out of it is worth doing, but not by
        // deleting the explanation of a correctness guard to buy nine lines —
        // that is how the guard gets removed by the next reader who cannot see
        // what it is for.
        // 709 -> 718 on 2026-09-11, still 24 methods. Review pointed out that
        // the guard reported a row that VANISHED mid-update and a write that
        // FAILED with the same `false`, and all four writers turned both into a
        // DeferredRenderingException — so a consumed request racing its own
        // last slot made the renderer log a failed finalisation and abandon the
        // rest. The three outcomes are now distinct and the nine lines are the
        // sentence explaining which is which.
        'semitexa-ssr/src/Application/Service/Isomorphic/DeferredRequestRegistry.php' => [24, 718],
        'semitexa-orm/src/Adapter/ConnectionPool.php' => [27, 842],
        'semitexa-ssr/src/Application/Service/Http/Response/HtmlResponse.php' => [25, 765],
        // 771 -> 779 on 2026-09-06, recorded deliberately: the trace buffer
        // became a ring that keeps the LAST events, so the capped-trace notice
        // now has to say WHICH end was cut, and the root coroutine is read from
        // the file rather than guessed from the first surviving span. No new
        // method; the class is no more tangled than it was, just eight lines
        // longer for two things it previously got wrong.
        'semitexa-dev/src/Application/Service/Trace/TraceHtmlRenderer.php' => [24, 779],
        // Newly recorded on 2026-09-11 at 25/713, crossing the 700-line line
        // from 684. The command now has to report a verdict that distinguishes
        // "every required target ran" from "nothing objected", so it carries
        // completed(), an incomplete count in the trace summary, and an exit
        // code that fails on an incomplete run rather than only on a failing
        // one. That is the point of the change, and it is the part that cost
        // the lines.
        //
        // Recorded rather than split: the growth is one concern, not a second
        // one moving in. If this file is split later it should be along the
        // envelope/trace seam, which is a deliberate refactor and not something
        // to do under the pressure of a budget number.
        //
        // 25/713 -> 25/719 on 2026-09-13, in review of dev#83: an accepted
        // violation is no longer subtracted silently. The envelope now carries
        // the `accepted` block whole — which rule, in which file, and the reason
        // it was allowed — because a verdict of `pass` that quietly ignored a
        // known violation reads exactly like one with nothing to ignore. Six
        // lines, NO NEW METHOD.
        //
        // 25/719 -> 25/731 on 2026-09-13: the `--dirty` flag. The scan itself
        // is NOT here — it grew this file to 30/882 inline, the ratchet said so,
        // and it moved to DirtyWorkspaceScanner, which is where a thing that
        // knows about git repositories belongs anyway. What is left is the
        // option, two call sites and the comments explaining why the answer
        // carries its own reach. METHOD COUNT UNCHANGED at 25.
        // 25/731 -> 25/750 on 2026-09-13, in self-review of the same flag: a
        // clean tree now gets its own verdict instead of the generic "pass
        // --files, --git-ref, --diff-stdin or --dirty" error, which told the
        // caller to do the thing they had just done and exited 1 on every
        // clean checkout. Nineteen lines, most of them the comment saying why
        // it is neither a pass nor a failure. METHOD COUNT UNCHANGED at 25.
        // 750 -> 761 in review of dev#84: the empty --dirty answer now honours
        // the output mode. Default mode is NDJSON dispatched by `kind`, and
        // emitting the single-envelope shape there handed such a consumer a
        // record it could not place. Eleven lines for the second shape and the
        // comment. METHOD COUNT UNCHANGED at 25.
        //
        // 25/761 -> 19/669 in review of dev#84, the first entry here to go
        // DOWN. Two more facts had to reach both output shapes — renames, and
        // the dirty scan in NDJSON — and the pattern was by then unmistakable:
        // every one of the growth notes above is a fact added to one shape and
        // then, separately, to the other, and twice it reached only one and was
        // caught in review rather than by anyone reading the output. So the
        // five methods both builders shared moved out to
        // VerifyReportSerializer, which is pure and has one definition per
        // fact. The command is left with argument handling, dispatch and the
        // exit code.
        //
        // 669 -> 691 in the same review round: the clean `--dirty` answer
        // returned BEFORE maybeAppendToTrace(), so a workflow running
        // `ai:verify --dirty --trace=<id>` recorded nothing for the run at all
        // and the trail read as a step nobody took. It now builds an empty plan
        // and appends like every other answer, in both output modes. Twenty-two
        // lines, METHOD COUNT UNCHANGED at 19.
        //
        // 691 -> 705, same round: dedupe() was first-wins, so combining
        // `--files=<renamed destination>` with `--dirty` named one path twice
        // and the scanner's rename lost to the hand-written modification --
        // taking `originalPath` with it, which is what ContractMoveResolver
        // needs to find consumers of the old contract. It now merges the
        // richer entry in. Eighteen lines, METHOD COUNT UNCHANGED at 19.
        // 709 -> 740 in review of dev#84, three reviewer-found correctness
        // fixes and their reasons. The clean `--dirty` answer now carries
        // `completed`, `counts` and `restart` off VerifyReportSerializer — it
        // was the one answer missing them, so a consumer reading the stable v1
        // schema had to special-case it. And dedupe() learned that a deletion
        // never wins over a record of a live file: `D path` then `?? path` is
        // a file staged for deletion and written again, and keeping only the
        // deletion made the planner skip a file sitting right there.
        // METHOD COUNT UNCHANGED at 19.
        'semitexa-dev/src/Application/Console/Command/AiVerifyCommand.php' => [19, 740],
        // FIRST RECORDING, 2026-09-13: this crossed the 700-line threshold in
        // review of dev#84 and the ratchet said so. The addition is a refusal:
        // `--preview` with `--expect-field` resolved the target, returned
        // SUCCESS and evaluated no expectation, so a caller relying on the
        // documented failure exit for an assertion got a green run that
        // verified nothing. Recorded rather than extracted because the class is
        // one command with one flow; if it grows again, the envelope-building
        // half is the seam — the same one VerifyReportSerializer was cut along.
        'semitexa-dev/src/Application/Console/Command/AiInvokeCommand.php' => [18, 713],
        'semitexa-dev/src/Application/Service/Ai/Verify/Structure/ModuleStructureValidator.php' => [22, 1092],
        'semitexa-orm/src/Application/Service/Sync/SyncEngine.php' => [21, 865],
        // 21/718 -> 22/750 on 2026-09-09: semitexa-dev#73, a regression that
        // shipped in 2026.09.08.2003 and turned the first ai:verify after any
        // framework update red in every consumer project. The new method is
        // installerScaffoldDir(), a sibling of skillsSyncScript() answering the
        // same question for the other gate — "is the thing I compare even here".
        // Recorded rather than trimmed: most of the growth is the comment saying
        // why the guard exists, and shrinking that to fit a budget would delete
        // the part a future reader needs to not remove the guard again.
        // 22/750 -> 22/759 on 2026-09-11: the skipped/incomplete split. A target
        // that was SUPPOSED to run and did not used to be recorded as skipped
        // with exit 0, which reads as a pass — the same false green that once
        // let five lints go unrun without anyone noticing. Every such result is
        // now INCOMPLETE with exit 1, and only genuinely optional tooling (the
        // docs commands, absent when semitexa/docs is not installed) keeps
        // skipped. NO NEW METHOD: the count is still 22, so the class is no
        // more tangled than it was, just nine lines longer for a distinction it
        // previously could not make.
        // 22/759 -> 22/760 on 2026-09-13: one line, carrying that same
        // `accepted` block through to the result so the command has something
        // to report. NO NEW METHOD.
        'semitexa-dev/src/Application/Service/Ai/Verify/VerificationExecutor.php' => [22, 760],
        // 912 -> 915 on 2026-09-06: the `?cursor=` parameter became conditional
        // on the route's declared pagination modes, and turning one unconditional
        // statement into an if costs two lines that no wording can remove. No new
        // method; the class is no more tangled than it was.
        'semitexa-api/src/OpenApi/Route/ResourceRouteSchemaGenerator.php' => [20, 915],
        'semitexa-update/src/Application/Service/Composer/ComposerUpdateRunner.php' => [20, 751],
        'semitexa-core/src/Discovery/ClassDiscovery.php' => [20, 742],
        'semitexa-orm/src/Application/Service/Schema/SchemaCollector.php' => [20, 709],
        // 827 -> 831 on 2026-09-14: lint:mechanisms joined the KIND_SERVICE row
        // when the prompt.catalog detector landed, and the four lines are the
        // comment saying why — a detector no kind schedules never runs and reads
        // exactly like a passing check, which is the mistake this map has made
        // before. The row edit itself costs nothing. NO NEW METHOD: still 19.
        // 831 -> 843 the same day, from review: that row also scheduled the lint
        // for package paths it cannot scan, which is the SAME mistake from the
        // other side — a target that runs, examines nothing in the diff, and
        // passes. The guard is inlined in the loop rather than extracted, which
        // is why the method count is unchanged; a predicate this small reads
        // better where it acts than as a twentieth method on this class.
        // 843 -> 847 from review: lint:mechanisms joined the HANDLER row too,
        // since a handler can inline a heredoc straight into an LLM call and a
        // diff holding only a handler would otherwise pass standard
        // verification. Four lines of comment, no new method.
        // 847 -> 850: the listener row joined the handler and service rows for
        // the same reason — a listener can call an LLM with an inline heredoc,
        // and the execution shape decides, not the directory. Three lines of
        // comment, no new method.
        // 850 -> 859: nine lines recording WHY lint:mechanisms is not on the
        // catch-all kind. It was tried — a module console command calling an LLM
        // is a real gap — and it wedged the suite, because KIND_PHP_OTHER is what
        // the verify tooling's own fixtures classify as, so plans built in tests
        // started executing a real shell-out lint. A reverted experiment that
        // leaves no trace invites the next person to repeat it.
        // 859 -> 864: the application-root guard is anchored rather than a
        // substring, since the lint scans the REPOSITORY-ROOT src/modules and a
        // package path containing that segment is not somewhere it looks.
        'semitexa-dev/src/Application/Service/Ai/Verify/VerificationPlanner.php' => [19, 864],
        // 720 -> 728 on 2026-09-12: the mapped status is now named on the trace,
        // so an observer can tell a refusal from a crash — a gate declines by
        // throwing, and until this the two arrived as the same event. One
        // statement, one local to hold the response that was previously mapped
        // inline, and the six lines saying why. No new method; the file is no
        // more tangled than it was.
        'semitexa-core/src/Pipeline/RouteExecutor.php' => [18, 730],
        'semitexa-demo/src/Application/Service/DemoCatalogService.php' => [17, 825],
        'semitexa-platform-ui/src/Application/Service/Twig/PlatformUiTwigExtension.php' => [17, 818],
        'semitexa-core/src/Resource/ResourceExpansionPipeline.php' => [12, 707],
    ];

    /**
     * A class declaration, with modifiers in any order PHP 8.4 allows.
     *
     * The first draft matched only `final class` and `abstract class`. MEASURED
     * when that was reported: 465 classes in packages/ were invisible to this
     * guard, because `final readonly class` is the ordinary shape for a value
     * object here. A budget that cannot see most of the repository is not a
     * budget.
     */
    private const CLASS_PATTERN = '/^\\s*(?:(?:final|abstract|readonly)\\s+)*class\\s/m';

    /**
     * A method declaration. Visibility is not always first: `abstract public
     * function` and `final public static function` both appear in src/, and the
     * first draft counted neither — 26 files were undercounted, one of them
     * reporting 1 method where it has 17.
     */
    private const METHOD_PATTERN = '/^\\s*(?:(?:final|abstract|public|protected|private|static)\\s+)+function\\s/m';

    /** Slack before a shrink is treated as a real reduction rather than an edit. */
    private const SHRINK_TOLERANCE_LINES = 40;
    private const SHRINK_TOLERANCE_METHODS = 3;

    /**
     * The matchers themselves, pinned against real declaration shapes.
     *
     * Narrowing them does NOT fail the three budget tests — a blind matcher
     * sees fewer classes, so nothing new appears unlisted and everything
     * recorded looks unchanged. That is exactly how the first draft shipped
     * missing 465 classes. The patterns need their own test.
     */
    #[Test]
    public function the_matchers_recognise_the_declarations_this_codebase_uses(): void
    {
        $classes = [
            'class Plain {',
            'final class Sealed {',
            'abstract class Base {',
            'readonly class Value {',
            'final readonly class ValueObject {',
        ];

        foreach ($classes as $declaration) {
            self::assertSame(
                1,
                preg_match(self::CLASS_PATTERN, $declaration),
                $declaration . ' must be seen as a class',
            );
        }

        $methods = [
            '    public function a(): void',
            '    protected static function b(): void',
            '    private function c(): void',
            '    abstract public function d(): void',
            '    final public static function e(): void',
            '    public static function f(): void',
        ];

        foreach ($methods as $declaration) {
            self::assertSame(
                1,
                preg_match_all(self::METHOD_PATTERN, $declaration),
                trim($declaration) . ' must be counted as a method',
            );
        }
    }

    /**
     * The same thing against a real file, because a fixture only proves the
     * regex reads its own examples. This class declares seventeen methods and
     * the first draft counted one.
     */
    #[Test]
    public function a_real_file_is_measured_the_way_a_reader_would_count_it(): void
    {
        $path = $this->packagesDir()
            . '/semitexa-platform-ui/src/Application/Service/Twig/PlatformUiTwigExtension.php';

        self::assertFileExists($path);
        [$methods] = $this->measureFile($path);

        self::assertGreaterThanOrEqual(
            17,
            $methods,
            'the method matcher is missing declarations a reader would count',
        );
    }

    #[Test]
    public function no_recorded_outlier_has_grown(): void
    {
        $grown = [];

        foreach (self::BUDGETS as $path => [$methods, $lines]) {
            $actual = $this->measure($path);
            if ($actual === null) {
                continue; // a deleted class is covered by the test below
            }

            if ($actual[0] > $methods || $actual[1] > $lines) {
                $grown[] = sprintf(
                    '%s: %d methods / %d lines, budget %d / %d',
                    $path,
                    $actual[0],
                    $actual[1],
                    $methods,
                    $lines,
                );
            }
        }

        self::assertSame(
            [],
            $grown,
            "These classes grew past their recorded size. Split them, or record the new number "
            . "deliberately:\n  - " . implode("\n  - ", $grown),
        );
    }

    /**
     * The half that keeps "done" honest: once a class is genuinely smaller, the
     * budget must come down with it.
     */
    #[Test]
    public function a_shrunken_outlier_lowers_its_budget(): void
    {
        $stale = [];

        foreach (self::BUDGETS as $path => [$methods, $lines]) {
            $actual = $this->measure($path);
            if ($actual === null) {
                $stale[] = $path . ': gone — remove it from the budget list';
                continue;
            }

            if ($actual[0] + self::SHRINK_TOLERANCE_METHODS < $methods
                || $actual[1] + self::SHRINK_TOLERANCE_LINES < $lines
            ) {
                $stale[] = sprintf(
                    '%s: now %d methods / %d lines, budget still %d / %d',
                    $path,
                    $actual[0],
                    $actual[1],
                    $methods,
                    $lines,
                );
            }
        }

        self::assertSame(
            [],
            $stale,
            "These budgets have room the class no longer needs — lower them, or the next "
            . "regrowth is invisible:\n  - " . implode("\n  - ", $stale),
        );
    }

    /**
     * A new class larger than everything recorded here would otherwise arrive
     * unnoticed: the two tests above only look at what is already listed.
     */
    #[Test]
    public function nothing_new_has_climbed_into_the_outliers(): void
    {
        $unlisted = [];

        foreach ($this->classFiles() as $path => [$methods, $lines]) {
            if (($methods >= 30 || $lines >= 700) && !array_key_exists($path, self::BUDGETS)) {
                $unlisted[] = sprintf('%s: %d methods / %d lines', $path, $methods, $lines);
            }
        }

        sort($unlisted);

        self::assertSame(
            [],
            $unlisted,
            "These crossed the outlier threshold without being recorded:\n  - "
            . implode("\n  - ", $unlisted),
        );
    }

    /** @return array{0: int, 1: int}|null */
    private function measure(string $path): ?array
    {
        $full = $this->packagesDir() . '/' . $path;

        return is_file($full) ? $this->measureFile($full) : null;
    }

    /** @return array<string, array{0: int, 1: int}> */
    private function classFiles(): array
    {
        $out = [];
        $dir = $this->packagesDir();

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace($dir . '/', '', $file->getPathname());
            // Source only: tests and resources carry their own shapes.
            if (!preg_match('#^[a-z0-9-]+/src/#', $path)) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match(self::CLASS_PATTERN, $source) !== 1) {
                continue;
            }
            $out[$path] = $this->measureFile($file->getPathname());
        }

        return $out;
    }

    /** @return array{0: int, 1: int} */
    private function measureFile(string $file): array
    {
        $source = (string) file_get_contents($file);

        return [
            preg_match_all(self::METHOD_PATTERN, $source),
            substr_count($source, "\n") + 1,
        ];
    }

    private function packagesDir(): string
    {
        return dirname(__DIR__, 4);
    }
}

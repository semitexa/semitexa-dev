<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The BC gate of the stability contract: roave's breaks since a package's
 * previous tag, kept only on the @api surface of that tag, and refused unless
 * the part was deprecated in a release at least 90 days before this one.
 *
 * Built on real tags; roave itself is stood in for by a script printing its
 * JSON (its real output, checked by hand on 2026-10-30: "Method Probe\Clock#old()
 * was removed", exit 3), so the test needs neither bcmath nor intl.
 */
final class BcGateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-bc-' . uniqid('', true);
        mkdir($this->root . '/packages/semitexa-core/src', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** What roave says for core at 2026.11.01.0448, wrapped mid-word by the console, with Clock for ThemeValue. */
    private const UNREADABLE_TAG = "No security vulnerability advisories found.\n\nIn IdentifierNotFound.php line 26:\n                \n  Roave\\BetterReflection\\Reflection\\ReflectionClass \"Semitexa\\Co  \n  re\\Clock\" could not be found in the located source  \n                \n\nroave-backwards-compatibility-check:assert-backwards-compatible [--from [FROM]] [--to TO]\n";

    private const CLOCK_EXEMPT = ['semitexa-core' => ['2026.07.01.0900' => ['reason' => 'Clock moved to its own file', 'error' => 'Semitexa\\Core\\Clock" could not be found']]];

    private const BREAKS = ['Method Semitexa\\Core\\Clock#old() was removed', 'Method Semitexa\\Core\\Inner#gone() was removed', 'Method b() was added to interface Semitexa\\Core\\Port'];

    #[Test]
    public function a_break_on_the_api_surface_fails_the_cut_and_one_off_it_is_ignored(): void
    {
        $this->released();

        [$status, $out] = $this->gate(self::BREAKS);

        self::assertSame(1, $status);
        self::assertSame([
            '[BREAK] semitexa-core (since 2026.07.01.0900): Method Semitexa\\Core\\Clock#old() was removed — @api and never deprecated',
            '[BREAK] semitexa-core (since 2026.07.01.0900): Method b() was added to interface Semitexa\\Core\\Port — @api and never deprecated',
        ], $out, 'Inner is not @api: its break is not the contract\'s');
    }

    /** 13.11: roave not reading a class is about that class, not the @api enum it tripped on. */
    #[Test]
    public function a_class_roave_could_not_read_is_judged_by_that_class(): void
    {
        $this->released();
        $unread = static fn (string $class): string => "An enum expression Semitexa\\Core\\Clock::Json is not supported in class {$class} in file /src/X.php (line 35)";

        self::assertSame([0, 'No break on the @api surface.'], $this->first($this->gate([$unread('Semitexa\\Core\\Inner')])));
        [$status, $out] = $this->gate([$unread('Semitexa\\Core\\Port')]);
        self::assertSame(1, $status);
        self::assertStringContainsString('roave could not read this @api class', implode("\n", $out));
        // Review, dev#250: the method form names the class too.
        [$status, $out] = $this->gate(['An enum expression Semitexa\\Core\\Inner::Json is not supported in method Semitexa\\Core\\Clock::now() in file /src/Clock.php (line 5)']);
        self::assertSame(1, $status, implode("\n", $out));
        self::assertStringContainsString('roave could not read this @api class', implode("\n", $out));
    }

    /** Review, dev#250: an @api class inheriting the unread method from one that is not promised is refused. */
    #[Test]
    public function an_unread_parent_of_an_api_class_is_refused(): void
    {
        $this->released(extra: [
            'Base.php' => "<?php\nnamespace Semitexa\\Core;\nclass Base { public function read(): void {} }\n",
            // Review, dev#250: the only promised child names its parent in lower case; PHP reads
            // \Semitexa\Core\base as Base, and so does the gate.
            'Child.php' => "<?php\nnamespace Semitexa\\Core;\nfinal class Child extends Base {}\n",
            'Other.php' => "<?php\nnamespace Semitexa\\Core;\n/** @api */\nfinal class Other extends \\Semitexa\\Core\\base {}\n",
        ]);
        [$status, $out] = $this->gate(['An enum expression Semitexa\\Core\\Mode::A is not supported in method Semitexa\\Core\\Base::read() in file /src/Base.php (line 3)']);
        self::assertSame(1, $status, implode("\n", $out));
        self::assertStringContainsString('roave could not read this @api class', implode("\n", $out));

    }

    /** ai:verify (tk-stab-bc-check-gate): the working tree is checked through SEMITEXA_BC_TO, one package through --package. */
    #[Test]
    public function the_working_tree_is_checked_through_a_stash_commit_and_one_package_alone(): void
    {
        $this->released(changedSince: false);
        $dir = $this->root . '/packages/semitexa-core';
        [$status, $out] = $this->gate([self::BREAKS[0]]);
        self::assertSame(0, $status, 'HEAD is the tag: nothing to check');

        file_put_contents($dir . '/src/Later.php', "<?php\nnamespace Semitexa\\Core;\nfinal class Later {}\n");
        exec('git -C ' . escapeshellarg($dir) . ' add -A && git -C ' . escapeshellarg($dir) . ' stash create', $sha);
        self::assertNotSame('', $sha[0] ?? '', 'the working tree has a change to commit');
        [$status, $out] = $this->gate([self::BREAKS[0]], env: 'SEMITEXA_BC_TO=' . escapeshellarg($sha[0]) . ' ');
        self::assertSame(1, $status, implode("\n", $out));
        self::assertSame('[BREAK] semitexa-core (since 2026.07.01.0900): Method Semitexa\\Core\\Clock#old() was removed — @api and never deprecated', $out[0]);
        // Review, dev#250: roave itself is asked about the stash commit, not HEAD.
        self::assertStringContainsString('--to=' . $sha[0], (string) file_get_contents($this->root . '/roave.args'));

        mkdir($this->root . '/packages/semitexa-other/src', 0777, true);
        $this->git($this->root . '/packages/semitexa-other', ['init -q']);
        [$status, $out] = $this->gate([self::BREAKS[0]], env: 'SEMITEXA_BC_TO=' . escapeshellarg($sha[0]) . ' ', args: ' --package=semitexa-other');
        self::assertSame(0, $status, 'only the package asked for is checked: ' . implode("\n", $out));

        // Fable's review of dev#250: --package repeats, and a revision that reads as an option is refused.
        [$status, $out] = $this->gate([self::BREAKS[0]], env: 'SEMITEXA_BC_TO=' . escapeshellarg($sha[0]) . ' ', args: ' --package=semitexa-other --package=semitexa-core');
        self::assertSame(1, $status, 'both packages are checked: ' . implode("\n", $out));
        [$status, $out] = $this->gate([self::BREAKS[0]], env: 'SEMITEXA_BC_TO=--upload-pack=x ');
        self::assertSame(1, $status);
        self::assertStringContainsString('is not a git revision', implode("\n", $out));

        // Review, dev#250: a package asked for that is not there fails, not passes.
        [$status, $out] = $this->gate([self::BREAKS[0]], args: ' --package=semitexa-croe');
        self::assertSame(1, $status);
        self::assertStringContainsString('--package semitexa-croe: no such package repository', implode("\n", $out));
    }

    /**
     * @param array{0: int, 1: list<string>} $result
     * @return array{0: int, 1: string}
     */
    private function first(array $result): array
    {
        return [$result[0], (string) end($result[1])];
    }

    #[Test]
    public function a_part_deprecated_in_a_release_ninety_days_before_may_go(): void
    {
        // Shipped in 2026.07.01.0900; the cut is on 2026.10.31: 122 days.
        $this->released(oldDoc: '/** @deprecated 2026.07.01 Use now() instead. */');

        [$status, $out] = $this->gate([self::BREAKS[0]]);

        self::assertSame([0, [
            '[ALLOWED] semitexa-core (since 2026.07.01.0900): Method Semitexa\\Core\\Clock#old() was removed — deprecated 2026.07.01, shipped in 2026.07.01.0900, 122 days ago',
            '1 break(s), each deprecated long enough.',
        ]], [$status, $out]);
    }

    /** A deprecation written four spaces in, the furthest a tag may sit, is read as one. */
    #[Test]
    public function a_deprecation_four_spaces_in_is_read(): void
    {
        $this->released(oldDoc: "/**\n     *    @deprecated 2026.07.01 Use now() instead.\n     */");

        [$status, $out] = $this->gate([self::BREAKS[0]]);

        self::assertSame([0, [
            '[ALLOWED] semitexa-core (since 2026.07.01.0900): Method Semitexa\\Core\\Clock#old() was removed — deprecated 2026.07.01, shipped in 2026.07.01.0900, 122 days ago',
            '1 break(s), each deprecated long enough.',
        ]], [$status, $out]);
    }

    /**
     * The 90 days count from the release that shipped the deprecation: a date
     * written back to July, first released on 2026.10.20, gave 11 days of
     * notice and passed as 122.
     */
    #[Test]
    public function a_backdated_deprecation_counts_from_the_release_that_shipped_it(): void
    {
        $this->released(oldDoc: '/** @deprecated 2026.07.01 Use now() instead. */', deprecatedLater: true);

        [$status, $out] = $this->gate([self::BREAKS[0]]);

        self::assertSame([1, ['[BREAK] semitexa-core (since 2026.10.20.0900): Method Semitexa\\Core\\Clock#old() was removed — @api, deprecated 2026.07.01, shipped in 2026.10.20.0900: only 11 of 90 days ago']], [$status, $out]);
    }

    /** A promoted property marked @api is a promise, on a class that is not. */
    #[Test]
    public function a_promoted_api_property_is_on_the_surface(): void
    {
        $this->released(extra: ['Box.php' => "<?php\nnamespace Semitexa\\Core;\nfinal class Box\n{\n    public function __construct(\n        /** @api */\n        public string \$label = '',\n    ) {}\n}\n"]);

        [$status, $out] = $this->gate(['Property Semitexa\\Core\\Box#$label was removed']);

        self::assertSame([1, ['[BREAK] semitexa-core (since 2026.07.01.0900): Property Semitexa\\Core\\Box#$label was removed — @api and never deprecated']], [$status, $out]);
    }

    /**
     * A mark up to four spaces after the star is a mark, as semitexa.deprecatedApi
     * reads it; five or more is an example in the prose.
     */
    #[Test]
    public function a_mark_a_few_spaces_in_is_a_mark_and_further_in_is_prose(): void
    {
        $this->released(extra: [
            'Box.php' => "<?php\nnamespace Semitexa\\Core;\n/**\n *    @api\n */\nfinal class Box\n{\n    public function open(): void {}\n}\n",
            'Note.php' => "<?php\nnamespace Semitexa\\Core;\n/**\n * Mark it so:\n *\n *     @api\n */\nfinal class Note\n{\n    public function read(): void {}\n}\n",
        ]);

        [$status, $out] = $this->gate(['Method Semitexa\\Core\\Box#open() was removed', 'Method Semitexa\\Core\\Note#read() was removed']);

        self::assertSame([1, ['[BREAK] semitexa-core (since 2026.07.01.0900): Method Semitexa\\Core\\Box#open() was removed — @api and never deprecated']], [$status, $out]);
    }

    /** Roave reports a class removed whole by its name: a member promised in it went with it. */
    #[Test]
    public function a_removed_class_takes_its_promised_members_with_it(): void
    {
        $this->released(extra: ['Kit.php' => "<?php\nnamespace Semitexa\\Core;\nfinal class Kit\n{\n    /** @api */\n    public function use(): void {}\n}\n"]);

        [$status, $out] = $this->gate(['Class Semitexa\\Core\\Kit has been deleted']);

        self::assertSame([1, ['[BREAK] semitexa-core (since 2026.07.01.0900): Class Semitexa\\Core\\Kit has been deleted — Semitexa\\Core\\Kit::use @api and never deprecated']], [$status, $out]);

        // Made final, it is still there: no promised member changed.
        self::assertSame([0, ['No break on the @api surface.']], $this->gate(['Class Semitexa\\Core\\Kit became final']));
    }

    /** Only the break's subject is judged: an @api class named as a type in someone else's signature is not. */
    #[Test]
    public function a_break_is_judged_by_its_subject_not_by_the_types_it_names(): void
    {
        $this->released(extra: ['Kit.php' => "<?php\nnamespace Semitexa\\Core;\nfinal class Kit\n{\n    /** @api */\n    public function use(): void {}\n    public function helper(): void {}\n}\n"]);

        [$status, $out] = $this->gate([
            'The return type of Semitexa\\Core\\Inner#gone() changed from Semitexa\\Core\\Clock to string',
            'Method Semitexa\\Core\\Kit#helper() was removed',
        ]);

        self::assertSame([0, ['No break on the @api surface.']], [$status, $out], 'Inner is not @api, and Kit::helper() is not the promised Kit::use()');
    }

    /** A second declaration with the same @deprecated line shipped later: its own release counts, not the first one's. */
    #[Test]
    public function each_declaration_counts_from_its_own_release(): void
    {
        $this->released(oldDoc: '/** @deprecated 2026.07.01 Use now() instead. */', laterMethod: "    /** @deprecated 2026.07.01 Use now() instead. */\n    public function older(): int { return 2; }\n");

        [$status, $out] = $this->gate(['Method Semitexa\\Core\\Clock#older() was removed', self::BREAKS[0]]);

        self::assertSame([1, [
            '[ALLOWED] semitexa-core (since 2026.10.20.0900): Method Semitexa\\Core\\Clock#old() was removed — deprecated 2026.07.01, shipped in 2026.07.01.0900, 122 days ago',
            '[BREAK] semitexa-core (since 2026.10.20.0900): Method Semitexa\\Core\\Clock#older() was removed — @api, deprecated 2026.07.01, shipped in 2026.10.20.0900: only 11 of 90 days ago',
        ]], [$status, $out]);
    }

    /** A member added to a class after the class was deprecated was promised only since it appeared. */
    #[Test]
    public function a_deleted_class_is_judged_by_each_promised_member(): void
    {
        $this->released(laterMethod: "    public function later(): int { return 3; }\n", classDoc: '@deprecated 2026.07.01 Use Time instead.');

        [$status, $out] = $this->gate(['Class Semitexa\\Core\\Clock has been deleted']);

        self::assertSame([1, ['[BREAK] semitexa-core (since 2026.10.20.0900): Class Semitexa\\Core\\Clock has been deleted — Semitexa\\Core\\Clock::later @api, deprecated 2026.07.01, shipped in 2026.10.20.0900: only 11 of 90 days ago']], [$status, $out]);
    }

    /** What the shipping history could not read reached no exit code: the break was allowed. */
    #[Test]
    public function an_unreadable_older_release_fails_the_gate_instead_of_dating_from_it(): void
    {
        $dir = $this->root . '/packages/semitexa-core';
        file_put_contents($dir . '/composer.json', '{"name":"semitexa/core"}');
        file_put_contents($dir . '/src/Broken.php', "<?php\nfinal class {\n");
        $this->git($dir, ['init -q', 'config user.email t@example.test', 'config user.name T', 'add -A', 'commit -q -m early']);
        $this->git($dir, ['tag -a -m cut 2026.05.01.0900'], '2026-05-01T09:00:00Z');
        unlink($dir . '/src/Broken.php');
        file_put_contents($dir . '/src/Clock.php', "<?php\nnamespace Semitexa\\Core;\n/** @api */\nfinal class Clock\n{\n    /** @deprecated 2026.07.01 Use now() instead. */\n    public function old(): int { return 1; }\n}\n");
        $this->git($dir, ['add -A', 'commit -q -m released']);
        $this->git($dir, ['tag -a -m cut 2026.07.01.0900'], '2026-07-01T09:00:00Z');
        file_put_contents($dir . '/src/Later.php', "<?php\nnamespace Semitexa\\Core;\nfinal class Later {}\n");
        $this->git($dir, ['add -A', 'commit -q -m after']);

        [$status, $out] = $this->gate([self::BREAKS[0]]);

        self::assertSame(1, $status);
        self::assertContains('[UNCHECKED] semitexa-core: src/Broken.php at 2026.05.01.0900 does not parse (Syntax error, unexpected \'{\', expecting T_STRING on line 2)', $out);
    }

    #[Test]
    public function a_global_namespace_api_class_is_on_the_surface(): void
    {
        $this->released(extra: ['GlobalClock.php' => "<?php\n/** @api */\nfinal class GlobalClock {}\n"]);

        [$status, $out] = $this->gate(['Class GlobalClock has been deleted']);

        self::assertSame([1, ['[BREAK] semitexa-core (since 2026.07.01.0900): Class GlobalClock has been deleted — @api and never deprecated']], [$status, $out]);
    }

    /** A package that promised nothing at its tag costs nothing: roave is not run for it. */
    #[Test]
    public function a_package_without_api_at_its_tag_is_not_checked(): void
    {
        $this->released(api: false);

        [$status, $out] = $this->gate(null);

        self::assertSame([0, ['No break on the @api surface.']], [$status, $out]);
    }

    /** Deleting src was skipped as "not a package", and every promised class went with it. */
    #[Test]
    public function a_package_that_deleted_its_src_is_still_checked(): void
    {
        $this->released();
        exec(sprintf('git -C %s rm -rq src 2>&1 && git -C %s commit -qm gone 2>&1', escapeshellarg($this->root . '/packages/semitexa-core'), escapeshellarg($this->root . '/packages/semitexa-core')));

        [$status, $out] = $this->gate(['Class Semitexa\\Core\\Clock has been deleted']);

        self::assertSame([1, ['[BREAK] semitexa-core (since 2026.07.01.0900): Class Semitexa\\Core\\Clock has been deleted — @api and never deprecated']], [$status, $out]);
    }

    /**
     * Nothing to compare, nothing broken: roave is not run (this stand-in would
     * fail if it were), so a tag roave cannot read does not fail an untouched
     * package. core 2026.10.31.0849 declares ThemeValue in ThemeLayer.php.
     */
    #[Test]
    public function a_package_whose_source_did_not_change_since_its_tag_is_not_checked(): void
    {
        $this->released(changedSince: false);

        self::assertSame([0, [
            '[SAME] semitexa-core: no source change since 2026.07.01.0900',
            'No break on the @api surface.',
        ]], $this->gate(null));
    }

    /**
     * Roave checks out the old tag with composer, which prints "No security
     * vulnerability advisories found." to stdout before the report: read as a
     * whole the output never decoded, and every package came back unchecked.
     */
    #[Test]
    public function the_report_is_read_past_what_composer_prints_before_it(): void
    {
        $this->released();

        [$status, $out] = $this->gate(self::BREAKS, null, "No security vulnerability advisories found.\nNo security vulnerability advisories found.\n");

        self::assertSame(1, $status);
        self::assertSame([
            '[BREAK] semitexa-core (since 2026.07.01.0900): Method Semitexa\\Core\\Clock#old() was removed — @api and never deprecated',
            '[BREAK] semitexa-core (since 2026.07.01.0900): Method b() was added to interface Semitexa\\Core\\Port — @api and never deprecated',
        ], $out);
    }

    /** "No break" read from a roave that could not check, or from a break it could not read, would pass a break. */
    #[Test]
    public function roave_that_cannot_check_or_be_read_fails_the_gate(): void
    {
        $this->released();

        self::assertSame([1, ['[UNCHECKED] semitexa-core: roave did not check it (exit 2)']], $this->gate(null));
        self::assertSame([1, ['[UNCHECKED] semitexa-core: roave reported break #0 without a description']], $this->gate([], ['errors' => [['nope' => 1]]]));
        self::assertSame([1, ['[UNCHECKED] semitexa-core: roave reported break #0 without a description']], $this->gate([], ['errors' => [['description' => '  ']]]));
        self::assertSame([1, ['[UNCHECKED] semitexa-core: roave said it found breaks and listed none']], $this->gate([], ['errors' => []]));
        self::assertSame([1, ['[UNCHECKED] semitexa-core: roave checked it (exit 3), but its report could not be read']], $this->gate([], ['no' => 'errors']));
    }

    /**
     * The operator's way past a tag roave cannot read (core 2026.11.01.0448
     * declares ThemeValue in ThemeLayer.php): declared per package and tag,
     * with its reason in the report.
     */
    #[Test]
    public function an_exempted_tag_roave_cannot_read_passes_with_its_reason(): void
    {
        $this->released();

        // The console wrapped the class name mid-word; the match ignores that.
        self::assertSame([0, [
            '[EXEMPT] semitexa-core (since 2026.07.01.0900): roave did not check it (exit 1): Roave\\BetterReflection\\Reflection\\ReflectionClass "Semitexa\\Co re\\Clock" could not be found in the located source — exempt: Clock moved to its own file',
            'No break on the @api surface.',
        ]], $this->gate(null, exemptions: self::CLOCK_EXEMPT, exit: 1, stderr: self::UNREADABLE_TAG));
    }

    /**
     * Only the error it names: the container, composer or another roave error
     * failing at that tag ends with the same exit 1, and is not what the
     * operator exempted.
     */
    #[Test]
    public function an_exemption_covers_only_the_error_it_names(): void
    {
        $this->released();

        foreach (['service "app" is not running', 'Class "Semitexa\\Core\\Other" could not be found', ''] as $stderr) {
            $said = $stderr === '' ? '' : ': ' . $stderr;
            self::assertSame([1, [
                '[UNUSED EXEMPTION] semitexa-core at 2026.07.01.0900: nothing needed it; remove it from the exemptions',
                '[UNCHECKED] semitexa-core: roave did not check it (exit 1)' . $said . ' (the exemption for this tag covers only "Semitexa\\Core\\Clock" could not be found")',
            ]], $this->gate(null, exemptions: self::CLOCK_EXEMPT, exit: 1, stderr: $stderr), $stderr);
        }
    }

    /** It forgives an unreadable tag, never a break roave did report; read, the tag needed no exemption. */
    #[Test]
    public function an_exemption_does_not_forgive_a_break(): void
    {
        $this->released();

        [$status, $out] = $this->gate(self::BREAKS, exemptions: self::CLOCK_EXEMPT);

        self::assertSame(1, $status);
        self::assertSame([
            '[UNUSED EXEMPTION] semitexa-core at 2026.07.01.0900: nothing needed it; remove it from the exemptions',
            '[BREAK] semitexa-core (since 2026.07.01.0900): Method Semitexa\\Core\\Clock#old() was removed — @api and never deprecated',
            '[BREAK] semitexa-core (since 2026.07.01.0900): Method b() was added to interface Semitexa\\Core\\Port — @api and never deprecated',
        ], $out);
        // Nor a report roave did give but that cannot be judged.
        self::assertSame([1, [
            '[UNUSED EXEMPTION] semitexa-core at 2026.07.01.0900: nothing needed it; remove it from the exemptions',
            '[UNCHECKED] semitexa-core: roave said it found breaks and listed none',
        ]], $this->gate([], ['errors' => []], exemptions: self::CLOCK_EXEMPT));
        // Exit 3 is roave having checked: a report without its errors is not an unreadable tag.
        self::assertSame([1, [
            '[UNUSED EXEMPTION] semitexa-core at 2026.07.01.0900: nothing needed it; remove it from the exemptions',
            '[UNCHECKED] semitexa-core: roave checked it (exit 3), but its report could not be read',
        ]], $this->gate([], ['no' => 'errors'], exemptions: self::CLOCK_EXEMPT));
    }

    /** Another tag's or another package's exemption covers nothing here, and is shown for removal. */
    #[Test]
    public function an_exemption_for_another_tag_does_not_apply(): void
    {
        $this->released();

        self::assertSame([1, [
            '[UNUSED EXEMPTION] semitexa-core at 2026.01.01.0900: nothing needed it; remove it from the exemptions',
            '[UNCHECKED] semitexa-core: roave did not check it (exit 2)',
        ]], $this->gate(null, exemptions: ['semitexa-core' => ['2026.01.01.0900' => ['reason' => 'an older cut', 'error' => 'could not be found']]]));
        self::assertSame([1, [
            '[UNUSED EXEMPTION] semitexa-orm at 2026.07.01.0900: nothing needed it; remove it from the exemptions',
            '[UNCHECKED] semitexa-core: roave did not check it (exit 2)',
        ]], $this->gate(null, exemptions: ['semitexa-orm' => ['2026.07.01.0900' => ['reason' => 'another package', 'error' => 'could not be found']]]));
    }

    /** A checker that never ran is not a tag roave could not read: no exemption covers it. */
    #[Test]
    public function an_exemption_does_not_cover_a_checker_that_could_not_run(): void
    {
        $this->released();

        foreach ([125, 126, 127] as $exit) {
            self::assertSame([1, [
                '[UNUSED EXEMPTION] semitexa-core at 2026.07.01.0900: nothing needed it; remove it from the exemptions',
                "[UNCHECKED] semitexa-core: the checker could not be run (exit {$exit}): sh: roave: not found",
            ]], $this->gate(null, exemptions: self::CLOCK_EXEMPT, exit: $exit, stderr: 'sh: roave: not found'));
        }
        // Even when it prints the very error the exemption names.
        self::assertSame([1, [
            '[UNUSED EXEMPTION] semitexa-core at 2026.07.01.0900: nothing needed it; remove it from the exemptions',
            '[UNCHECKED] semitexa-core: the checker could not be run (exit 127): Roave\\BetterReflection\\Reflection\\ReflectionClass "Semitexa\\Co re\\Clock" could not be found in the located source',
        ]], $this->gate(null, exemptions: self::CLOCK_EXEMPT, exit: 127, stderr: self::UNREADABLE_TAG));
    }

    /** An exemption without its reason exempts nothing, and says so. */
    #[Test]
    public function an_exemption_without_a_reason_fails_the_gate(): void
    {
        $this->released();

        [$status, $out] = $this->gate(null, exemptions: ['semitexa-core' => ['2026.07.01.0900' => ['reason' => ' ', 'error' => 'x']]]);

        self::assertSame(1, $status);
        self::assertSame([$this->root . '/exemptions.json: semitexa-core at 2026.07.01.0900 needs the reason it is exempt and the error roave gives there.'], $out);
        // Nor without the error it covers, or as a bare reason.
        foreach ([['reason' => 'moved'], ['reason' => 'moved', 'error' => ' '], 'moved'] as $exemption) {
            self::assertSame([1, [$this->root . '/exemptions.json: semitexa-core at 2026.07.01.0900 needs the reason it is exempt and the error roave gives there.']], $this->gate(null, exemptions: ['semitexa-core' => ['2026.07.01.0900' => $exemption]]));
        }
        // A file that is not package => tag => reason exempts nothing either.
        self::assertSame([1, [$this->root . '/exemptions.json is not a JSON object of exemptions.']], $this->gate(null, exemptions: '{"semitexa-core": '));
        self::assertSame([1, [$this->root . '/exemptions.json is not a JSON object of exemptions.']], $this->gate(null, exemptions: '["semitexa-core"]'));
        self::assertSame([1, [$this->root . '/exemptions.json is not a JSON object of exemptions.']], $this->gate(null, exemptions: '[]'));
        self::assertSame([1, [$this->root . '/exemptions.json: "semitexa-core" must map tags to reasons.']], $this->gate(null, exemptions: '{"semitexa-core": {}}'));
    }

    /**
     * A package tagged 2026.07.01.0900 with Clock (old() deprecated or not),
     * Inner and Port; with $deprecatedLater the deprecation is added after that
     * tag and shipped in 2026.10.20.0900 instead; $laterMethod is a method added
     * in that later release; with $changedSince false nothing under src or in
     * composer.json's autoload moves after the last tag.
     *
     * @param array<string, string> $extra more files at the first tag
     */
    private function released(string $oldDoc = '', bool $api = true, bool $deprecatedLater = false, array $extra = [], string $laterMethod = '', string $classDoc = '', bool $changedSince = true): void
    {
        $dir = $this->root . '/packages/semitexa-core';
        $mark = $api ? ($classDoc !== '' ? "/**\n * @api\n * {$classDoc}\n */" : '/** @api */') : '';
        $clock = static fn (string $doc): string => "<?php\nnamespace Semitexa\\Core;\n{$mark}\nfinal class Clock\n{\n    public function now(): int { return 0; }\n    {$doc}\n    public function old(): int { return 1; }\n}\n";
        file_put_contents($dir . '/composer.json', '{"name":"semitexa/core"}');
        file_put_contents($dir . '/src/Clock.php', $clock($deprecatedLater ? '' : $oldDoc));
        file_put_contents($dir . '/src/Inner.php', "<?php\nnamespace Semitexa\\Core;\nfinal class Inner { public function gone(): void {} }\n");
        file_put_contents($dir . '/src/Port.php', "<?php\nnamespace Semitexa\\Core;\n{$mark}\ninterface Port { public function a(): void; }\n");
        foreach ($extra as $file => $source) {
            file_put_contents($dir . '/src/' . $file, $source);
        }
        $this->git($dir, ['init -q', 'config user.email t@example.test', 'config user.name T', 'add -A', 'commit -q -m released']);
        $this->git($dir, ['tag -a -m cut 2026.07.01.0900'], '2026-07-01T09:00:00Z');
        if ($deprecatedLater || $laterMethod !== '') {
            file_put_contents($dir . '/src/Clock.php', str_replace("    public function now(): int { return 0; }\n", "    public function now(): int { return 0; }\n" . $laterMethod, $clock($oldDoc)));
            $this->git($dir, ['commit -q -am deprecate']);
            $this->git($dir, ['tag -a -m cut 2026.10.20.0900'], '2026-10-20T09:00:00Z');
        }
        if ($changedSince) {
            // The code moved on since the tag: what roave is there to judge.
            file_put_contents($dir . '/src/Later.php', "<?php\nnamespace Semitexa\\Core;\nfinal class Later {}\n");
            $this->git($dir, ['add -A', 'commit -q -m after']);
        } else {
            $this->git($dir, ['commit -q --allow-empty -m after']);
        }
    }

    /** @param list<string> $commands */
    private function git(string $dir, array $commands, ?string $date = null): void
    {
        foreach ($commands as $command) {
            $env = $date !== null ? 'GIT_COMMITTER_DATE=' . escapeshellarg($date) . ' ' : '';
            exec(sprintf('%sgit -C %s %s 2>&1', $env, escapeshellarg($dir), $command), $o, $exit);
            self::assertSame(0, $exit, "git {$command}: " . implode("\n", $o));
        }
    }

    /**
     * @param list<string>|null $breaks what the stand-in roave reports; null: it fails (exit 2)
     * @param string $before what it prints to stdout ahead of the report
     * @param array<string, mixed>|null $report a raw report instead
     * @param int $exit the stand-in's exit code when it reports nothing ($breaks and $report null)
     * @param string $stderr what it prints to stderr then
     * @param array<string, array<string, mixed>>|string $exemptions the operator's (a string: the file as written), none by default (not the gate's real file)
     * @return array{int, list<string>}
     */
    private function gate(?array $breaks, ?array $report = null, string $before = '', array|string $exemptions = [], int $exit = 2, string $stderr = '', string $env = '', string $args = ''): array
    {
        $exemptionsFile = $this->root . '/exemptions.json';
        file_put_contents($exemptionsFile, is_string($exemptions) ? $exemptions : ($exemptions === [] ? '{}' : (string) json_encode($exemptions)));
        $fake = $this->root . '/roave';
        $json = json_encode($report ?? ['errors' => array_map(static fn (string $d): array => ['description' => $d], $breaks ?? [])]);
        $record = 'printf \'%s\\n\' "$*" >> ' . escapeshellarg($this->root . '/roave.args') . "\n";
        file_put_contents($fake, $breaks === null && $report === null ? "#!/bin/sh\n{$record}printf '%s' " . escapeshellarg($stderr) . " >&2\nexit {$exit}\n" : "#!/bin/sh\n{$record}printf '%s' " . escapeshellarg($before . (string) $json) . "\nexit 3\n");
        chmod($fake, 0o755);
        $loader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        self::assertIsString($loader);
        $script = dirname(__DIR__, 2) . '/resources/skills/release-readiness/scripts/release-bc-gate.php';
        exec(sprintf(
            '%sRELEASE_ROOT=%s SEMITEXA_AUTOLOAD=%s SEMITEXA_BC_CHECK=%s SEMITEXA_BC_EXEMPTIONS=%s php %s --version=2026.10.31.0900%s 2>&1',
            $env,
            escapeshellarg($this->root),
            escapeshellarg(dirname($loader, 2) . '/autoload.php'),
            escapeshellarg($fake),
            escapeshellarg($exemptionsFile),
            escapeshellarg($script),
            $args,
        ), $out, $status);

        return [$status, $out];
    }
}

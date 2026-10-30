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
        $this->git($dir, ['commit -q --allow-empty -m after']);

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

    /** "No break" read from a roave that could not check, or from a break it could not read, would pass a break. */
    #[Test]
    public function roave_that_cannot_check_or_be_read_fails_the_gate(): void
    {
        $this->released();

        self::assertSame([1, ['[UNCHECKED] semitexa-core: roave did not check it (exit 2)']], $this->gate(null));
        self::assertSame([1, ['[UNCHECKED] semitexa-core: roave reported break #0 without a description']], $this->gate([], ['errors' => [['nope' => 1]]]));
        self::assertSame([1, ['[UNCHECKED] semitexa-core: roave reported break #0 without a description']], $this->gate([], ['errors' => [['description' => '  ']]]));
        self::assertSame([1, ['[UNCHECKED] semitexa-core: roave said it found breaks and listed none']], $this->gate([], ['errors' => []]));
    }

    /**
     * A package tagged 2026.07.01.0900 with Clock (old() deprecated or not),
     * Inner and Port; with $deprecatedLater the deprecation is added after that
     * tag and shipped in 2026.10.20.0900 instead; $laterMethod is a method added
     * in that later release.
     *
     * @param array<string, string> $extra more files at the first tag
     */
    private function released(string $oldDoc = '', bool $api = true, bool $deprecatedLater = false, array $extra = [], string $laterMethod = '', string $classDoc = ''): void
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
        $this->git($dir, ['commit -q --allow-empty -m after']);
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
     * @param array<string, mixed>|null $report a raw report instead
     * @return array{int, list<string>}
     */
    private function gate(?array $breaks, ?array $report = null): array
    {
        $fake = $this->root . '/roave';
        $json = json_encode($report ?? ['errors' => array_map(static fn (string $d): array => ['description' => $d], $breaks ?? [])]);
        file_put_contents($fake, $breaks === null && $report === null ? "#!/bin/sh\nexit 2\n" : "#!/bin/sh\nprintf '%s' " . escapeshellarg((string) $json) . "\nexit 3\n");
        chmod($fake, 0o755);
        $loader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        self::assertIsString($loader);
        $script = dirname(__DIR__, 2) . '/resources/skills/release-readiness/scripts/release-bc-gate.php';
        exec(sprintf(
            'RELEASE_ROOT=%s SEMITEXA_AUTOLOAD=%s SEMITEXA_BC_CHECK=%s php %s --version=2026.10.31.0900 2>&1',
            escapeshellarg($this->root),
            escapeshellarg(dirname($loader, 2) . '/autoload.php'),
            escapeshellarg($fake),
            escapeshellarg($script),
        ), $out, $status);

        return [$status, $out];
    }
}

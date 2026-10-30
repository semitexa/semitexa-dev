<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The upgrade notes a release writes: what each package deprecated since its
 * previous tag. The stability contract promises 90 days of warning, and a
 * warning nobody reads is none — a @deprecated tag in a vendor file is seen by
 * whoever opens that file.
 *
 * Built on a real tag and a change on top of it, as NewPublicApiReportTest is.
 */
final class UpgradeNotesReportTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-upgrade-' . uniqid('', true);
        mkdir($this->root . '/packages/semitexa-core/src', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function it_lists_what_was_deprecated_since_the_tag_and_nothing_older(): void
    {
        $this->release(
            ['Clock.php' => "<?php\nnamespace Semitexa\\Core;\n/** @api */\nfinal class Clock\n{\n    /** @deprecated 2026.09.01 Use now() instead. */\n    public function old(): int { return 0; }\n    public function current(): int { return 0; }\n    public const A = 1;\n}\n"],
            [
                'Clock.php' => "<?php\nnamespace Semitexa\\Core;\n/** @api */\nfinal class Clock\n{\n    /** @deprecated 2026.09.01 Use now() instead. */\n    public function old(): int { return 0; }\n    /**\n     * @deprecated 2026.10.30 Use now(), which\n     *     reads the same clock.\n     */\n    #[\\Deprecated('Use now().', since: '2026.10.30')]\n    public function current(): int { return 0; }\n    /** @deprecated 2026.10.30 Use B. */\n    public const int A = 1;\n    /** @deprecated 2026.10.30 Internal detail. */\n    private function helper(): void {}\n    public function now(): int { return Clock::class === '' ? 1 : 0; }\n}\n",
                'Legacy.php' => "<?php\nnamespace Semitexa\\Core;\n/**\n * @deprecated 2026.10.30 Use Clock instead.\n */\nfinal class Legacy\n{\n}\n",
                // A tag shown as an example inside the prose deprecates nothing.
                'Rule.php' => "<?php\nnamespace Semitexa\\Core;\n/**\n * Write it like this:\n *\n *     @deprecated 2026.11.02 Use Clock::now() instead.\n */\nfinal class Rule\n{\n}\n",
            ],
        );

        [$status, $out] = $this->notes();

        self::assertSame(0, $status);
        self::assertSame([
            '# Upgrading to 2026.10.31.0900',
            '',
            'Deprecated in this release. Parts marked `@api` keep working for at least 90 days after the release that ships the deprecation; the others are listed as a courtesy, with no such promise (see get-started/stability).',
            '',
            '## semitexa/core (since 2026.10.29.1052)',
            '',
            '- `Semitexa\Core\Clock::A` (`@api`) — 2026.10.30 Use B.',
            '- `Semitexa\Core\Clock::current()` (`@api`) — 2026.10.30 Use now(), which reads the same clock.',
            '- `Semitexa\Core\Clock::helper()` — 2026.10.30 Internal detail.',
            '- `Semitexa\Core\Legacy` — 2026.10.30 Use Clock instead.',
        ], $out, 'old() was deprecated before the tag, so it is not news');
    }

    /** What a token scan got wrong (review of #166), read from the parsed source instead. */
    #[Test]
    public function members_are_read_from_the_syntax_tree_and_a_moved_file_is_not_news(): void
    {
        $this->release(
            ['Old.php' => "<?php\nnamespace Semitexa\\Core;\n/** @deprecated 2026.09.01 Use Clock. */\nfinal class Old {}\n"],
            [
                'Old.php' => null,
                // Moved, not newly deprecated.
                'Moved.php' => "<?php\nnamespace Semitexa\\Core;\n/** @deprecated 2026.09.01 Use Clock. */\nfinal class Old {}\n",
                'Box.php' => "<?php\nnamespace Semitexa\\Core;\n/** @api */\nfinal class Box\n{\n"
                    . "    /** @deprecated 2026.10.30 Use C. */\n    public const A = 1, B = 2;\n"
                    . "    /** @deprecated 2026.10.30 Use size(). */\n    public int \$count = 0;\n"
                    . "    public function __construct(\n        /**\n         * @api\n         * @deprecated 2026.10.30 Use label().\n         */\n        public string \$name = '',\n    ) {}\n"
                    . "    private /** @deprecated 2026.10.30 Gone soon. */ function helper(): void {}\n"
                    . "    public function kind(int \$k): object\n    {\n        switch (\$k) {\n            /** @deprecated 2026.10.30 not a member */\n            case 1: break;\n        }\n"
                    . "        return new class extends \\ArrayObject {\n            /** @deprecated 2026.10.30 anonymous */\n            public function old(): void {}\n        };\n    }\n}\n"
                    . "/** @deprecated 2026.10.30 a function, no member */\nfunction loose(): void {}\n",
            ],
        );

        [$status, $out] = $this->notes();

        self::assertSame(0, $status, implode("\n", $out));
        self::assertSame([
            '- `Semitexa\Core\Box::$count` — 2026.10.30 Use size().',
            '- `Semitexa\Core\Box::$name` (`@api`) — 2026.10.30 Use label().',
            '- `Semitexa\Core\Box::A` (`@api`) — 2026.10.30 Use C.',
            '- `Semitexa\Core\Box::B` (`@api`) — 2026.10.30 Use C.',
        ], array_values(array_filter($out, static fn (string $l): bool => str_starts_with($l, '- '))), 'both grouped constants; an @api class\'s property only by its own mark; a docblock after the modifier documents nothing (as for phpDocumentor), so the private helper() is not listed, let alone as @api; no switch label, anonymous member, function or moved class');
    }

    /** A dangling link is a file that is there and cannot be read, not a deleted one: the notes fail. */
    #[Test]
    public function a_changed_file_that_cannot_be_read_fails_the_notes(): void
    {
        $this->release(['Clock.php' => "<?php\nnamespace Semitexa\\Core;\nfinal class Clock {}\n"], []);
        $dir = $this->root . '/packages/semitexa-core';
        symlink($dir . '/nowhere.php', $dir . '/src/Broken.php');
        exec(sprintf('git -C %s add -A 2>&1 && git -C %s commit -q -m link 2>&1', escapeshellarg($dir), escapeshellarg($dir)));

        [$status, $out] = $this->notes();

        self::assertSame(1, $status);
        self::assertSame('Not read, so not in the notes: ' . $dir . '/src/Broken.php', end($out));
    }

    /** Rerun after the release is tagged, the stage compared the tree with itself and found nothing. */
    #[Test]
    public function the_tag_of_this_release_is_not_the_one_compared_with(): void
    {
        $this->release(['Clock.php' => "<?php\nnamespace Semitexa\\Core;\nfinal class Clock {}\n"], ['Clock.php' => "<?php\nnamespace Semitexa\\Core;\n/** @deprecated 2026.10.30 Use Time. */\nfinal class Clock {}\n"]);
        // Annotated and dated later, so it is the newest tag by creation date, as it is after the cut.
        exec(sprintf('GIT_COMMITTER_DATE=2030-01-01T00:00:00Z git -C %s -c user.email=t@example.test -c user.name=T tag -a -m cut 2026.10.31.0900 2>&1', escapeshellarg($this->root . '/packages/semitexa-core')), $tagged, $exit);
        self::assertSame(0, $exit, implode("\n", $tagged));

        [$status, $out] = $this->notes();

        self::assertSame(0, $status);
        self::assertSame(['## semitexa/core (since 2026.10.29.1052)', '', '- `Semitexa\Core\Clock` — 2026.10.30 Use Time.'], array_slice($out, 4, 3));
    }

    /**
     * A manifest it cannot read gave the section the directory's name, and the notes
     * passed; with nothing deprecated it was not read at all.
     *
     * @return iterable<string, array{string}>
     */
    public static function afterTheTag(): iterable
    {
        yield 'with a deprecation' => ["<?php\nnamespace Semitexa\\Core;\n/** @deprecated 2026.10.30 Use Time. */\nfinal class Clock {}\n"];
        yield 'without one' => ["<?php\nnamespace Semitexa\\Core;\nfinal class Clock { public function now(): int { return 0; } }\n"];
    }

    #[Test]
    #[DataProvider('afterTheTag')]
    public function a_package_manifest_that_cannot_be_read_fails_the_notes(string $now): void
    {
        $this->release(['Clock.php' => "<?php\nnamespace Semitexa\\Core;\nfinal class Clock {}\n"], ['Clock.php' => $now]);
        // A dangling link: there, and unreadable even to root.
        $manifest = $this->root . '/packages/semitexa-core/composer.json';
        unlink($manifest);
        symlink($this->root . '/nowhere.json', $manifest);

        [$status, $out] = $this->notes();

        self::assertSame(1, $status);
        self::assertSame('Not read, so not in the notes: ' . $manifest, end($out));
    }

    #[Test]
    public function a_release_without_deprecations_says_so(): void
    {
        $this->release(['Clock.php' => "<?php\nnamespace Semitexa\\Core;\nfinal class Clock {}\n"], ['Clock.php' => "<?php\nnamespace Semitexa\\Core;\nfinal class Clock { public function now(): int { return 0; } }\n"]);

        self::assertSame([0, ['# Upgrading to 2026.10.31.0900', '', 'Nothing was deprecated in this release.']], $this->notes());
    }

    /**
     * @param array<string, string> $released files at the tag
     * @param array<string, string|null> $now files in the working tree after it (null: deleted)
     */
    private function release(array $released, array $now): void
    {
        $dir = $this->root . '/packages/semitexa-core';
        file_put_contents($dir . '/composer.json', json_encode(['name' => 'semitexa/core']));
        foreach ($released as $file => $source) {
            file_put_contents($dir . '/src/' . $file, $source);
        }
        foreach (['init -q', 'config user.email test@example.test', 'config user.name Test', 'add -A', 'commit -q -m released', 'tag 2026.10.29.1052'] as $command) {
            exec(sprintf('git -C %s %s 2>&1', escapeshellarg($dir), $command), $out, $exit);
            self::assertSame(0, $exit, "git {$command}: " . implode("\n", $out));
        }
        foreach ($now as $file => $source) {
            if ($source === null) {
                unlink($dir . '/src/' . $file);
            } else {
                file_put_contents($dir . '/src/' . $file, $source);
            }
        }
        // The release clone tags committed master heads: what follows the tag is committed too.
        exec(sprintf('git -C %s add -A 2>&1 && git -C %s commit -q --allow-empty -m after 2>&1', escapeshellarg($dir), escapeshellarg($dir)), $out, $exit);
        self::assertSame(0, $exit, implode("\n", $out));
    }

    /** @return array{int, list<string>} */
    private function notes(): array
    {
        $script = dirname(__DIR__, 2) . '/resources/skills/release-readiness/scripts/release-upgrade-notes.php';
        // The parser comes from the autoloader running this test (a release clone has its
        // own): vendor/composer/ClassLoader.php sits two levels under vendor/autoload.php.
        $loader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        self::assertIsString($loader);
        $autoload = dirname($loader, 2) . '/autoload.php';
        exec(sprintf('RELEASE_ROOT=%s SEMITEXA_AUTOLOAD=%s php %s --version=2026.10.31.0900 2>&1', escapeshellarg($this->root), escapeshellarg($autoload), escapeshellarg($script)), $out, $status);

        return [$status, $out];
    }
}

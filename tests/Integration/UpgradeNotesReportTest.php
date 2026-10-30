<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

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
            'Deprecated in this release. A deprecated part keeps working for at least 90 days after the release that ships it; `@api` parts are promised that, the rest is a courtesy (see get-started/stability).',
            '',
            '## semitexa/core (since 2026.10.29.1052)',
            '',
            '- `Semitexa\Core\Clock::A` (`@api`) — 2026.10.30 Use B.',
            '- `Semitexa\Core\Clock::current()` (`@api`) — 2026.10.30 Use now(), which reads the same clock.',
            '- `Semitexa\Core\Clock::helper()` — 2026.10.30 Internal detail.',
            '- `Semitexa\Core\Legacy` — 2026.10.30 Use Clock instead.',
        ], $out, 'old() was deprecated before the tag, so it is not news');
    }

    #[Test]
    public function a_release_without_deprecations_says_so(): void
    {
        $this->release(['Clock.php' => "<?php\nnamespace Semitexa\\Core;\nfinal class Clock {}\n"], ['Clock.php' => "<?php\nnamespace Semitexa\\Core;\nfinal class Clock { public function now(): int { return 0; } }\n"]);

        self::assertSame([0, ['# Upgrading to 2026.10.31.0900', '', 'Nothing was deprecated in this release.']], $this->notes());
    }

    /**
     * @param array<string, string> $released files at the tag
     * @param array<string, string> $now files in the working tree after it
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
            file_put_contents($dir . '/src/' . $file, $source);
        }
        // The release clone tags committed master heads: what follows the tag is committed too.
        exec(sprintf('git -C %s add -A 2>&1 && git -C %s commit -q -m after 2>&1', escapeshellarg($dir), escapeshellarg($dir)), $out, $exit);
        self::assertSame(0, $exit, implode("\n", $out));
    }

    /** @return array{int, list<string>} */
    private function notes(): array
    {
        $script = dirname(__DIR__, 2) . '/resources/skills/release-readiness/scripts/release-upgrade-notes.php';
        exec(sprintf('RELEASE_ROOT=%s php %s --version=2026.10.31.0900 2>&1', escapeshellarg($this->root), escapeshellarg($script)), $out, $status);

        return [$status, $out];
    }
}

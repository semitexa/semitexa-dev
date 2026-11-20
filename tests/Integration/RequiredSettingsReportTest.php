<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a release newly requires, written at the cut: the settings and doctor
 * checks a package declares now and did not at its previous tag. A consumer's
 * prod returned 500 on 2026-11-20 for a missing APP_SECRET no CHANGELOG had
 * named, so a newly named setting that the CHANGELOG leaves out stops the cut
 * (tk-new-required-setting-reaches-prod-silently).
 *
 * Built on a real tag and a change on top of it, as UpgradeNotesReportTest is.
 */
final class RequiredSettingsReportTest extends TestCase
{
    private const string TAG = '2026.10.29.1052';
    private const string PLAIN_CHECK = "<?php\nnamespace Semitexa\\Core\\Http;\nuse Semitexa\\Core\\Attribute\\AsDoctorCheck;\n#[AsDoctorCheck(name: 'http.compression', package: 'semitexa/core')]\nfinal class CompressionDoctorCheck {}\n";
    private const string TOKEN_CHECK = "<?php\nnamespace Semitexa\\Core\\Security;\nuse Semitexa\\Core\\Attribute\\AsDoctorCheck;\n#[AsDoctorCheck(name: 'foo.token', package: 'semitexa/core', settings: ['FOO_TOKEN'])]\nfinal class FooTokenDoctorCheck {}\n";

    private string $root;
    private string $package;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-required-settings-' . uniqid('', true);
        $this->package = $this->root . '/packages/semitexa-core';
        mkdir($this->package . '/src', 0777, true);
        file_put_contents($this->package . '/composer.json', json_encode(['name' => 'semitexa/core']));
        $this->git('init -q');
        $this->git('config user.email test@example.test');
        $this->git('config user.name Test');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_setting_the_changelog_names_is_listed_and_the_stage_passes(): void
    {
        $this->revision(['src/CompressionDoctorCheck.php' => self::PLAIN_CHECK, 'CHANGELOG.md' => "# Changelog\n"], self::TAG);
        $this->revision(['src/FooTokenDoctorCheck.php' => self::TOKEN_CHECK, 'CHANGELOG.md' => "# Changelog\n\n- `FOO_TOKEN` is required outside dev.\n"]);

        [$status, $out] = $this->stage();

        self::assertSame(0, $status, implode("\n", $out));
        self::assertSame([
            '## New required settings',
            '',
            'Set these before the new code serves requests: `bin/semitexa system:doctor` fails without them, and so does `bin/semitexa update`.',
            '',
            '- `FOO_TOKEN` — check `foo.token`, semitexa/core (since 2026.10.29.1052)',
            '',
            '## New doctor checks',
            '',
            '- `foo.token` — semitexa/core (since 2026.10.29.1052)',
        ], $out, 'http.compression was declared at the tag, so it is not news');
    }

    #[Test]
    public function a_setting_the_changelog_leaves_out_fails_the_stage_and_is_still_listed(): void
    {
        $this->revision(['src/CompressionDoctorCheck.php' => self::PLAIN_CHECK, 'CHANGELOG.md' => "# Changelog\n"], self::TAG);
        $this->revision(['src/FooTokenDoctorCheck.php' => self::TOKEN_CHECK, 'CHANGELOG.md' => "# Changelog\n\n- A new doctor check for the token.\n"]);

        [$status, $out] = $this->stage();

        self::assertSame(1, $status, implode("\n", $out));
        self::assertContains('- `FOO_TOKEN` — check `foo.token`, semitexa/core (since 2026.10.29.1052)', $out);
        self::assertSame(
            '  semitexa/core: FOO_TOKEN is newly required (doctor check foo.token) and ' . $this->package . '/CHANGELOG.md does not mention it.',
            end($out),
        );
    }

    #[Test]
    public function the_sections_join_the_upgrade_notes_and_a_second_run_replaces_them(): void
    {
        $this->revision(['src/CompressionDoctorCheck.php' => self::PLAIN_CHECK], self::TAG);
        $this->revision(['src/FooTokenDoctorCheck.php' => self::TOKEN_CHECK, 'CHANGELOG.md' => "FOO_TOKEN\n"]);
        $notes = $this->root . '/var/UPGRADE.md';
        mkdir(dirname($notes));
        file_put_contents($notes, "# Upgrading to 2026.10.31.0900\n\nNothing was deprecated in this release.\n");

        $this->stage('--write=' . escapeshellarg($notes));
        [$status] = $this->stage('--write=' . escapeshellarg($notes));

        self::assertSame(0, $status);
        self::assertSame(
            "# Upgrading to 2026.10.31.0900\n\nNothing was deprecated in this release.\n\n"
            . "## New required settings\n\n"
            . "Set these before the new code serves requests: `bin/semitexa system:doctor` fails without them, and so does `bin/semitexa update`.\n\n"
            . "- `FOO_TOKEN` — check `foo.token`, semitexa/core (since 2026.10.29.1052)\n\n"
            . "## New doctor checks\n\n"
            . "- `foo.token` — semitexa/core (since 2026.10.29.1052)\n",
            file_get_contents($notes),
        );
    }

    /** A setting that only moved to another check was required before. */
    #[Test]
    public function an_unchanged_package_gives_none_and_so_does_a_setting_that_moved(): void
    {
        $this->revision(['src/CompressionDoctorCheck.php' => self::PLAIN_CHECK, 'src/FooTokenDoctorCheck.php' => self::TOKEN_CHECK], self::TAG);
        $none = ['## New required settings', '', 'none', '', '## New doctor checks', '', 'none'];

        self::assertSame([0, $none], $this->stage());

        $this->revision(['src/FooTokenDoctorCheck.php' => str_replace("'foo.token'", "'foo.signing'", self::TOKEN_CHECK)]);

        self::assertSame(
            [0, ['## New required settings', '', 'none', '', '## New doctor checks', '', '- `foo.signing` — semitexa/core (since 2026.10.29.1052)']],
            $this->stage(),
            'no CHANGELOG at all, and it passes: FOO_TOKEN is not new',
        );
    }

    /** The tag's copy of the file is gone from the object store: what it declared is unknown. */
    #[Test]
    public function a_file_that_cannot_be_read_at_the_tag_fails_the_stage(): void
    {
        $this->revision(['src/FooTokenDoctorCheck.php' => self::TOKEN_CHECK, 'CHANGELOG.md' => "FOO_TOKEN\n"], self::TAG);
        $this->revision(['src/CompressionDoctorCheck.php' => self::PLAIN_CHECK]);
        $blob = $this->git('rev-parse ' . self::TAG . ':src/FooTokenDoctorCheck.php')[0];
        self::assertTrue(unlink($this->package . '/.git/objects/' . substr($blob, 0, 2) . '/' . substr($blob, 2)));

        [$status, $out] = $this->stage();

        self::assertSame(1, $status, implode("\n", $out));
        self::assertSame('Not read, so not in the notes: ' . $this->package . ' (doctor checks at 2026.10.29.1052)', end($out));
    }

    /** Settings built from a constant cannot be compared with another revision. */
    #[Test]
    public function a_declaration_that_cannot_be_read_fails_the_stage(): void
    {
        $this->revision(['src/FooTokenDoctorCheck.php' => str_replace("['FOO_TOKEN']", '[self::SETTING]', self::TOKEN_CHECK)], self::TAG);
        $this->revision(['src/FooTokenDoctorCheck.php' => self::TOKEN_CHECK, 'CHANGELOG.md' => "FOO_TOKEN\n"]);

        [$status, $out] = $this->stage();

        self::assertSame(1, $status, implode("\n", $out));
        self::assertSame(
            'Not read, so not in the notes: ' . $this->package . '/src/FooTokenDoctorCheck.php (at 2026.10.29.1052) (the settings of doctor check foo.token are not a list of string literals)',
            end($out),
        );
    }

    #[Test]
    public function a_package_with_no_previous_tag_counts_its_checks_as_new(): void
    {
        $this->revision(['src/CompressionDoctorCheck.php' => self::PLAIN_CHECK, 'src/FooTokenDoctorCheck.php' => self::TOKEN_CHECK, 'CHANGELOG.md' => "FOO_TOKEN\n"]);

        [$status, $out] = $this->stage();

        self::assertSame(0, $status, implode("\n", $out));
        self::assertSame([
            '- `FOO_TOKEN` — check `foo.token`, semitexa/core (since its first release)',
            '- `foo.token` — semitexa/core (since its first release)',
            '- `http.compression` — semitexa/core (since its first release)',
        ], array_values(array_filter($out, static fn (string $l): bool => str_starts_with($l, '- '))));
    }

    /**
     * Writes the files (paths from the package root) and commits them: the
     * release clone tags committed master heads.
     *
     * @param array<string, string> $files
     */
    private function revision(array $files, ?string $tag = null): void
    {
        foreach ($files as $file => $source) {
            file_put_contents($this->package . '/' . $file, $source);
        }
        $this->git('add -A');
        $this->git('commit -q -m revision');
        if ($tag !== null) {
            $this->git('tag ' . $tag);
        }
    }

    /** @return list<string> */
    private function git(string $command): array
    {
        exec(sprintf('git -C %s %s 2>&1', escapeshellarg($this->package), $command), $out, $exit);
        self::assertSame(0, $exit, "git {$command}: " . implode("\n", $out));

        return $out;
    }

    /** @return array{int, list<string>} */
    private function stage(string $extra = ''): array
    {
        $script = dirname(__DIR__, 2) . '/resources/skills/release-readiness/scripts/release-required-settings.php';
        // The parser comes from the autoloader running this test (a release clone has its
        // own): vendor/composer/ClassLoader.php sits two levels under vendor/autoload.php.
        $loader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        self::assertIsString($loader);
        $autoload = dirname($loader, 2) . '/autoload.php';
        exec(sprintf('RELEASE_ROOT=%s SEMITEXA_AUTOLOAD=%s php %s --version=2026.10.31.0900 %s 2>&1', escapeshellarg($this->root), escapeshellarg($autoload), escapeshellarg($script), $extra), $out, $status);

        return [$status, $out];
    }
}

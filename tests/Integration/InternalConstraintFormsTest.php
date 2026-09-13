<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which constraints a package may put on another Semitexa package.
 *
 * The release is a dated set, so `*` and an exact date were the only two forms
 * the gate accepted — and neither can say "at least". A consumer installing one
 * package directly beside an older pinned core got a resolution composer was
 * happy with and a worker that died on a missing class. Raised by review on
 * semitexa-ledger#14, where the suggested fix was refused precisely because the
 * gate would have rejected it.
 *
 * Run against the shipped script through its own RELEASE_ROOT seam, so this
 * tests what the release actually executes rather than a copy of its rule.
 */
final class InternalConstraintFormsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-constraints-' . uniqid('', true);
        mkdir($this->root . '/packages/semitexa-ledger', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/packages/semitexa-ledger/composer.json');
        @rmdir($this->root . '/packages/semitexa-ledger');
        @rmdir($this->root . '/packages');
        @rmdir($this->root);
    }

    private function script(): string
    {
        return dirname(__DIR__, 2)
            . '/resources/skills/release-readiness/scripts/release-check-internal-constraints.php';
    }

    /** @return array{exit: int, output: string} */
    private function gateOn(string $constraint): array
    {
        file_put_contents(
            $this->root . '/packages/semitexa-ledger/composer.json',
            (string) json_encode([
                'name' => 'semitexa/ledger',
                'require' => ['semitexa/core' => $constraint, 'php' => '^8.4'],
            ]),
        );

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, $this->script()],
            $descriptors,
            $pipes,
            null,
            ['RELEASE_ROOT' => $this->root, 'PATH' => getenv('PATH') ?: '/usr/bin'],
        );
        self::assertIsResource($process);

        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'output' => $output];
    }

    #[Test]
    public function a_floor_is_accepted(): void
    {
        $result = $this->gateOn('>=2026.09.13.0749');

        self::assertSame(0, $result['exit'], 'the form review asked for must not fail the release: ' . $result['output']);
    }

    /**
     * A floor WITHOUT this escape made the workspace unbuildable, and the
     * release that introduced the first floors failed preflight on it.
     *
     * Packages are developed as path repositories, where composer takes the
     * version from the git branch — `dev-master` — and a branch version
     * satisfies no date floor. The path repo is canonical, so composer cannot
     * fall back to Packagist either: it reports the whole set as
     * uninstallable. The escape says "this release or newer, OR the local
     * checkout".
     */
    #[Test]
    public function a_floor_may_admit_the_local_checkout(): void
    {
        $result = $this->gateOn('>=2026.09.13.0749 || dev-master');

        self::assertSame(0, $result['exit'], 'a path-repo workspace cannot resolve without this: ' . $result['output']);
    }

    /**
     * On an EXACT pin it is refused: ultimate's pins exist to name one
     * release, and an escape would defeat the pin.
     */
    #[Test]
    public function an_exact_pin_may_not_admit_the_local_checkout(): void
    {
        self::assertSame(1, $this->gateOn('2026.09.13.0749 || dev-master')['exit']);
    }

    /** And the escape is only ever that branch, not any branch. */
    #[Test]
    public function only_the_master_checkout_is_admitted(): void
    {
        foreach (['>=2026.09.13.0749 || dev-develop', '>=2026.09.13.0749 || dev-main', '>=2026.09.13.0749 || *'] as $bad) {
            self::assertSame(1, $this->gateOn($bad)['exit'], "'{$bad}' must not pass the gate");
        }
    }

    #[Test]
    public function the_two_forms_that_always_worked_still_do(): void
    {
        self::assertSame(0, $this->gateOn('*')['exit']);
        self::assertSame(0, $this->gateOn('2026.09.13.0749')['exit']);
        self::assertSame(0, $this->gateOn('2026.09.13.0749-beta')['exit']);
    }

    /**
     * A floor is a date, not a semver range: `^` and `~` would drift with a
     * date scheme where every release is a new "major".
     */
    #[Test]
    public function semver_ranges_are_still_refused(): void
    {
        foreach (['^2026.09.13.0749', '~2026.09.13.0749', '>2026.09.13.0749', 'dev-develop', '', '1.0'] as $bad) {
            self::assertSame(1, $this->gateOn($bad)['exit'], "'{$bad}' must not pass the gate");
        }
    }

    /** A refusal has to teach the rule, because that message is where the next person reads it. */
    #[Test]
    public function the_refusal_names_all_three_accepted_forms(): void
    {
        $output = $this->gateOn('^2026.09.13.0749')['output'];

        self::assertStringContainsString('"*"', $output);
        self::assertStringContainsString('2026.09.13.0749', $output);
        self::assertStringContainsString('>=', $output);
    }
}

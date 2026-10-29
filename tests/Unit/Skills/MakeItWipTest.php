<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * make-it's two limits: one open PR per repository (a second change on develop
 * joins the PR under review and starts it over), and at most MAKE_IT_WIP in
 * review at once (more only fills CodeRabbit's queue ahead of time).
 */
final class MakeItWipTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    #[Test]
    public function it_lists_what_is_open_and_how_many_slots_are_left(): void
    {
        [$status, $out] = $this->wip(['semitexa/semitexa-docs 109', 'semitexa/semitexa-core 200'], []);

        self::assertSame(0, $status);
        self::assertSame(['semitexa/semitexa-core#200', 'semitexa/semitexa-docs#109', 'open=2 limit=3 free=1'], $out);
    }

    #[Test]
    public function a_repository_with_an_open_pr_cannot_take_another(): void
    {
        [$status, $out] = $this->wip(['semitexa/semitexa-core 200'], ['--can-open', 'semitexa-core']);

        self::assertSame([1, ['no: semitexa/semitexa-core already has semitexa/semitexa-core#200 in review']], [$status, $out]);
    }

    #[Test]
    public function no_new_pr_opens_when_every_slot_is_taken(): void
    {
        [$status, $out] = $this->wip(['semitexa/semitexa-docs 109', 'semitexa/semitexa-core 200'], ['--can-open', 'semitexa/semitexa-orm'], wip: 2);

        self::assertSame([1, ['no: 2 of 2 review slots are taken']], [$status, $out]);
    }

    #[Test]
    public function a_free_repository_with_a_free_slot_may_open_one(): void
    {
        [$status, $out] = $this->wip(['semitexa/semitexa-docs 109'], ['--can-open', 'semitexa-orm']);

        self::assertSame([0, ['yes: semitexa/semitexa-orm has no open PR, 2 of 3 slots free']], [$status, $out]);
    }

    #[Test]
    public function a_failed_search_says_so_instead_of_reporting_nothing_open(): void
    {
        [$status, $out] = $this->wip(null, ['--can-open', 'semitexa-orm']);

        self::assertSame([2, ['ERROR gh search prs failed for owner semitexa; nothing is known about what is open']], [$status, $out]);
    }

    /**
     * Runs scripts/wip.sh against a fake gh that answers the search as the real
     * one does after --jq, or fails it when $open is null.
     *
     * @param list<string>|null $open "<owner/repo> <number>"
     * @param list<string> $args
     * @return array{int, list<string>}
     */
    private function wip(?array $open, array $args, int $wip = 3): array
    {
        $this->dir = sys_get_temp_dir() . '/make-it-wip-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0o755, true);
        $answer = $open === null ? 'exit 1' : 'printf %s ' . escapeshellarg(implode("\n", $open) . "\n");
        file_put_contents($this->dir . '/bin/gh', "#!/bin/bash\ncase \"\$1\" in\n  search) {$answer} ;;\n  *) echo \"unexpected gh \$*\" >&2; exit 1 ;;\nesac\n");
        chmod($this->dir . '/bin/gh', 0o755);

        $script = dirname(__DIR__, 3) . '/resources/skills/make-it/scripts/wip.sh';
        exec(sprintf(
            'env -i PATH=%s:/usr/bin:/bin MAKE_IT_WIP=%d bash %s %s 2>&1',
            escapeshellarg($this->dir . '/bin'),
            $wip,
            escapeshellarg($script),
            implode(' ', array_map('escapeshellarg', $args)),
        ), $out, $status);

        return [$status, $out];
    }
}

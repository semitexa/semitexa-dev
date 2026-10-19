<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Work;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Dev\Application\Service\Ai\Work\BacklogHygiene;
use Semitexa\Dev\Application\Service\Ai\Work\BacklogQuality;
use Semitexa\Dev\Application\Service\Ai\Work\Epic;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStatus;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStore;
use Semitexa\Dev\Application\Service\Ai\Work\NumberProvenance;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Semitexa\Testing\TestCase;

/**
 * A count in a next-step must carry the command that produced it (AGENTS.md
 * §3). ai:backlog stats says when one does not — as advice only: the item's
 * quality, and so which view shows it, never changes.
 */
final class NumberProvenanceTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function notes(): iterable
    {
        yield 'a bare count' => ['AsyncResourceSseServer has 47 call sites left', true];
        yield 'a count with its command' => ['47 call sites (grep -rn AsyncResourceSseServer:: packages | wc -l)', false];
        yield 'a count in a code span' => ['37 errors outside the baseline, `docker compose exec app phpstan analyse`', false];
        yield 'a date and a version' => ['measured 2026-10-17 on phpstan 2.1.40, see 18.10 note', false];
        yield 'ids and hashes' => ['done in core e318378, receipt rcpt-20261018-073240-97ca09', false];
        yield 'no number at all' => ['decide the shape first', false];
        yield 'a step number' => ['step 2 of the plan', false];
        yield 'a duration' => ['support each release for 12 months, TTL 5 min', false];
    }

    #[Test]
    #[DataProvider('notes')]
    public function a_count_is_bare_only_when_no_command_is_beside_it(string $note, bool $bare): void
    {
        self::assertSame($bare, NumberProvenance::bare($note));
    }

    #[Test]
    public function the_warning_advises_without_changing_the_quality(): void
    {
        $this->enterFixtureProjectRoot('ai-work-number-provenance');
        $tasks = new TaskStore();
        $epics = new EpicStore();
        PropertyInjector::inject($epics, new ArrayContainer([TaskStore::class => $tasks]));
        $now = '2026-10-18T00:00:00+00:00';
        $epics->save(new Epic('ep-shrink-server', 'Shrink the server', 'cut 600 lines from the server', EpicStatus::NEW, $now, $now));
        $hygiene = new BacklogHygiene();
        PropertyInjector::inject($hygiene, new ArrayContainer([EpicStore::class => $epics, TaskStore::class => $tasks]));

        $bare = new Task('tk-fix-analyser', 'ep-shrink-server', 'Fix the analyser errors', TaskStatus::NEW, 'r', 'low', ['x.php'], 'fix the 37 errors', 'tk-fix-analyser', $now, $now);
        $sourced = new Task('tk-fix-analyser', 'ep-shrink-server', 'Fix the analyser errors', TaskStatus::NEW, 'r', 'low', ['x.php'], 'fix the 37 errors (`phpstan analyse`)', 'tk-fix-analyser', $now, $now);
        $assessment = $hygiene->assessTask($bare);
        self::assertSame([NumberProvenance::WARNING], $assessment->warnings);
        self::assertSame([], $hygiene->assessTask($sourced)->warnings);
        self::assertSame(BacklogQuality::CLEAN, $assessment->quality);
        self::assertSame($hygiene->assessTask($sourced)->quality, $assessment->quality);

        $done = new Task('tk-fixed-analyser', 'ep-shrink-server', 'Fix the analyser errors', TaskStatus::DONE, 'r', 'low', ['x.php'], 'fixed the 37 errors', 'tk-fixed-analyser', $now, $now);
        self::assertSame([], $hygiene->assessTask($done)->warnings, 'a closed task is history, not a plan');

        $epic = $epics->get('ep-shrink-server');
        self::assertNotNull($epic);
        self::assertSame([NumberProvenance::WARNING], $hygiene->assessEpic($epic)->warnings);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\State;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\State\BacklogProgress;
use Semitexa\Dev\Application\Service\Ai\State\Phase;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskApproval;
use Semitexa\Dev\Application\Service\Ai\Work\TaskProof;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;

/**
 * A spec's whole way through the numbers: cut, approved, built, changed. The
 * denominator is what is approved, wherever it came from, so a spec that grows
 * lowers the percent and one that shrinks raises it, and neither is a fault.
 */
final class SpecToProgressTest extends TestCase
{
    private const NOW = '2026-11-06T00:00:00+00:00';

    private function idea(string $id): Task
    {
        return new Task($id, 'ep-spec', 't', TaskStatus::NEW, '', 'low', [], null, $id, self::NOW, self::NOW, null, Phase::WORK);
    }

    private function approved(Task $task): Task
    {
        return $task->withApproval(new TaskApproval('owner', 'Так', self::NOW), self::NOW);
    }

    private function proven(Task $task): Task
    {
        return $task->with(status: TaskStatus::DONE)->withProof(new TaskProof('rcpt-1', self::NOW, ['phpunit:X'], []));
    }

    /** @param list<Task> $tasks */
    private function line(array $tasks): string
    {
        return explode(' · ', BacklogProgress::of($tasks, false)->line())[0];
    }

    public function test_a_spec_moves_the_percent_only_as_it_should(): void
    {
        $cut = array_map($this->idea(...), ['tk-a', 'tk-b', 'tk-c', 'tk-d']);
        self::assertSame('work not measured', $this->line($cut), 'a cut nobody approved measures nothing');
        self::assertSame(4, BacklogProgress::of($cut, false)->ideas);

        $approved = array_map($this->approved(...), $cut);
        self::assertSame('work 0% (0/4, 4 open)', $this->line($approved));

        $approved[0] = $this->proven($approved[0]);
        $approved[1] = $this->proven($approved[1]);
        self::assertSame('work 50% (2/4, 2 open)', $this->line($approved));

        $grown = [...$approved, $this->approved($this->idea('tk-e')), $this->approved($this->idea('tk-f'))];
        self::assertSame('work 33% (2/6, 4 open)', $this->line($grown), 'the spec grew: the percent falls, and that is right');

        $grown[5] = $grown[5]->with(status: TaskStatus::DISCARDED);
        $grown[4] = $grown[4]->with(status: TaskStatus::DISCARDED);
        self::assertSame('work 50% (2/4, 2 open)', $this->line($grown), 'the spec dropped them: out of the denominator');
    }

    public function test_work_from_elsewhere_counts_beside_the_spec(): void
    {
        $spec = $this->proven($this->approved($this->idea('tk-a')));
        $asked = $this->approved(new Task('tk-b', 'ep-other', 't', TaskStatus::NEW, '', 'low', [], null, 'tk-b', self::NOW, self::NOW, null, Phase::WORK));

        self::assertSame('work 50% (1/2, 1 open)', $this->line([$spec, $asked]));
    }
}

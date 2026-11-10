<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiAuditCommand;
use Semitexa\Dev\Application\Console\Command\AiStateCommand;
use Semitexa\Dev\Application\Console\Command\AiWorkApproveCommand;
use Semitexa\Dev\Application\Console\Command\AiWorkCommand;
use Semitexa\Dev\Application\Service\Ai\State\AuditStore;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\VerifyReceipts;
use Semitexa\Dev\Application\Service\Ai\Work\BacklogHygiene;
use Semitexa\Dev\Application\Service\Ai\Work\Epic;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStatus;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStore;
use Semitexa\Dev\Application\Service\Ai\Work\ResumeService;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The numbers on a fresh project, end to end (tk-progress-fresh-project-check).
 *
 * Every step goes through the commands an agent or the owner runs, on an
 * empty project, and reads the ai:state line after it: nothing measured at
 * first; ideas count nothing; approved work is the denominator; a task closed
 * with a verify receipt that ran a test is done, one closed without is
 * unproven; a bug counts the moment it is filed and the percent recalculates;
 * an audit that found nothing makes an empty phase read clean, not unmeasured.
 * The receipt is written by VerifyReceipts::attach(), the code ai:verify uses.
 */
final class AiStateFreshProjectEndToEndTest extends TestCase
{
    private string $root;
    private ?string $cwd = null;

    /**
     * What these variables held before the test, restored after it (review,
     * dev#222): a session named by the caller that this temp project's registry
     * does not know would refuse the task updates, and a trace id would tag them.
     */
    private const ISOLATED_ENV = ['SEMITEXA_STATE_WITH_FRAMEWORK', 'SEMITEXA_AI_TRACE_ID', 'SEMITEXA_AGENT_SESSION', 'SEMITEXA_AGENT_IMPLICIT'];

    /** @var array<string, string|false> */
    private array $savedEnv = [];
    private TaskStore $tasks;
    private EpicStore $epics;
    private TraceStore $traces;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-fresh-numbers-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src/modules/Shop', 0755, true);
        file_put_contents($this->root . '/composer.json', '{"name":"temp/project"}');
        $this->cwd = getcwd() ?: null;
        chdir($this->root);
        ProjectRoot::reset();
        foreach (self::ISOLATED_ENV as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
        $this->tasks = new TaskStore();
        $this->epics = new EpicStore();
        PropertyInjector::inject($this->epics, new ArrayContainer([TaskStore::class => $this->tasks]));
        $this->traces = new TraceStore();
        $now = gmdate(DATE_ATOM);
        $this->epics->save(new Epic('ep-shop', 'The shop', 'Sell things', EpicStatus::NEW, $now, $now));
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        if ($this->cwd !== null) {
            chdir($this->cwd);
        }
        ProjectRoot::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function the_numbers_move_as_the_work_does(): void
    {
        self::assertSame('work not measured · right not measured · fast not measured', $this->line(), 'nothing filed, nothing measured');

        $this->work(['action' => 'start', '--epic' => 'ep-shop', '--id' => 'tk-cart', '--title' => 'A cart', '--recipe' => 'add_feature', '--phase' => 'work']);
        $this->work(['action' => 'start', '--epic' => 'ep-shop', '--id' => 'tk-checkout', '--title' => 'A checkout', '--recipe' => 'add_feature', '--phase' => 'work']);
        self::assertSame('work not measured · right not measured · fast not measured · 2 ideas', $this->line(), 'filed, not approved: ideas, in no percent');

        $this->approve('tk-cart');
        $this->approve('tk-checkout');
        self::assertSame('work 0% (0/2, 2 open) · right not measured · fast not measured', $this->line(), 'approved work is the denominator');

        // A verify run that ran a test of the cart's code, for this task's trace.
        file_put_contents($this->root . '/src/modules/Shop/Cart.php', "<?php\nfinal class Cart {}\n");
        $this->receipt('tk-cart', 'src/modules/Shop/Cart.php');
        $this->work(['action' => 'update', '--id' => 'tk-cart', '--status' => 'done']);
        self::assertSame('work 50% (1/2, 1 open) · right not measured · fast not measured', $this->line(), 'closed with a test: done');

        $this->work(['action' => 'update', '--id' => 'tk-checkout', '--status' => 'done']);
        self::assertSame('work 50% (1/2, 1 unproven) · right not measured · fast not measured', $this->line(), 'closed with no run behind it: unproven, adds nothing');

        // A bug counts the moment it is filed: no approval asked, the percent moves by itself.
        $this->work(['action' => 'start', '--epic' => 'ep-shop', '--id' => 'tk-cart-rounding', '--title' => 'The cart total rounds down', '--recipe' => 'fix_bug', '--phase' => 'work']);
        self::assertSame('work 33% (1/3, 1 open, 1 unproven) · right not measured · fast not measured', $this->line());

        $this->audit(['action' => 'record', '--phase' => 'right', '--covered' => 'the Shop module']);
        self::assertSame('work 33% (1/3, 1 open, 1 unproven) · right nothing open (audited today) · fast not measured', $this->line(), 'looked and found nothing is not unmeasured');
    }

    /** @param array<string, mixed> $input */
    private function work(array $input): void
    {
        $hygiene = new BacklogHygiene();
        PropertyInjector::inject($hygiene, new ArrayContainer([EpicStore::class => $this->epics, TaskStore::class => $this->tasks]));
        $resume = new ResumeService();
        PropertyInjector::inject($resume, new ArrayContainer([TaskStore::class => $this->tasks, TraceStore::class => $this->traces]));
        $command = new AiWorkCommand();
        PropertyInjector::inject($command, new ArrayContainer([
            TaskStore::class => $this->tasks,
            EpicStore::class => $this->epics,
            TraceStore::class => $this->traces,
            ResumeService::class => $resume,
            BacklogHygiene::class => $hygiene,
        ]));
        $this->runCommand($command, $input + ['--json' => true]);
    }

    private function approve(string $id): void
    {
        $command = new AiWorkApproveCommand();
        PropertyInjector::inject($command, new ArrayContainer([TaskStore::class => $this->tasks, EpicStore::class => $this->epics, TraceStore::class => $this->traces]));
        $this->runCommand($command, ['--id' => $id, '--words' => 'Так, роби', '--json' => true]);
    }

    /** @param array<string, mixed> $input */
    private function audit(array $input): void
    {
        $command = new AiAuditCommand();
        PropertyInjector::inject($command, new ArrayContainer([AuditStore::class => new AuditStore()]));
        $this->runCommand($command, $input);
    }

    private function line(): string
    {
        $command = new AiStateCommand();
        PropertyInjector::inject($command, new ArrayContainer([TaskStore::class => new TaskStore(), AuditStore::class => new AuditStore()]));
        $result = json_decode($this->runCommand($command, []), true);
        self::assertIsArray($result);

        return (string) ($result['line'] ?? '');
    }

    /** A passing verify run that checked $file and ran a test of it, written as ai:verify writes it. */
    private function receipt(string $trace, string $file): void
    {
        $receipts = new VerifyReceipts($this->root);
        $envelope = $receipts->attach([
            'verdict' => 'pass',
            'generated_at' => gmdate(DATE_ATOM),
            'changed_files' => [['path' => $file]],
            'results' => [['id' => 'phpunit:CartTest', 'type' => 'phpunit', 'status' => 'pass', 'exit_code' => 0, 'signal' => 'OK (1 test)']],
        ], [], null, $trace);
        self::assertArrayHasKey('receipt', $envelope, 'the receipt was written');
    }

    /** @param array<string, mixed> $input */
    private function runCommand(Command $command, array $input): string
    {
        $tester = new CommandTester($command);
        $status = $tester->execute($input, ['interactive' => false]);
        self::assertSame(0, $status, $command->getName() . ' ' . json_encode($input) . ': ' . $tester->getDisplay());

        return $tester->getDisplay();
    }
}

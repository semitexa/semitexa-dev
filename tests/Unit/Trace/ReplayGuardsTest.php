<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\QueueConfig;
use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Dev\Application\Service\Trace\CapturingQueueTransport;
use Semitexa\Dev\Application\Service\Trace\ReplayRollbackSignal;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;

/**
 * The replay sandbox promises exactly two things and these tests are those
 * promises: a write inside a replay NEVER survives it (proven by row counts,
 * not by trust in a code path), and a queue publish NEVER leaves the process
 * (captured by the stub that shadows every real transport factory).
 */
final class ReplayGuardsTest extends TestCase
{
    protected function tearDown(): void
    {
        QueueTransportRegistry::reset();
    }

    #[Test]
    public function a_write_inside_the_replay_transaction_never_commits(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute('CREATE TABLE replay_probe (id INTEGER PRIMARY KEY, label TEXT)');

        $tx = $orm->getTransactionManager();
        $outcome = null;
        try {
            $tx->run(static function ($adapter) use (&$outcome): never {
                $adapter->execute("INSERT INTO replay_probe (label) VALUES ('written-in-replay')");
                $outcome = 'handler-finished';
                throw new ReplayRollbackSignal($outcome);
            });
            self::fail('the rollback signal must propagate out of run()');
        } catch (ReplayRollbackSignal $signal) {
            self::assertSame('handler-finished', $signal->result, 'the outcome rides the signal out');
        }

        $rows = $orm->getAdapter()->query('SELECT COUNT(*) AS c FROM replay_probe')->rows;
        self::assertSame(0, (int) $rows[0]['c'], 'a replay write reached the table — the hard guard is broken');
    }

    #[Test]
    public function queue_publishes_are_captured_even_after_lazy_initialize(): void
    {
        // The trap this pins: captors registered BEFORE initialize() get
        // overwritten by the real factories when create() lazily initializes.
        // The runner initializes eagerly, then shadows — replicate that order.
        $captor = new CapturingQueueTransport();
        $factory = new class($captor) implements QueueTransportFactoryInterface {
            public function __construct(private readonly CapturingQueueTransport $captor)
            {
            }

            public function create(): QueueTransportInterface
            {
                return $this->captor;
            }
        };

        QueueTransportRegistry::reset();
        QueueTransportRegistry::initialize();
        foreach (array_unique([QueueConfig::defaultTransport(), 'in-memory', 'memory', 'database', 'nats', 'sync']) as $name) {
            QueueTransportRegistry::register($name, $factory);
        }

        QueueTransportRegistry::create(QueueConfig::defaultTransport())->publish('events', '{"probe":1}');
        QueueTransportRegistry::create('in-memory')->publish('mail', '{"probe":2}');

        $captured = $captor->drain();
        self::assertCount(2, $captured, 'every publish must land in the captor, none in a real transport');
        self::assertSame('events', $captured[0]['queue']);
    }

    #[Test]
    public function consuming_inside_a_replay_is_refused(): void
    {
        $this->expectException(\LogicException::class);

        (new CapturingQueueTransport())->consume('events', static fn () => null);
    }
}

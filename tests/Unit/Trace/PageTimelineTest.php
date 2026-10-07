<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use Semitexa\Core\Server\PageTimeline;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPageTimeline;
use Semitexa\Dev\Application\Service\Trace\PageTimelineHtmlRenderer;
use Semitexa\Dev\Application\Service\Trace\PageTimelineReader;
use Semitexa\Testing\TestCase;

/**
 * tk-ls-timeline: a page's UI events, stream frames and re-runs land, in
 * order, in one timeline per page — in development only, and a page that is
 * not named safely is not written at all.
 */
final class PageTimelineTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/semitexa-timeline-' . uniqid();
        mkdir($this->dir, 0755, true);
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_OBSERVATORY_DIR=' . $this->dir);
        PageTimeline::use(new ObservatoryPageTimeline());
    }

    protected function tearDown(): void
    {
        PageTimeline::use(null);
        putenv('APP_ENV');
        putenv('SEMITEXA_OBSERVATORY_DIR');
        foreach (glob($this->dir . '/timeline/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/timeline');
        foreach (glob($this->dir . '/*.ndjson') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    #[Test]
    public function a_page_s_events_are_kept_in_order_and_rendered(): void
    {
        PageTimeline::record('sse_page1', 'event', ['component' => 'shop.counter', 'part' => 'increment', 'event' => 'click', 'ms' => 1.2, 'effects' => ['morph']]);
        PageTimeline::record('sse_page1', 'frame', ['event' => 'ui.patch', 'id' => 'kabcdef.1', 'bytes' => 812]);
        PageTimeline::record('sse_page1', 'rerun', ['sub' => 'sse_sub', 'cause' => 'write', 'sent' => 'ui.collection.patch']);
        PageTimeline::record('../escape', 'frame', ['event' => 'x']);
        PageTimeline::record('', 'frame', []);

        $reader = new PageTimelineReader();
        self::assertSame(['sse_page1'], array_column($reader->pages(), 'session'), 'a page not named safely is not written');
        $events = $reader->events('sse_page1');
        self::assertSame(['event', 'frame', 'rerun'], array_column($events, 'type'));
        self::assertSame(['morph'], $events[0]['effects']);
        self::assertSame([], $reader->events('../escape'));

        $html = (new PageTimelineHtmlRenderer())->renderPage('sse_page1', $events);
        self::assertStringContainsString('<b>shop.counter</b> increment.click', $html);
        self::assertStringContainsString('1 event · 1 frame · 1 rerun', $html);
    }

    #[Test]
    public function outside_development_nothing_is_written(): void
    {
        putenv('APP_ENV=prod');
        PageTimeline::record('sse_page2', 'frame', ['event' => 'ui.patch']);

        self::assertSame([], (new PageTimelineReader())->pages());
    }

    /**
     * The panel showed a wallet changing every two seconds and nothing moving:
     * the signal and the push it caused never reached the file it follows.
     */
    #[Test]
    public function a_signal_and_the_push_it_caused_reach_the_pulse_file_without_the_page_s_session(): void
    {
        PageTimeline::broadcast('signal', ['tenant' => 'default', 'scope' => 'ui_playground_wallet', 'origin' => 'WalletTickTimerListener']);
        PageTimeline::record('sse_secretpage', 'rerun', ['sub' => 'sse_sub1', 'cause' => 'write', 'sent' => 'ui.island']);
        PageTimeline::record('sse_secretpage', 'rerun', ['sub' => 'sse_sub1', 'cause' => 'write', 'sent' => 'nothing']);

        $raw = (string) file_get_contents($this->dir . '/pulses-' . date('Ymd') . '.ndjson');
        $rows = array_map(static fn (string $l): array => json_decode($l, true), array_values(array_filter(explode("\n", $raw))));

        self::assertSame(['signal', 'push'], array_column($rows, 'event'), 'a re-run that sent nothing is not a push');
        self::assertSame('ui_playground_wallet', $rows[0]['scope']);
        self::assertSame('WalletTickTimerListener', $rows[0]['origin']);
        self::assertSame(substr(hash('sha256', 'sse_secretpage'), 0, 8), $rows[1]['page']);
        self::assertStringNotContainsString('sse_secretpage', $raw, 'a page\'s session id lets it subscribe: never in the pulse file');
        self::assertFileDoesNotExist($this->dir . '/journal-' . date('Ymd') . '.ndjson', 'pulses stay out of the journal');
        self::assertFileDoesNotExist($this->dir . '/timeline/' . PageTimeline::SERVER . '.ndjson', 'the server is not a page');
    }

    /**
     * Every signal used to read as if a request had sent it; a wallet ticked
     * by a worker timer looked like handler work.
     */
    #[Test]
    public function a_signal_names_the_process_that_published_it_and_a_bare_one_is_a_timer(): void
    {
        \Semitexa\Dev\Application\Service\Trace\ObservatoryContext::reset();
        PageTimeline::broadcast('signal', ['scope' => 'from_timer']);

        \Semitexa\Dev\Application\Service\Trace\ObservatoryContext::open(['id' => 'p-9-run', 'kind' => 'scheduler', 'name' => 'WalletCronJob']);
        try {
            PageTimeline::broadcast('signal', ['scope' => 'from_cron']);
        } finally {
            \Semitexa\Dev\Application\Service\Trace\ObservatoryContext::reset();
        }

        $rows = array_map(static fn (string $l): array => json_decode($l, true), array_values(array_filter(explode("\n",
            (string) file_get_contents($this->dir . '/pulses-' . date('Ymd') . '.ndjson')))));

        self::assertSame(['timer', 'scheduler'], array_column($rows, 'via'));
        self::assertArrayNotHasKey('process', $rows[0]);
        self::assertSame('p-9-run', $rows[1]['process']);
    }

    /** The scheduler planning its runs was drawn as a worker timer. */
    #[Test]
    public function a_bare_signal_from_a_console_process_is_its_own_bookkeeping_not_a_timer(): void
    {
        \Semitexa\Dev\Application\Service\Trace\ObservatoryContext::reset();
        PageTimeline::use(ObservatoryPageTimeline::forConsole());
        PageTimeline::broadcast('signal', ['scope' => 'scheduler_runs', 'origin' => 'orm write']);

        $row = json_decode(trim((string) file_get_contents($this->dir . '/pulses-' . date('Ymd') . '.ndjson')), true);

        self::assertSame('console', $row['via']);
    }
}


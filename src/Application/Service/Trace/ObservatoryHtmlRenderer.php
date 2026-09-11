<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;

/**
 * The live panel at `/__observatory`: the architecture as a moving picture.
 *
 * Every process the framework runs — HTTP request, SSE session, scheduler
 * run, queue job — is a particle travelling through the real pipeline
 * stages, dwelling at each for the share of time that stage took. Workers on
 * the left, the ticker on the right, sixty seconds of history underneath.
 *
 * HTML from PHP, same discipline as the trace viewer: dev must not depend on
 * ssr, so no Twig, no asset pipeline, no external fonts. The stylesheet and the
 * script live as plain files under the package's `resources/observatory/`
 * (assets, not code — the module-structure validator does not walk them).
 *
 * They are LINKED, not inlined. Inlining made the page one request and was
 * defended on the grounds that it works on a stack where nothing else does —
 * but the panel is opened precisely where a consumer has conventions of its
 * own, and under a Content-Security-Policy of the usual shape
 * (`script-src 'self' 'nonce-…'`) the browser silently refuses an inline script
 * with no nonce. The panel then paints its shell and stops: every tile «–», no
 * worker, an EMPTY console, so it reads as broken rather than blocked. Served
 * from {@see \Semitexa\Dev\Application\Handler\PayloadHandler\ObservatoryAssetHandler}
 * they are covered by `'self'` and the page carries no inline anything — no
 * script, no style block, and no `style=` attribute either, since a strict
 * policy blocks those too.
 *
 * Transport is the journal followed as a delta stream (`/__observatory/feed
 * ?stream=1&after=<cursor>`) polled at 250 ms while anything moves, 1 s when
 * idle, not at all while the tab is hidden. A KISS/SSE push is NOT the
 * upgrade path: SSE lives in semitexa/ssr, and dev must not depend on it. At
 * that cadence the eye cannot tell the difference, and the file stays the
 * one honest cross-worker medium.
 */
#[AsService]
final class ObservatoryHtmlRenderer
{
    public function render(): string
    {
        $notice = $this->missingAssetNotice();

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Observatory · Semitexa</title>
<link rel="stylesheet" href="/__observatory/asset/observatory.css">
</head>
<body>
{$notice}
<div class="obs">
  <header class="head panel">
    <div class="brand"><span class="dot" id="led"></span><h1>Semitexa <span>Observatory</span></h1><span class="tr" id="transport" title="transport">…</span></div>
    <div class="tiles">
      <div class="tile" id="t-rps"><b>–</b><span>requests</span></div>
      <div class="tile" id="t-flight"><b>–</b><span>in flight</span></div>
      <div class="tile" id="t-workers"><b>–</b><span>workers</span></div>
      <div class="tile" id="t-p50"><b>–</b><span>p50 · 60s</span></div>
      <div class="tile" id="t-p95"><b>–</b><span>p95 · 60s</span></div>
      <div class="tile" id="t-q"><b>–</b><span>queries</span></div>
      <div class="tile" id="t-err"><b>–</b><span>failed · 60s</span></div>
      <div class="tile" id="t-hung"><b>–</b><span>hung coroutines</span></div>
    </div>
    <div class="controls">
      <button class="btn stage" id="b-stage" type="button"><i class="led"></i>stage <kbd>S</kbd></button>
      <button class="btn demo" id="b-demo" type="button"><i class="led"></i>demo load <kbd>D</kbd></button>
      <span class="seg" id="speed" title="slow motion"><button type="button" class="on" data-v="1">1×</button><button type="button" data-v="0.5">½×</button><button type="button" data-v="0.25">¼×</button></span>
      <button class="btn" id="b-fit" type="button" title="fit the whole picture">fit <kbd>0</kbd></button>
      <button class="btn" id="b-explain" type="button">explain <kbd>E</kbd></button>
      <button class="btn" id="b-pause" type="button"><span>pause</span> <kbd>␣</kbd></button>
      <button class="btn" id="b-full" type="button">⛶ <kbd>F</kbd></button>
      <a class="link" href="/__trace">history →</a>
    </div>
  </header>

  <section class="workers panel">
    <nav class="tabs">
      <button type="button" class="on" data-tab="workers">Workers <b id="tb-workers" hidden></b></button>
      <button type="button" data-tab="coro">Coroutines <b id="tb-coro" hidden></b></button>
      <button type="button" data-tab="cron">Cron <b id="tb-cron" hidden></b></button>
      <button type="button" data-tab="system">System <b id="tb-system" hidden></b></button>
    </nav>
    <div class="wscroll">
      <div class="pane" id="pane-workers">
        <h2>Swoole workers <span class="sub">pid · in flight · 60 s</span></h2>
        <div class="wlist" id="wlist"><div class="empty">No worker has spoken yet.</div></div>
      </div>
      <div class="pane" id="pane-coro" hidden>
        <h2>Coroutines <span class="sub">per worker snapshot</span></h2>
        <div class="bg" id="coro"></div>
      </div>
      <div class="pane" id="pane-cron" hidden>
        <h2>Cron <span class="sub">#[AsScheduledJob] · next run</span></h2>
        <div class="bg cron" id="cron"><div class="empty">Loading schedules…</div></div>
      </div>
      <div class="pane" id="pane-system" hidden>
        <h2>Resident &amp; background <span class="sub">sse · jobs · failures</span></h2>
        <div class="bg" id="bg"></div>
      </div>
    </div>
  </section>

  <section class="river panel" id="river">
    <canvas id="river-canvas"></canvas>
    <div class="legend"><span><i class="k-http"></i>http</span><span><i class="k-sse"></i>sse</span><span><i class="k-scheduler"></i>scheduler</span><span><i class="k-queue"></i>queue</span><span><i class="k-replay"></i>replay</span></div>
    <div class="zoomctl">
      <button type="button" id="z-in" title="zoom in (+)">+</button>
      <button type="button" id="z-out" title="zoom out (−)">−</button>
      <button type="button" id="z-fit" title="fit (0)">⌖</button>
      <span class="pan"><button type="button" data-x="1" data-y="0" title="pan left">←</button><button type="button" data-x="0" data-y="1" title="pan up">↑</button><button type="button" data-x="0" data-y="-1" title="pan down">↓</button><button type="button" data-x="-1" data-y="0" title="pan right">→</button></span>
    </div>
    <div class="caption">drag · wheel · pinch · arrows · dbl-click fits · hover a node, click to pin</div>
    <div class="tip" id="tip"></div>
  </section>

  <section class="ticker panel">
    <h2>Just finished <span class="sub">newest first · click → waterfall</span></h2>
    <div class="tlist" id="tlist"><div class="empty">Waiting for the first end line…</div></div>
  </section>

  <section class="time panel" id="time">
    <h2>Last 60 seconds <span class="sub">duration · log scale · bars = finished per second</span></h2>
    <canvas id="tl-canvas"></canvas>
  </section>
</div>
<script src="/__observatory/asset/observatory.js"></script>
</body>
</html>
HTML;
    }

    /**
     * One inlined asset. A missing file renders a visible notice instead of a
     * blank page: the operator is looking at this to debug, and a debugger
     * that fails silently is the one thing it must not be.
     */
    /**
     * A missing asset must SAY so.
     *
     * The panel has no other voice: a stylesheet that 404s leaves an unstyled
     * page, and a script that 404s leaves the shell frozen at «–» — which is
     * the same picture as being blocked by a policy, and the reason that bug
     * took a consumer report to find. So the asset route answers 404 and this
     * page carries a line that names what is missing, visible without the
     * stylesheet because it brings its own.
     */
    private function missingAssetNotice(): string
    {
        $missing = [];
        foreach (['observatory.css', 'observatory.js'] as $name) {
            if (!is_file(self::assetDir() . '/' . $name)) {
                $missing[] = $name;
            }
        }

        if ($missing === []) {
            return '';
        }

        return '<p role="alert" class="asset-missing">Observatory asset missing: '
            . htmlspecialchars(implode(', ', $missing), ENT_QUOTES)
            . ' — expected under ' . htmlspecialchars(self::assetDir(), ENT_QUOTES) . '</p>';
    }

    public static function assetDir(): string
    {
        // src/Application/Service/Trace → package root is four levels up.
        return dirname(__DIR__, 4) . '/resources/observatory';
    }
}

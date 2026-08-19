<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;

/**
 * The live process panel at `/__observatory`.
 *
 * Self-contained HTML from PHP — same discipline as the trace viewer: dev must
 * not depend on ssr, so no Twig, no asset pipeline, no external fonts. The page
 * polls `/__observatory/feed` once a second; polling a journal file is the
 * honest transport here (the file is the cross-worker state), and a KISS/SSE
 * push is a later upgrade on top, never a replacement.
 */
#[AsService]
final class ObservatoryHtmlRenderer
{
    public function render(): string
    {
        $css = $this->css();
        $js = $this->js();

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Observatory · Semitexa</title>
<style>{$css}</style>
</head>
<body>
<header>
  <div>
    <h1>Observatory <span class="dim">live processes</span></h1>
    <div class="meta" id="meta">connecting…</div>
  </div>
  <nav>
    <span class="stat" id="stat-live" title="processes running right now">live <b>–</b></span>
    <span class="stat" id="stat-workers" title="workers with live processes">workers <b>–</b></span>
    <a class="stat link" href="/__trace">history →</a>
    <button class="stat link" id="pause" type="button">pause</button>
  </nav>
</header>

<h2>Live <span class="dim" id="live-note"></span></h2>
<div class="list" id="live"><div class="empty">Nothing in flight.</div></div>

<h2>Just finished</h2>
<div class="list" id="recent"><div class="empty">Nothing yet.</div></div>

<script>{$js}</script>
</body>
</html>
HTML;
    }

    private function css(): string
    {
        return <<<'CSS'
:root{
  --bg:#0b0e14;--panel:#111621;--line:#1e2532;--text:#e6e9ef;--dim:#8792a6;
  --accent:#5b9dff;--warn:#ffb454;--danger:#ff6b6b;
  --mono:ui-monospace,SFMono-Regular,"SF Mono",Menlo,monospace;
}
@media (prefers-color-scheme: light){
  :root{--bg:#f7f8fa;--panel:#fff;--line:#e4e7ec;--text:#141821;--dim:#667085}
}
body{margin:0;background:var(--bg);color:var(--text);
  font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;padding:28px clamp(16px,6vw,90px) 60px}
header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
h1{font-size:20px;margin:0}
h2{font-size:12px;text-transform:uppercase;letter-spacing:.09em;color:var(--dim);margin:34px 0 12px}
.dim{color:var(--dim);font-weight:400}
.meta{color:var(--dim);font-size:13px;margin-top:2px}
nav{display:flex;gap:8px;align-items:center}
.stat{font-family:var(--mono);font-size:12px;color:var(--dim);border:1px solid var(--line);
  border-radius:6px;padding:5px 10px;background:var(--panel)}
.stat b{color:var(--text);font-weight:600}
.stat.link{color:var(--accent);text-decoration:none;cursor:pointer;font:inherit;font-family:var(--mono);font-size:12px}
.list{border:1px solid var(--line);border-radius:10px;overflow:hidden;background:var(--panel)}
.row{display:grid;grid-template-columns:64px minmax(180px,1fr) 90px 110px 90px;gap:14px;align-items:center;
  padding:10px 16px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit}
.row:last-child{border-bottom:none}
a.row:hover{background:color-mix(in srgb,var(--accent) 8%,transparent)}
.kind{font-family:var(--mono);font-size:10.5px;font-weight:700;text-align:center;
  border:1px solid var(--line);border-radius:4px;padding:2px 0;color:var(--accent)}
.kind.sse{color:var(--warn)}
.name{font-family:var(--mono);font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.num{font-family:var(--mono);font-size:12.5px;text-align:right;font-variant-numeric:tabular-nums;color:var(--dim)}
.num.hot{color:var(--text)}
.age{font-family:var(--mono);font-size:12.5px;text-align:right;font-variant-numeric:tabular-nums}
.age.stale{color:var(--danger)}
.pulse{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--accent);
  margin-right:8px;animation:pulse 1.6s ease-in-out infinite}
.pulse.stale{background:var(--danger);animation:none;opacity:.7}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.25}}
.empty{padding:18px;color:var(--dim);font-size:13px}
.tracelink{font-family:var(--mono);font-size:11.5px;color:var(--accent);text-align:right}
CSS;
    }

    private function js(): string
    {
        return <<<'JS'
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fmtAge = (s) => s < 60 ? s + 's' : s < 3600 ? Math.floor(s/60) + 'm ' + (s%60) + 's' : Math.floor(s/3600) + 'h ' + Math.floor((s%3600)/60) + 'm';
const fmtMs = (v) => v == null ? '—' : v >= 1000 ? (v/1000).toFixed(1) + ' s' : v.toFixed(1) + ' ms';
let paused = false, timer = null;

document.getElementById('pause').addEventListener('click', function () {
  paused = !paused;
  this.textContent = paused ? 'resume' : 'pause';
  if (!paused) tick();
});

function liveRow(p) {
  return '<div class="row">'
    + '<span class="kind ' + esc(p.kind) + '">' + esc(p.kind) + '</span>'
    + '<span class="name"><span class="pulse' + (p.stale ? ' stale' : '') + '"></span>' + esc(p.name) + '</span>'
    + '<span class="num">w' + esc(p.worker) + '</span>'
    + '<span class="age' + (p.stale ? ' stale' : '') + '">' + fmtAge(p.ageS) + (p.stale ? ' · stale' : '') + '</span>'
    + '<span class="num"></span>'
    + '</div>';
}

function recentRow(p) {
  const inner = '<span class="kind ' + esc(p.kind) + '">' + esc(p.kind) + '</span>'
    + '<span class="name">' + esc(p.name) + '</span>'
    + '<span class="num">w' + esc(p.worker) + '</span>'
    + '<span class="num hot">' + fmtMs(p.durationMs) + '</span>'
    + '<span class="tracelink">' + (p.trace ? 'waterfall →' : '') + '</span>';
  return p.trace
    ? '<a class="row" href="/__trace?file=' + encodeURIComponent(p.trace) + '">' + inner + '</a>'
    : '<div class="row">' + inner + '</div>';
}

async function tick() {
  if (paused) return;
  try {
    const r = await fetch('/__observatory/feed', {headers: {'Accept': 'application/json'}});
    const d = await r.json();
    document.getElementById('stat-live').innerHTML = 'live <b>' + d.counts.live + '</b>'
      + (d.counts.stale ? ' <span class="age stale">(' + d.counts.stale + ' stale)</span>' : '');
    document.getElementById('stat-workers').innerHTML = 'workers <b>' + d.counts.workers + '</b>';
    document.getElementById('meta').textContent = 'updated ' + new Date(d.generatedAt).toLocaleTimeString()
      + (d.truncated ? ' · journal tail truncated' : '');
    document.getElementById('live-note').textContent =
      Object.entries(d.counts.byKind).map(([k, n]) => n + ' ' + k).join(' · ');
    document.getElementById('live').innerHTML =
      d.live.length ? d.live.map(liveRow).join('') : '<div class="empty">Nothing in flight.</div>';
    document.getElementById('recent').innerHTML =
      d.recent.length ? d.recent.map(recentRow).join('') : '<div class="empty">Nothing yet.</div>';
  } catch (e) {
    document.getElementById('meta').textContent = 'feed unreachable — retrying…';
  }
}

tick();
timer = setInterval(tick, 1000);
JS;
    }
}

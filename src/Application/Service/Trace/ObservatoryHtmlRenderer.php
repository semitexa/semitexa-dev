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

/* Duration as GEOMETRY, not digits. The bar sits behind the row so the eye reads
   magnitude before it reads anything else - the list spans 0.076 ms to 23915 ms and
   a right-aligned number makes those look equally heavy.
   LOG scale on purpose: linear would render everything except the slowest row as an
   invisible sliver, which is the same "one bar at 98%" problem the waterfall has. */
.row{position:relative}
.bar{position:absolute;left:0;top:0;bottom:0;width:0;
  background:linear-gradient(90deg,color-mix(in srgb,var(--accent) 22%,transparent),transparent);
  pointer-events:none;transition:width .25s ease}
.bar.hot{background:linear-gradient(90deg,color-mix(in srgb,var(--warn) 26%,transparent),transparent)}
.bar.slow{background:linear-gradient(90deg,color-mix(in srgb,var(--danger) 26%,transparent),transparent)}
/* :not(.bar) matters - a bare .row>* rule outranks .bar's position:absolute on
   specificity, which drops the bar back into the grid as a real column and shifts
   every cell right. It must stay out of flow. */
.row>*:not(.bar){position:relative}

/* Repetition collapsed. Ten identical FundsTickJob lines say nothing ten times; one
   line with a count and the shape of those durations says it once and adds the trend. */
.count{font-family:var(--mono);font-size:11px;color:var(--bg);background:var(--accent);
  border-radius:999px;padding:1px 7px;margin-left:8px;font-weight:700}
.spark{display:inline-flex;align-items:flex-end;gap:2px;height:14px;margin-left:10px;vertical-align:-2px}
.spark i{width:3px;background:var(--dim);border-radius:1px;min-height:2px;display:block}
.group{cursor:pointer}
.group:hover{background:color-mix(in srgb,var(--accent) 8%,transparent)}
.caret{display:inline-block;width:10px;color:var(--dim);transition:transform .15s ease}
.group.open .caret{transform:rotate(90deg)}
.child{padding-left:34px;background:color-mix(in srgb,var(--line) 30%,transparent)}

/* Stale processes are hours-dead and were crowding out everything live. Demoted to a
   fold rather than hidden - they still matter when nothing else explains a wedged worker. */
.fold{padding:9px 16px;color:var(--dim);font-size:12.5px;font-family:var(--mono);
  cursor:pointer;border-bottom:1px solid var(--line)}
.fold:hover{color:var(--text)}
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

// Magnitude on a LOG scale. The list routinely spans five orders of magnitude
// (0.076 ms next to 23915 ms); linear would leave every row but the slowest at zero
// width, which is exactly the "one bar at 98%" failure the deep view already has.
function barWidth(ms, max) {
  if (!ms || !max || max <= 0) return 0;
  return Math.max(2, Math.round(100 * Math.log10(1 + ms) / Math.log10(1 + max)));
}
function barClass(ms) {
  return ms >= 1000 ? ' slow' : ms >= 100 ? ' hot' : '';
}

// Consecutive only, never global: collapsing across the whole list would reorder time,
// and "these ten ran back to back" is itself the information.
function groupRuns(rows) {
  const out = [];
  for (const p of rows) {
    const last = out[out.length - 1];
    if (last && last.kind === p.kind && last.name === p.name) { last.items.push(p); continue; }
    out.push({kind: p.kind, name: p.name, items: [p]});
  }
  return out;
}

function sparkline(items) {
  const vals = items.map(p => p.durationMs || 0);
  const max = Math.max.apply(null, vals.concat([1]));
  return '<span class="spark" title="' + vals.map(v => fmtMs(v)).join(' · ') + '">'
    + vals.map(v => '<i style="height:' + Math.max(2, Math.round(14 * v / max)) + 'px"></i>').join('')
    + '</span>';
}

function liveRow(p) {
  return '<div class="row">'
    + '<span class="kind ' + esc(p.kind) + '">' + esc(p.kind) + '</span>'
    + '<span class="name"><span class="pulse' + (p.stale ? ' stale' : '') + '"></span>' + esc(p.name) + '</span>'
    + '<span class="num">w' + esc(p.worker) + '</span>'
    + '<span class="age' + (p.stale ? ' stale' : '') + '">' + fmtAge(p.ageS) + (p.stale ? ' · stale' : '') + '</span>'
    + '<span class="num"></span>'
    + '</div>';
}

function recentRow(p, max, extraClass) {
  const w = barWidth(p.durationMs, max);
  const inner = '<span class="bar' + barClass(p.durationMs) + '" style="width:' + w + '%"></span>'
    + '<span class="kind ' + esc(p.kind) + '">' + esc(p.kind) + '</span>'
    + '<span class="name">' + esc(p.name) + '</span>'
    + '<span class="num">w' + esc(p.worker) + '</span>'
    + '<span class="num hot">' + fmtMs(p.durationMs) + '</span>'
    + '<span class="tracelink">' + (p.trace ? 'waterfall →' : '') + '</span>';
  const cls = 'row' + (extraClass ? ' ' + extraClass : '');
  return p.trace
    ? '<a class="' + cls + '" href="/__trace?file=' + encodeURIComponent(p.trace) + '">' + inner + '</a>'
    : '<div class="' + cls + '">' + inner + '</div>';
}

// A run of identical processes becomes ONE row carrying the count, the total, and the
// shape of the individual durations. Click opens the run in place rather than navigating.
function groupRow(g, max, idx) {
  if (g.items.length === 1) return recentRow(g.items[0], max);
  const total = g.items.reduce((a, p) => a + (p.durationMs || 0), 0);
  const head = '<div class="row group" data-group="' + idx + '">'
    + '<span class="bar' + barClass(total) + '" style="width:' + barWidth(total, max) + '%"></span>'
    + '<span class="kind ' + esc(g.kind) + '">' + esc(g.kind) + '</span>'
    + '<span class="name"><span class="caret">▶</span> ' + esc(g.name)
    + '<span class="count">×' + g.items.length + '</span>' + sparkline(g.items) + '</span>'
    + '<span class="num"></span>'
    + '<span class="num hot">' + fmtMs(total) + '</span>'
    + '<span class="tracelink">total</span>'
    + '</div>';
  const kids = '<div class="kids" data-kids="' + idx + '" hidden>'
    + g.items.map(p => recentRow(p, max, 'child')).join('') + '</div>';
  return head + kids;
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
    // Stale entries are hours-dead and were filling the LIVE panel, which is the one
    // place that must answer "what is happening RIGHT NOW". Folded, not dropped - a
    // wedged worker is sometimes the only thing that explains a stuck request.
    const fresh = d.live.filter(p => !p.stale);
    const stale = d.live.filter(p => p.stale);
    let liveHtml = fresh.length ? fresh.map(liveRow).join('') : '';
    if (stale.length) {
      liveHtml += '<div class="fold" id="stale-fold">▶ ' + stale.length
        + ' stale · oldest ' + fmtAge(Math.max.apply(null, stale.map(p => p.ageS))) + '</div>'
        + '<div id="stale-list" hidden>' + stale.map(liveRow).join('') + '</div>';
    }
    document.getElementById('live').innerHTML =
      liveHtml || '<div class="empty">Nothing in flight.</div>';

    const max = Math.max.apply(null, d.recent.map(p => p.durationMs || 0).concat([1]));
    document.getElementById('recent').innerHTML = d.recent.length
      ? groupRuns(d.recent).map((g, i) => groupRow(g, max, i)).join('')
      : '<div class="empty">Nothing yet.</div>';
    restoreOpenGroups();
  } catch (e) {
    document.getElementById('meta').textContent = 'feed unreachable — retrying…';
  }
}

// Delegated because the lists are re-rendered wholesale every second; binding per row
// would leak listeners and lose the open state on the next tick.
document.addEventListener('click', (e) => {
  const fold = e.target.closest('#stale-fold');
  if (fold) {
    const list = document.getElementById('stale-list');
    if (list) { list.hidden = !list.hidden; fold.textContent = (list.hidden ? '▶ ' : '▼ ') + fold.textContent.slice(2); }
    return;
  }
  const g = e.target.closest('.group');
  if (!g) return;
  const kids = document.querySelector('[data-kids="' + g.dataset.group + '"]');
  if (!kids) return;
  kids.hidden = !kids.hidden;
  g.classList.toggle('open', !kids.hidden);
  // A run the reader opened must survive the next poll, or the panel fights them.
  openGroups[g.querySelector('.name').textContent.trim()] = !kids.hidden;
});

// Which runs the reader has opened, keyed by name so it survives re-render.
const openGroups = {};

function restoreOpenGroups() {
  document.querySelectorAll('.group').forEach(g => {
    const key = g.querySelector('.name').textContent.trim();
    if (!openGroups[key]) return;
    const kids = document.querySelector('[data-kids="' + g.dataset.group + '"]');
    if (kids) { kids.hidden = false; g.classList.add('open'); }
  });
}

tick();
timer = setInterval(tick, 1000);
JS;
    }
}

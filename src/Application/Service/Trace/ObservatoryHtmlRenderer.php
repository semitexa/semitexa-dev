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
 * honest transport here (the file is the cross-worker state). A KISS/SSE push
 * is NOT the upgrade path: SSE lives in semitexa/ssr, and dev must not depend on
 * it. The poll is adaptive instead - 1s while anything is live, 4s when idle,
 * off while the tab is hidden - see schedule() in the script.
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
.fold:focus-visible,.group:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}

/* Motion is only possible because rows now PERSIST between polls. The panel used to
   replace innerHTML wholesale every second, which destroyed and recreated every node -
   no transition can survive that, which is why the page felt static even though it was
   updating constantly. The transport was never the problem. */
@keyframes enter{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.row.enter{animation:enter .28s cubic-bezier(.2,.8,.2,1)}
.row.leaving{opacity:0;transform:translateY(4px);transition:opacity .2s ease,transform .2s ease}
/* A row that just landed keeps a brief edge so the eye catches WHICH one is new. */
.row.enter::after{content:'';position:absolute;left:0;top:0;bottom:0;width:2px;
  background:var(--accent);animation:fadeEdge 1.1s ease forwards}
@keyframes fadeEdge{to{opacity:0}}
@media (prefers-reduced-motion:reduce){
  .row.enter,.row.leaving{animation:none;transition:none;transform:none;opacity:1}
  .row.enter::after{display:none}
}
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
  if (!paused) tick().then(schedule);
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
  if (g.items.length === 1) return {head: recentRow(g.items[0], max), kids: null};
  const total = g.items.reduce((a, p) => a + (p.durationMs || 0), 0);
  const head = '<div class="row group" data-group="' + idx + '" role="button" tabindex="0" aria-expanded="false">'
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
  return {head: head, kids: kids};
}

async function tick() {
  if (paused) return;
  try {
    const r = await fetch('/__observatory/feed', {headers: {'Accept': 'application/json'}});
    const d = await r.json();
    liveCount = d.counts.live;
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
    lastSnapshotAt = Date.now();
    liveAges = {};
    d.live.forEach(p => { liveAges[p.id] = p.ageS; });
    const liveBox = document.getElementById('live');
    if (!fresh.length && !stale.length) {
      liveBox.innerHTML = '<div class="empty">Nothing in flight.</div>';
      delete liveBox.dataset.primed;
    } else {
      const entries = fresh.map(p => ({
        key: p.id,
        // The signature decides whether a row is rewritten at all. Age is excluded on
        // purpose - it changes every second and would rebuild every live row forever,
        // which is the churn this reconciler exists to stop. A ticker updates it instead.
        sig: p.kind + '|' + p.name + '|' + p.worker + '|' + (p.stale ? 's' : ''),
        html: liveRow(p),
      }));
      reconcile(liveBox, entries);
      renderStaleFold(liveBox, stale);
    }

    const max = Math.max.apply(null, d.recent.map(p => p.durationMs || 0).concat([1]));
    const recentBox = document.getElementById('recent');
    if (!d.recent.length) {
      recentBox.innerHTML = '<div class="empty">Nothing yet.</div>';
      delete recentBox.dataset.primed;
    } else {
      const entries = groupRuns(d.recent).map((g, i) => {
        const parts = groupRow(g, max, i);
        return {
          // Keyed on the OLDEST member so a run keeps its identity as it grows: the feed
          // is newest-first, so items[0] changes on every tick and would read as a new
          // row arriving each second - the exact flicker keyed rendering exists to stop.
          key: g.items[g.items.length - 1].id,
          sig: g.kind + '|' + g.name + '|' + g.items.length + '|' + Math.round(max),
          html: parts.head,
          after: parts.kids,
        };
      });
      reconcile(recentBox, entries);
    }
  } catch (e) {
    document.getElementById('meta').textContent = 'feed unreachable — retrying…';
    // A failed fetch is not "idle": retry at the fast cadence so a server
    // restart shows up within a second, not four.
    liveCount = 1;
  }
}

// Keyed reconciliation. The panel used to assign innerHTML on every poll, which threw
// away and rebuilt every node once a second: no CSS transition could survive it, the
// open state of a group had to be restored by hand afterwards, and the page looked
// static precisely BECAUSE it was being recreated so often. Rows now persist across
// ticks and only their contents change, which is what makes motion possible at all.
function reconcile(container, entries) {
  // The empty placeholder carries no data-key, so the removal pass below would never
  // collect it and it would sit above the first real row forever. Clear it on the way in.
  const placeholder = container.querySelector(':scope > .empty');
  if (placeholder) placeholder.remove();

  const seen = new Set();
  const changed = new Set();
  let anchor = null;
  for (const e of entries) {
    seen.add(e.key);
    let node = container.querySelector('[data-key="' + CSS.escape(e.key) + '"]');
    if (!node) {
      const holder = document.createElement('div');
      holder.innerHTML = e.html;
      node = holder.firstElementChild;
      if (!node) continue;
      node.dataset.key = e.key;
      node.classList.add('enter');
      // Only the first paint should be quiet - otherwise the initial load animates
      // twenty rows at once and reads as noise rather than as arrival.
      if (!container.dataset.primed) node.classList.remove('enter');
    } else if (node.dataset.sig !== e.sig) {
      const wasOpen = node.classList.contains('open');
      const holder = document.createElement('div');
      holder.innerHTML = e.html;
      const fresh = holder.firstElementChild;
      if (fresh && fresh.tagName !== node.tagName) {
        // A single (<a href>) that became a run (<div class="row group">) or back:
        // patching innerHTML would leave a link with no href, or a group that
        // navigates. Swap the element and keep its place.
        fresh.dataset.key = e.key;
        node.replaceWith(fresh);
        node = fresh;
      } else if (fresh) {
        node.innerHTML = fresh.innerHTML;
        node.className = fresh.className;
        for (const attr of ['href', 'data-group']) {
          if (fresh.hasAttribute(attr)) node.setAttribute(attr, fresh.getAttribute(attr));
          else node.removeAttribute(attr);
        }
      }
      node.dataset.key = e.key;
      if (wasOpen) { node.classList.add('open'); node.setAttribute('aria-expanded', 'true'); }
      changed.add(e.key);
    }
    node.dataset.sig = e.sig;
    // insertBefore with an existing node MOVES it, so order follows the feed without
    // rebuilding anything.
    container.insertBefore(node, anchor ? anchor.nextSibling : container.firstChild);
    anchor = node;
    if (e.after) {
      const kids = e.after;
      let kn = container.querySelector('[data-key="' + CSS.escape(e.key) + '::kids"]');
      if (!kn) {
        const h = document.createElement('div');
        h.innerHTML = kids;
        kn = h.firstElementChild;
        if (kn) kn.dataset.key = e.key + '::kids';
      } else if (changed.has(e.key)) {
        // The run grew: its member list is stale until it is re-rendered too.
        const h = document.createElement('div');
        h.innerHTML = kids;
        if (h.firstElementChild) kn.innerHTML = h.firstElementChild.innerHTML;
      }
      if (kn) {
        seen.add(e.key + '::kids');
        kn.hidden = !node.classList.contains('open');
        container.insertBefore(kn, anchor.nextSibling);
        anchor = kn;
      }
    }
  }
  container.dataset.primed = '1';
  Array.from(container.children).forEach(n => {
    if (!n.dataset.key || seen.has(n.dataset.key)) return;
    n.classList.add('leaving');
    setTimeout(() => n.remove(), 200);
  });
}

// Delegated because rows are reconciled rather than owned by a listener each.
// The fold and the group heads are divs acting as buttons, so they carry
// role/tabindex/aria-expanded and answer Enter and Space the way a button would;
// a keyboard user must be able to reach the trace links a closed run hides.
function toggleFold(fold) {
  const list = document.getElementById('stale-list');
  if (!list) return;
  list.hidden = !list.hidden;
  fold.textContent = (list.hidden ? '▶ ' : '▼ ') + fold.textContent.slice(2);
  fold.setAttribute('aria-expanded', String(!list.hidden));
}
function toggleGroup(g) {
  const kids = g.nextElementSibling && g.nextElementSibling.classList.contains('kids')
    ? g.nextElementSibling : null;
  if (!kids) return;
  kids.hidden = !kids.hidden;
  g.classList.toggle('open', !kids.hidden);
  g.setAttribute('aria-expanded', String(!kids.hidden));
  // No bookkeeping needed any more: the row and its children are the same DOM nodes
  // next tick, so the open state simply stays. That was the workaround the wholesale
  // innerHTML rewrite forced, and reconciliation removed the need for it.
}
function activate(e) {
  const fold = e.target.closest('#stale-fold');
  if (fold) { toggleFold(fold); return true; }
  const g = e.target.closest('.group');
  if (g) { toggleGroup(g); return true; }
  return false;
}
document.addEventListener('click', activate);
document.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter' && e.key !== ' ') return;
  if (!e.target.matches || !e.target.matches('.group, #stale-fold')) return;
  if (activate(e)) e.preventDefault();
});

// The stale fold is appended after the live rows and owned outside the reconciler,
// which keys on process ids it has no business inventing one for.
function renderStaleFold(box, stale) {
  const existing = box.querySelector('.fold');
  const list = box.querySelector('#stale-list');
  if (!stale.length) { if (existing) existing.remove(); if (list) list.remove(); return; }
  const oldest = fmtAge(Math.max.apply(null, stale.map(p => p.ageS)));
  const open = list ? !list.hidden : false;
  const label = (open ? '▼ ' : '▶ ') + stale.length + ' stale · oldest ' + oldest;
  if (existing) { existing.textContent = label; existing.setAttribute('aria-expanded', String(open)); } else {
    const f = document.createElement('div');
    f.className = 'fold'; f.id = 'stale-fold'; f.textContent = label;
    f.setAttribute('role', 'button'); f.tabIndex = 0; f.setAttribute('aria-expanded', String(open));
    box.appendChild(f);
  }
  const holder = list || document.createElement('div');
  holder.id = 'stale-list';
  holder.hidden = !open;
  holder.innerHTML = stale.map(liveRow).join('');
  if (!list) box.appendChild(holder);
}

// Live ages advance every 200ms from the last snapshot rather than jumping once a
// second. Continuous motion is most of what separates "live" from "refreshing", and it
// costs one interval instead of a transport rewrite.
let lastSnapshotAt = 0, liveAges = {};
function tickAges() {
  if (paused || !lastSnapshotAt) return;
  const drift = Math.floor((Date.now() - lastSnapshotAt) / 1000);
  document.querySelectorAll('#live .row[data-key]').forEach(row => {
    const base = liveAges[row.dataset.key];
    if (base === undefined) return;
    const el = row.querySelector('.age');
    if (!el) return;
    const stale = el.classList.contains('stale');
    el.textContent = fmtAge(base + drift) + (stale ? ' · stale' : '');
  });
}
setInterval(tickAges, 200);

// Adaptive cadence rather than a fixed second. A push transport is not on the
// table (dev must not depend on ssr, which owns SSE), so the poll is made honest
// instead: every second while something is in flight, backing off to 4s when
// nothing is - and not at all while the tab is hidden, where a poll would only
// journal itself. Coming back refreshes at once.
let liveCount = 0;
function schedule() {
  clearTimeout(timer);
  if (document.hidden) return;
  timer = setTimeout(async () => { await tick(); schedule(); }, liveCount > 0 ? 1000 : 4000);
}
document.addEventListener('visibilitychange', () => {
  if (document.hidden) { clearTimeout(timer); return; }
  tick().then(schedule);
});
tick().then(schedule);
JS;
    }
}

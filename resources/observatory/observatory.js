/* Observatory live panel.
 *
 * One journal row in, one moving thing on screen out. Every HTTP request, SSE
 * session, scheduler run and queue job the framework runs is announced in the
 * journal (begin/end); this page follows the journal as a delta stream and
 * animates each process through the REAL pipeline stages — dwelling at each
 * node for the share of time that stage actually took whenever the end line
 * carries a phase summary (stage mode, or a ?__trace=1 request).
 *
 * The picture is a WORLD in its own coordinates, drawn through a view
 * transform: it pans, zooms and scrolls, so nothing has to fit a laptop
 * screen to stay readable on a projector.
 *
 * No framework, no build step: a canvas for the river and the timeline, DOM
 * for the panels, one poll loop. */
(() => {
'use strict';

/* ------------------------------------------------------------ vocabulary */
const STAGES = [
  {key:'client',    label:'CLIENT',    title:'Client',            desc:'A browser, a crawler, a program. Every particle is one real connection the Swoole server accepted; it comes in from the icon that matches its User-Agent and goes back to it with the response.'},
  {key:'router',    label:'ROUTER',    title:'Route match',       desc:'The path is matched to a Payload class declared with #[AsPayload] / #[AsPublicPayload]. No controllers: the payload IS the route.'},
  {key:'gate',      label:'GATE',      phase:'gate',      title:'Pre-hydration auth gate', desc:'Access model checked BEFORE the body is read. A stranger is refused without the framework ever parsing their input.'},
  {key:'hydrate',   label:'HYDRATE',   phase:'hydrate',   title:'Hydrate + validate', desc:'PayloadHydrator fills the typed payload from query, body and path params; validation rejects here and the request short-circuits.'},
  {key:'resource',  label:'RESOURCE',  phase:'resolve',   title:'Resource resolve',  desc:'The response DTO is chosen from the Accept header — the same route can answer HTML, JSON or a stream.'},
  {key:'auth',      label:'AUTH',      phase:'auth',      title:'AuthCheck phase',   desc:'Pipeline phase where authorization, CSRF, theme and tenant listeners run. Each listener is its own span.'},
  {key:'listeners', label:'LISTENERS', phase:'listeners', title:'Pipeline listeners', desc:'#[AsPipelineListener] classes hooked on the request phases — middleware without a middleware stack. SSE sessions leave the river here and stay resident in LIVE.'},
  {key:'handler',   label:'HANDLER',   phase:'handler',   title:'Payload handler',   desc:'Your code: the #[AsPayloadHandler] for this payload. Queries to the ORM fan out from here.'},
  {key:'events',    label:'EVENTS',    phase:'completed', title:'Handler completed', desc:'Domain events are dispatched; listeners that opted in are QUEUED instead of run inline — the impulse you see leaving for QUEUE is exactly that hand-off.'},
  {key:'render',    label:'RENDER',    phase:'render',    title:'Response render',   desc:'ResponseRenderer turns the resource DTO into bytes: Twig via SSR, JSON, or a redirect.'},
  {key:'response',  label:'RESPONSE',  title:'Response',          desc:'Bytes back on the socket, and the particle travels back along the return lane to whoever asked. The worker coroutine ends; the journal gets its end line.'},
];
const IDX = Object.fromEntries(STAGES.map((s, i) => [s.key, i]));
const PHASE_KEYS = ['gate','hydrate','resolve','auth','listeners','handler','completed','render'];
const SIDE = {
  db:     {title:'ORM / database', desc:'Queries attributed to the request by the live QueryRecorder — count and total ms per request, each a spark from HANDLER.'},
  live:   {title:'Live connections', desc:'SSE / KISS sessions held open by the server. They pass the pipeline once, then stay resident here until the client leaves.'},
  cron:   {title:'Cron', desc:'Every #[AsScheduledJob] the project declares, with its expression. A tick here is the scheduler materialising a due run; the rows on the left count down to the next one.'},
  queue:  {title:'Queue', desc:'Messages consumed by queue:work — listeners and handlers that a request chose not to run inline. The impulse from EVENTS is the enqueue; the particle leaving here is the consumer picking it up.'},
  run:    {title:'Job run', desc:'A scheduler run or a queue message being executed. Journaled like a request, without the HTTP pipeline.'},
  done:   {title:'Done', desc:'Jobs that ended without an error.'},
  retry:  {title:'Waiting for retry', desc:'Runs that failed with attempts left under their schedule\'s maxAttempts. They orbit here until the scheduler starts the next attempt.'},
  failed: {title:'Failed', desc:'Jobs that failed for good. Hover shows the reason from the journal end line.'},
  human:  {title:'People', desc:'Browsers: Chrome, Firefox, Safari, Edge… classified from the User-Agent of each request.'},
  bot:    {title:'Bots', desc:'Crawlers and monitors: Googlebot, bingbot, uptime checkers, headless browsers.'},
  api:    {title:'Programs', desc:'curl, SDKs, other services, the demo-load button: anything that is not a person in a browser.'},
};
const KIND_COLOR = {http:'#5b9dff', sse:'#ffb454', scheduler:'#c084fc', job:'#c084fc', queue:'#34d399', replay:'#f472b6'};
const kindColor = k => KIND_COLOR[k] || '#9aa4bf';
const isJob = k => k === 'scheduler' || k === 'queue' || k === 'job';
const HUNG_MS = 5000;

/* ------------------------------------------------------------ state */
const S = {
  cursor: null, paused: false, speed: 1, stage: false, stageAvailable: true, explain: false,
  procs: new Map(), finished: [], particles: [], flashes: [], sparks: [], impulses: [],
  lastRowAt: 0, pollFail: 0,
  workers: new Map(), stats: {}, handlers: new Map(), recentPaths: [], demo: null, pinned: null, hover: null,
  clients: {human: 0, bot: 0, api: 0},
  schedules: [], scheduleByClass: new Map(), coroutines: [],
  failures: [], // {name, kind, error, at, attempt}
  view: {x: 0, y: 0, s: 1}, drag: null, pointers: new Map(), pinch: null,
};
const now = () => performance.now();
const $ = sel => document.querySelector(sel);

/* ------------------------------------------------------------ transport */
// SSE first: the panel holds one real connection and shows up in its own
// LIVE ring. Polling is the fallback — no Swoole pair to hold open (503),
// a proxy that will not stream, or a stream that keeps dying — and the
// cursor moves between the two, so nothing is missed either way.
const T = {mode: 'connecting', es: null, sseFailures: 0, retryAt: 0};
function applyBatch(d) {
  S.cursor = d.cursor; S.pollFail = 0;
  if (Array.isArray(d.coroutines)) { S.coroutines = d.coroutines; trackOccupancy(d.coroutines); }
  ingest(d.rows || [], !!d.reset, d.live || []);
  setLed('ok');
}
function openSse() {
  if (T.es || !('EventSource' in window) || document.hidden || S.paused) return false;
  if (T.sseFailures >= 3 && now() < T.retryAt) return false;
  const es = new EventSource('/__observatory/stream' + (S.cursor ? '?after=' + encodeURIComponent(S.cursor) : ''));
  T.es = es; T.mode = 'connecting'; let gotBytes = false;
  es.addEventListener('hello', () => { gotBytes = true; T.mode = 'sse'; T.sseFailures = 0; clearTimeout(pollTimer); setLed('ok'); });
  es.addEventListener('batch', e => { try { applyBatch(JSON.parse(e.data)); } catch (err) { /* a bad frame is dropped, the next one carries on */ } });
  es.addEventListener('close', () => { es.close(); T.es = null; T.mode = 'connecting'; openSse() || schedule(0); });
  es.onerror = () => {
    es.close(); T.es = null;
    T.sseFailures++; if (!gotBytes || T.sseFailures >= 3) { T.retryAt = now() + 30000; }
    T.mode = 'polling'; setLed('ok'); schedule(0);
  };
  return true;
}
async function poll() {
  if (document.hidden || S.paused) return schedule(600);
  if (T.mode === 'sse' && T.es) return; // the stream is delivering; nothing to poll
  if (openSse()) return schedule(4000); // give the stream a moment; if it fails, onerror re-arms polling
  const ctl = new AbortController();
  const t = setTimeout(() => ctl.abort(), 5000);
  try {
    const url = '/__observatory/feed?stream=1' + (S.cursor ? '&after=' + encodeURIComponent(S.cursor) : '');
    const r = await fetch(url, {headers: {Accept: 'application/json'}, cache: 'no-store', signal: ctl.signal});
    if (!r.ok) throw new Error('HTTP ' + r.status);
    applyBatch(await r.json());
  } catch (e) {
    S.pollFail++; setLed('off', String(e.message || e));
  } finally { clearTimeout(t); }
  const busy = now() - S.lastRowAt < 4000 || S.particles.some(p => p.state !== 'orbit');
  schedule(S.pollFail ? Math.min(5000, 500 * S.pollFail) : (busy ? 250 : 1000));
}
let pollTimer = 0;
function schedule(ms) { clearTimeout(pollTimer); pollTimer = setTimeout(poll, ms); }
document.addEventListener('visibilitychange', () => { if (document.hidden) { if (T.es) { T.es.close(); T.es = null; } T.mode = 'connecting'; } else schedule(0); });

async function loadSchedules() {
  try {
    const r = await fetch('/__observatory/schedules', {cache: 'no-store'}); const d = await r.json();
    S.schedules = (d.schedules || []).map(s => Object.assign({nextAt: null, lastRunAt: 0, runs: 0}, s));
    S.scheduleByClass = new Map(S.schedules.map(s => [s.jobClass, s]));
    renderCron(true);
  } catch (e) { /* keep last */ }
}

/* ------------------------------------------------------------ ingest */
function clientOf(ctx) { const c = ctx && ctx.client; return c === 'human' || c === 'bot' ? c : 'api'; }
// Jobs are journaled under their class FQCN; the picture wants the class name.
const shortName = (kind, name) => isJob(kind) && typeof name === 'string' && name.includes('\\') ? name.slice(name.lastIndexOf('\\') + 1) : name;
function ingest(rows, reset, live) {
  const t0 = now();
  if (reset) {
    S.procs.clear(); S.particles.length = 0;
    for (const p of live) {
      const rec = {id: p.id, kind: p.kind, name: p.name, worker: p.worker, at: t0 - (p.ageS || 0) * 1000, stale: !!p.stale, historic: true, client: 'api'};
      S.procs.set(p.id, rec); touchWorker(rec.worker, rec, 'open');
      if (p.kind === 'sse' && !p.stale) spawnParticle(rec, null, {resident: true});
    }
  }
  const ends = new Map();
  for (const r of rows) if (r.event === 'end') ends.set(r.id, r);
  const historic = reset;
  for (const r of rows) {
    const ts = Date.parse(r.ts) || Date.now();
    const at = historic ? t0 - Math.max(0, Date.now() - ts) : t0;
    if (r.event === 'begin') {
      const ctx = r.context || {};
      const rec = {id: r.id, kind: r.kind || 'http', name: shortName(r.kind, r.name || '?'), worker: r.worker, cid: r.cid, at, ts, historic,
        path: ctx.path, method: ctx.method, client: clientOf(ctx), agent: ctx.agent, attempt: ctx.attempt, route: ctx.route || r.name};
      // The bootstrap's `live` list may already hold this process with a
      // resident particle; a historic begin row must not orphan it, or the
      // end that follows spawns a second dot that never leaves.
      const known = S.procs.get(r.id);
      if (known && known.particle) { rec.particle = known.particle; rec.at = known.at; }
      S.procs.set(r.id, rec); touchWorker(rec.worker, rec, 'open');
      if (rec.kind === 'http' && !historic) S.clients[rec.client] = (S.clients[rec.client] || 0) + 1;
      if (rec.path && rec.kind === 'http' && (rec.method || 'GET') === 'GET' && !rec.path.startsWith('/__')) rememberPath(rec.path);
      if (rec.kind === 'scheduler') cronTick(rec, historic);
      if (!historic && !ends.get(r.id)) spawnParticle(rec, null, {});
      if (!historic && isJob(rec.kind)) resolveRetry(rec);
      continue;
    }
    if (r.event !== 'end') continue;
    const open = S.procs.get(r.id); S.procs.delete(r.id);
    const ctx = r.context || {};
    const fin = {id: r.id, kind: r.kind || (open && open.kind) || 'http', name: shortName(r.kind, r.name || (open && open.name) || '?'), worker: r.worker || (open && open.worker),
      durationMs: typeof r.durationMs === 'number' ? r.durationMs : null, phases: r.phases || null, trace: r.trace || null, endedAt: at, ts,
      outcome: (r.phases && r.phases.outcome) || (ctx.status === 'failed' ? 'failed' : 'ok'),
      error: ctx.error || null, attempt: ctx.attempt || (open && open.attempt) || 1, schedule: ctx.schedule || null,
      client: open ? open.client : 'api', route: (open && open.route) || r.name};
    S.finished.push(fin); touchWorker(fin.worker, fin, 'close', open); accountPhases(fin);
    if (fin.outcome === 'failed') S.failures.push({name: fin.name, kind: fin.kind, error: fin.error || 'no reason recorded', at, attempt: fin.attempt, id: fin.id});
    if (!historic) {
      const p = open && open.particle;
      if (p) finishParticle(p, fin);
      else spawnParticle(open || {id: r.id, kind: fin.kind, name: fin.name, worker: fin.worker, at, client: fin.client, route: fin.route}, fin, {});
    } else if (fin.outcome === 'failed' && isJob(fin.kind) && at > t0 - 600000) {
      // a historic failure still belongs in the ring: it is state, not motion
      spawnParticle({id: r.id, kind: fin.kind, name: fin.name, worker: fin.worker, at, route: fin.route}, fin, {parkOnly: true});
    }
    addTicker(fin, historic);
  }
  if (rows.length) S.lastRowAt = t0;
  prune();
}
function rememberPath(p) { if (S.recentPaths.includes(p)) return; S.recentPaths.push(p); if (S.recentPaths.length > 40) S.recentPaths.shift(); }
function prune() {
  const cut = now() - 600000;
  while (S.finished.length && S.finished[0].endedAt < cut) S.finished.shift();
  if (S.finished.length > 6000) S.finished.splice(0, S.finished.length - 6000);
  while (S.failures.length && S.failures[0].at < cut) S.failures.shift();
  if (S.failures.length > 200) S.failures.splice(0, S.failures.length - 200);
}
// A scheduler run beginning with a higher attempt releases the run of the
// same job that is orbiting in RETRY.
function resolveRetry(rec) {
  const waiting = S.particles.find(p => p.state === 'orbit' && p.ring === 'retry' && p.route === rec.route);
  if (!waiting) return;
  waiting.dead = true; waiting.state = 'gone';
}
function cronTick(rec, historic) {
  const s = S.scheduleByClass.get(rec.route) || S.schedules.find(x => x.job === rec.name || x.jobClass === rec.name);
  if (!s) return;
  s.lastRunAt = rec.at; s.runs++; s.nextAt = null;
  if (!historic) S.flashes.push({t0: now(), x: world.pts.cron.x, y: world.pts.cron.y, color: '#c084fc', small: true});
}

/* ------------------------------------------------------------ workers */
// A request lives a few milliseconds and a snapshot is taken every two
// seconds, so "busy now" is almost always 0 or 1. The rolling 60 s maximum is
// the number that says how loaded the worker really gets between two looks.
const occHist = new Map(); // pid -> [{at, num}]
function trackOccupancy(list) {
  const t = now();
  for (const c of list) {
    const h = occHist.get(c.pid) || []; h.push({at: t, num: c.num || 0});
    while (h.length && h[0].at < t - 60000) h.shift();
    occHist.set(c.pid, h);
  }
}
function occMax60(pid) { const h = occHist.get(pid); return h && h.length ? Math.max(...h.map(x => x.num)) : 0; }
function touchWorker(pid, rec, what) {
  if (!pid) return;
  let w = S.workers.get(pid);
  if (!w) { w = {pid, inflight: new Map(), hist: new Array(30).fill(0), lastAt: 0, total: 0}; S.workers.set(pid, w); }
  w.lastAt = now();
  if (what === 'open') w.inflight.set(rec.id, rec);
  else { w.inflight.delete(rec.id); w.total++; if (!rec.historic) w.hist[w.hist.length - 1]++; }
}
setInterval(() => { for (const w of S.workers.values()) { w.hist.push(0); if (w.hist.length > 30) w.hist.shift(); } }, 2000);

/* ------------------------------------------------------------ stats */
function accountPhases(fin) {
  const ph = fin.phases; if (!ph) return;
  for (const k of PHASE_KEYS) {
    if (typeof ph[k] !== 'number') continue;
    const s = S.stats[k] || (S.stats[k] = {n: 0, sum: 0, max: 0, ema: null, samples: []});
    s.n++; s.sum += ph[k]; s.max = Math.max(s.max, ph[k]); s.ema = s.ema === null ? ph[k] : s.ema * 0.9 + ph[k] * 0.1;
    s.samples.push(ph[k]); if (s.samples.length > 200) s.samples.shift();
  }
  if (ph.by) S.handlers.set(ph.by, (S.handlers.get(ph.by) || 0) + 1);
}
function window60() { const cut = now() - 60000; let i = S.finished.length; while (i > 0 && S.finished[i - 1].endedAt >= cut) i--; return S.finished.slice(i); }
function percentile(arr, p) { if (!arr.length) return null; const a = arr.slice().sort((x, y) => x - y); return a[Math.min(a.length - 1, Math.floor(p * a.length))]; }
function fmtMs(ms) { if (ms === null || ms === undefined || isNaN(ms)) return '–'; if (ms < 1) return ms.toFixed(2) + ' ms'; if (ms < 100) return ms.toFixed(1) + ' ms'; if (ms < 10000) return Math.round(ms) + ' ms'; return (ms / 1000).toFixed(1) + ' s'; }
function fmtCount(n) { return n >= 1000000 ? (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M' : n >= 1000 ? (n / 1000).toFixed(n >= 10000 ? 0 : 1).replace(/\.0$/, '') + 'k' : String(n); }
function fmtAge(s) { return s < 60 ? Math.round(s) + 's' : s < 3600 ? Math.round(s / 60) + 'm' : (s / 3600).toFixed(1) + 'h'; }
function qps() { const cut = now() - 10000; let q = 0; for (let i = S.finished.length - 1; i >= 0 && S.finished[i].endedAt >= cut; i--) q += (S.finished[i].phases && S.finished[i].phases.q) || 0; return q / 10; }

/* ------------------------------------------------------------ particles */
function travelMs(durationMs) { const d = Math.max(0, durationMs || 0); return Math.min(5200, 1500 + Math.log10(1 + d) * 520) / S.speed; }
function spawnParticle(rec, fin, opt) {
  const p = {id: rec.id, kind: rec.kind, name: rec.name, worker: rec.worker, route: rec.route || rec.name, client: rec.client || 'api', color: kindColor(rec.kind),
    born: now(), wps: [], trail: [], state: 'moving', fin: null, dead: false, angle: Math.random() * Math.PI * 2, size: rec.kind === 'sse' ? 4.5 : 5, ring: null};
  rec.particle = p; S.particles.push(p);
  if (opt.parkOnly) { p.fin = fin; p.state = 'orbit'; p.ring = 'failed'; p.color = '#ff5f6d'; p.error = fin.error; return p; }
  if (isJob(p.kind)) planJob(p, fin);
  else if (opt.resident) { p.state = 'orbit'; p.ring = 'live'; }
  else if (fin) planFull(p, fin, -1);
  else planUnknown(p);
  return p;
}
const srcKey = p => 'src:' + p.client;
// Route through the river with the real phase shares. `from` is the stage
// index the particle has reached (-1 = still at its source icon).
function planFull(p, fin, from) {
  p.fin = fin;
  const ph = fin.phases || {};
  const T = travelMs(fin.durationMs);
  const dwell = {}; let sum = 0;
  for (const s of STAGES) if (s.phase && typeof ph[s.phase] === 'number') { dwell[s.key] = ph[s.phase]; sum += ph[s.phase]; }
  const hasPhases = sum > 0;
  const nodes = STAGES.slice(Math.max(0, from));
  const hops = nodes.length - 1 + (from < 0 ? 1 : 0);
  const move = T * 0.45 / Math.max(1, hops), dwellBudget = T * 0.55;
  let t = 0; const wps = [];
  if (from < 0) { wps.push({pt: srcKey(p), t: 0}); wps.push({pt: 'trunk:' + p.client, t: move * 0.45}); wps.push({pt: 'trunk_in', t: move * 0.7}); t += move; }
  for (let i = 0; i < nodes.length; i++) {
    const s = nodes[i];
    wps.push({pt: s.key, t});
    let d = 0;
    if (hasPhases) { if (dwell[s.key] !== undefined) d = Math.max(dwellBudget * 0.04, dwellBudget * dwell[s.key] / sum); }
    else if (s.key === 'handler') d = dwellBudget;
    if (d > 0) { t += d; wps.push({pt: s.key, t}); }
    if (i < nodes.length - 1) t += move;
  }
  if (p.kind === 'sse') {
    p.wps = wps.filter(w => !['exit', 'response', 'render', 'events'].includes(w.pt));
    const last = p.wps[p.wps.length - 1].t;
    p.wps.push({pt: 'live', t: last + move * 2});
    p.born = now();
    if (fin.durationMs !== null) {
      // Planned with its end already known: the session is OVER. It flies to
      // LIVE and out — a dot that parked here would orbit forever.
      const L = world.nodes.live;
      p.wps.push({pt: {x: L.x, y: L.y - L.r - 40}, t: last + move * 2 + 500, fade: true}, {pt: 'gone', t: last + move * 2 + 501});
      p.state = 'moving'; p.total = last + move * 2 + 501;
      return;
    }
    p.state = 'toOrbit'; p.ring = 'live';
    return;
  }
  // the way back: up to the return lane, across, down to the source icon
  const back = T * 0.46;
  wps.push({pt: 'riser', t: t + back * 0.05});
  wps.push({pt: 'lane_out', t: t + back * 0.16});
  wps.push({pt: 'lane_in', t: t + back * 0.72});
  wps.push({pt: 'col:' + p.client, t: t + back * 0.9});
  wps.push({pt: srcKey(p), t: t + back, fade: true});
  wps.push({pt: 'gone', t: t + back + 1});
  p.wps = wps; p.born = now(); p.state = 'moving'; p.total = t + back;
  if (fin.phases && fin.phases.q) p.sparks = Math.min(8, fin.phases.q);
  if (fin.phases && fin.phases.queued) p.queued = fin.phases.queued;
}
function planUnknown(p) {
  const T = 1100 / S.speed, upto = IDX.handler, move = T / (upto + 1);
  const wps = [{pt: srcKey(p), t: 0}, {pt: 'trunk:' + p.client, t: move * 0.45}, {pt: 'trunk_in', t: move * 0.7}];
  for (let i = 0; i <= upto; i++) wps.push({pt: STAGES[i].key, t: (i + 1) * move});
  p.wps = wps; p.born = now(); p.state = 'holding'; p.total = T;
}
function finishParticle(p, fin) {
  p.fin = fin;
  const at = positionOf(p, now());
  if (isJob(p.kind)) { planJobEnd(p, fin, at); return; }
  if (p.state === 'orbit' || p.state === 'toOrbit') { p.state = 'leaving'; p.leaveFrom = at; p.born = now(); p.total = 700 / S.speed; return; }
  const reached = at.stageIdx === undefined ? IDX.handler : at.stageIdx;
  planFull(p, fin, reached);
  p.origin = {x: at.x, y: at.y};
}
function planJob(p, fin) {
  const src = p.kind === 'queue' ? 'queue' : 'cron';
  const T = fin ? travelMs(fin.durationMs) * 0.7 : 900 / S.speed;
  p.wps = [{pt: src, t: 0}, {pt: 'jt_' + src, t: T * 0.12}, {pt: 'jt_row', t: T * 0.2}, {pt: 'run', t: T * 0.4}];
  p.born = now();
  if (!fin) { p.state = 'holding'; p.total = T * 0.4; return; }
  p.fin = fin; p.wps.push({pt: 'run', t: T * 0.85});
  planJobTail(p, fin, T * 0.85, T * 0.35);
}
function planJobEnd(p, fin, at) {
  p.origin = {x: at.x, y: at.y}; p.born = now();
  const T = 500 / S.speed;
  p.wps = [{pt: 'run', t: 0}];
  planJobTail(p, fin, 0, T);
}
// After RUN: DONE and gone, or into RETRY / FAILED where it stays.
function planJobTail(p, fin, t, dur) {
  if (fin.outcome !== 'failed') {
    // Fade out just past DONE — not towards the return lane, which is the
    // requests' way home and made every finished job streak across the picture.
    const D = world.pts.done;
    p.wps.push({pt: 'done', t: t + dur}, {pt: {x: D.x + 46, y: D.y}, t: t + dur + 350, fade: true}); p.state = 'moving'; p.total = t + dur + 350; return;
  }
  const sched = S.scheduleByClass.get(p.route);
  const retriable = p.kind === 'scheduler' && sched && (fin.attempt || 1) < sched.maxAttempts;
  p.error = fin.error; p.attempt = fin.attempt;
  const ring = retriable ? 'retry' : 'failed';
  p.wps.push({pt: 'under_run', t: t + dur * 0.25}, {pt: 'under_' + ring, t: t + dur * 0.75}, {pt: ring + '_in', t: t + dur * 0.9}, {pt: ring, t: t + dur});
  p.state = 'toOrbit'; p.ring = ring; p.color = retriable ? '#ffb454' : '#ff5f6d'; p.total = t + dur;
}

/* ------------------------------------------------------------ world + view */
const world = {canvas: null, ctx: null, W: 0, H: 0, w: 0, h: 0, nodes: {}, pts: {}, y: 0, srcX: 0};
function layout() {
  const c = world.canvas, r = c.getBoundingClientRect(), dpr = Math.min(2, window.devicePixelRatio || 1);
  const first = world.W === 0;
  world.W = r.width; world.H = r.height;
  c.width = Math.round(r.width * dpr); c.height = Math.round(r.height * dpr);
  world.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  // The world is at least this big whatever the panel is: nodes keep a
  // readable size and the panel pans/zooms instead of squeezing them.
  const w = Math.max(r.width, 1640), h = Math.max(r.height, 740);
  world.w = w; world.h = h;
  const y = Math.round(h * 0.45); world.y = y;
  const srcX = 84; world.srcX = srcX;
  // The last node needs room on its right for the riser, the box edge and a
  // margin — the riser used to land past the world's edge and got clipped.
  const x0 = 210, xN = w - 170, n = STAGES.length, gap = (xN - x0) / (n - 1);
  const bw = 96, bh = 40;
  STAGES.forEach((s, i) => { const x = x0 + gap * i; world.nodes[s.key] = {x, y, w: bw, h: bh, stage: s, idx: i, key: s.key}; world.pts[s.key] = {x, y}; });
  const N = world.nodes;
  N['src:human'] = {x: srcX, y: y - 78, r: 22, side: 'human', key: 'human'};
  N['src:bot']   = {x: srcX, y: y,      r: 22, side: 'bot',   key: 'bot'};
  N['src:api']   = {x: srcX, y: y + 78, r: 22, side: 'api',   key: 'api'};
  // Nothing crosses anything, by construction:
  //  - LIVE sits straight above LISTENERS; the return lane runs ABOVE the ring,
  //    so the one vertical link up to LIVE never meets it;
  //  - the lane comes down the far-left column and enters each source icon
  //    from the left, while the icons' connections leave to the right;
  //  - the enqueue impulse drops from EVENTS to a bus UNDER the database and
  //    ABOVE the jobs lane, runs left, and drops into QUEUE;
  //  - the jobs lane flows left to right below everything else.
  N.live = {x: N.listeners.x, y: y - 160, r: 50, side: 'live', key: 'live'};
  N.db = {x: N.handler.x, y: y + 104, w: 82, h: 46, side: 'db', key: 'db'};
  // Every route is a Manhattan path: horizontal or vertical, never slanted.
  //   sources  → trunk at trunkX → CLIENT
  //   RESPONSE → right riser → lane above LIVE → left column → icons
  //   EVENTS   ↓ bus under the DB ← left ↓ into QUEUE from its LEFT
  //   CRON/QUEUE → job trunk on their RIGHT → jobs row → RUN → DONE
  //   RUN ↓ under DONE and RETRY → up into RETRY or FAILED
  const laneY = y - 252, busY = y + 160, jy = y + 250, underY = jy + 84;
  N.cron  = {x: x0 + 10, y: jy - 38, w: 92, h: 34, side: 'cron', key: 'cron', label: 'CRON'};
  N.queue = {x: x0 + 10, y: jy + 38, w: 92, h: 34, side: 'queue', key: 'queue', label: 'QUEUE'};
  N.run   = {x: N.resource.x, y: jy, w: 84, h: 36, side: 'run', key: 'run', label: 'RUN'};
  N.done  = {x: N.auth.x, y: jy, w: 84, h: 36, side: 'done', key: 'done', label: 'DONE'};
  N.retry = {x: N.handler.x - gap * 0.35, y: jy, r: 46, side: 'retry', key: 'retry'};
  N.failed = {x: N.render.x - gap * 0.3, y: jy, r: 46, side: 'failed', key: 'failed'};
  for (const k of ['src:human', 'src:bot', 'src:api', 'live', 'db', 'cron', 'queue', 'run', 'done', 'retry', 'failed']) world.pts[k] = {x: N[k].x, y: N[k].y};
  const colX = 30, trunkX = N.client.x - N.client.w / 2 - 44, riserX = N.response.x + N.response.w / 2 + 40;
  const busX = trunkX + 22, jobTrunkX = N.cron.x + N.cron.w / 2 + 34;
  for (const k of ['human', 'bot', 'api']) {
    world.pts['trunk:' + k] = {x: trunkX, y: N['src:' + k].y};
    world.pts['col:' + k] = {x: colX, y: N['src:' + k].y};
  }
  world.pts.trunk_in = {x: trunkX, y};
  world.pts.riser = {x: riserX, y};
  world.pts.lane_out = {x: riserX, y: laneY};
  world.pts.lane_in = {x: colX, y: laneY};
  world.pts.gone = world.pts.lane_in;
  world.pts.bus_a = {x: N.events.x, y: busY};
  world.pts.bus_b = {x: busX, y: busY};
  world.pts.bus_c = {x: busX, y: N.queue.y};
  world.pts.queue_in = {x: N.queue.x - N.queue.w / 2, y: N.queue.y};
  world.pts.jt_cron = {x: jobTrunkX, y: N.cron.y};
  world.pts.jt_queue = {x: jobTrunkX, y: N.queue.y};
  world.pts.jt_row = {x: jobTrunkX, y: jy};
  world.pts.under_run = {x: N.run.x, y: underY};
  world.pts.under_retry = {x: N.retry.x, y: underY};
  world.pts.under_failed = {x: N.failed.x, y: underY};
  world.pts.retry_in = {x: N.retry.x, y: N.retry.y + N.retry.r};
  world.pts.failed_in = {x: N.failed.x, y: N.failed.y + N.failed.r};
  // the system boundary: everything Semitexa runs; clients and the return lane stay outside
  world.box = {x: trunkX + 12, y: N.live.y - N.live.r - 22, w: riserX - 16 - (trunkX + 12), h: (underY + 22) - (N.live.y - N.live.r - 22)};
  world.laneY = laneY; world.busY = busY; world.colX = colX;
  if (first) fitView();
  else clampView();
}
function fitView() {
  const s = Math.min(world.W / world.w, world.H / world.h, 1);
  S.view.s = s; S.view.x = (world.W - world.w * s) / 2; S.view.y = (world.H - world.h * s) / 2;
  clampView();
}
function clampView() {
  const v = S.view, ww = world.w * v.s, wh = world.h * v.s;
  v.x = ww <= world.W ? (world.W - ww) / 2 : Math.min(0, Math.max(world.W - ww, v.x));
  v.y = wh <= world.H ? (world.H - wh) / 2 : Math.min(0, Math.max(world.H - wh, v.y));
}
function zoomAt(sx, sy, factor) {
  const v = S.view, ns = Math.min(3, Math.max(0.35, v.s * factor));
  const wx = (sx - v.x) / v.s, wy = (sy - v.y) / v.s;
  v.s = ns; v.x = sx - wx * ns; v.y = sy - wy * ns; clampView();
}
const toWorld = (sx, sy) => ({x: (sx - S.view.x) / S.view.s, y: (sy - S.view.y) / S.view.s});
const toScreen = (wx, wy) => ({x: wx * S.view.s + S.view.x, y: wy * S.view.s + S.view.y});
function ptOf(name) { if (name && typeof name === 'object') return name; return world.pts[name] || world.pts.gone; }
function ringOf(p) { return world.nodes[p.ring || 'live']; }
function positionOf(p, t) {
  const el = t - p.born;
  if (p.state === 'orbit' || (p.state === 'toOrbit' && el >= p.wps[p.wps.length - 1].t)) {
    if (p.state === 'toOrbit') { p.state = 'orbit'; if (p.ring === 'failed') p.frozen = p.angle; }
    const L = ringOf(p);
    const speed = p.ring === 'live' ? 0.6 : p.ring === 'retry' ? 1.1 : 0.05;
    const a = p.angle + t / 2600 * speed;
    const rr = L.r * (p.ring === 'failed' ? 0.45 + ((p.angle * 7) % 1) * 0.4 : 0.72);
    return {x: L.x + Math.cos(a) * rr, y: L.y + Math.sin(a) * rr, node: p.ring, ring: true};
  }
  if (p.state === 'leaving') {
    const k = Math.min(1, el / p.total); const from = p.leaveFrom, to = {x: from.x, y: from.y - 70};
    if (k >= 1) p.dead = true;
    return {x: from.x + (to.x - from.x) * k, y: from.y + (to.y - from.y) * k, alpha: 1 - k};
  }
  const wps = p.wps;
  if (el <= 0) { const o = p.origin || ptOf(wps[0].pt); return {x: o.x, y: o.y, node: wps[0].pt, stageIdx: IDX[wps[0].pt]}; }
  for (let i = 0; i < wps.length - 1; i++) {
    const a = wps[i], b = wps[i + 1];
    if (el <= b.t) {
      const k = b.t === a.t ? 1 : (el - a.t) / (b.t - a.t);
      const A = (i === 0 && p.origin) ? p.origin : ptOf(a.pt), B = ptOf(b.pt);
      const e = k < .5 ? 2 * k * k : -1 + (4 - 2 * k) * k;
      const dwelling = a.pt === b.pt;
      const fading = b.pt === 'gone' || b.fade === true;
      return {x: A.x + (B.x - A.x) * e, y: A.y + (B.y - A.y) * e, node: dwelling ? a.pt : null, dwelling,
        stageIdx: IDX[dwelling ? a.pt : (k < .5 ? a.pt : b.pt)], alpha: fading ? 1 - k * 0.7 : 1, returning: ['riser', 'lane_out', 'lane_in'].includes(a.pt) || (typeof a.pt === 'string' && a.pt.startsWith('col:')) || a.pt === 'response'};
    }
  }
  const last = wps[wps.length - 1];
  if (p.state === 'holding') { const P = ptOf(last.pt); return {x: P.x, y: P.y, node: last.pt, dwelling: true, held: true, stageIdx: IDX[last.pt]}; }
  p.dead = true;
  const P = ptOf(last.pt); return {x: P.x, y: P.y, node: last.pt, stageIdx: IDX[last.pt]};
}

/* ------------------------------------------------------------ drawing */
function cssVar(n) { return getComputedStyle(document.documentElement).getPropertyValue(n).trim(); }
let theme = {};
function readTheme() { theme = {text: cssVar('--text'), dim: cssVar('--dim'), faint: cssVar('--faint'), line: cssVar('--line-2'), panel: cssVar('--panel-2'), ok: cssVar('--ok'), danger: cssVar('--danger'), warn: cssVar('--warn'), mono: cssVar('--mono'), sans: cssVar('--sans')}; }
function rr(ctx, x, y, w, h, r) { ctx.beginPath(); ctx.roundRect(x, y, w, h, r); }
function alongBus(k) { // EVENTS → bus → QUEUE, parameterised by length
  const pts = [world.pts.events, world.pts.bus_a, world.pts.bus_b, world.pts.bus_c, world.pts.queue_in, world.pts.queue];
  const seg = []; let L = 0; for (let i = 0; i < pts.length - 1; i++) { const l = Math.hypot(pts[i + 1].x - pts[i].x, pts[i + 1].y - pts[i].y); seg.push(l); L += l; }
  let d = k * L; for (let i = 0; i < seg.length; i++) { if (d <= seg[i] || i === seg.length - 1) { const f = seg[i] ? Math.min(1, d / seg[i]) : 1; return {x: pts[i].x + (pts[i + 1].x - pts[i].x) * f, y: pts[i].y + (pts[i + 1].y - pts[i].y) * f}; } d -= seg[i]; }
  return pts[pts.length - 1];
}
function bez(a, b, k) { // vertical S-curve between two points, parameter k in [0,1]
  const c1 = {x: a.x, y: a.y + (b.y - a.y) * 0.5}, c2 = {x: b.x, y: a.y + (b.y - a.y) * 0.5}; const m = 1 - k;
  return {x: m * m * m * a.x + 3 * m * m * k * c1.x + 3 * m * k * k * c2.x + k * k * k * b.x, y: m * m * m * a.y + 3 * m * m * k * c1.y + 3 * m * k * k * c2.y + k * k * k * b.y};
}

function drawRiver(t) {
  const ctx = world.ctx, W = world.W, H = world.H, y = world.y, N = world.nodes, v = S.view;
  ctx.clearRect(0, 0, W, H);
  const occ = {}, pos = new Map();
  for (const p of S.particles) { const at = positionOf(p, t); pos.set(p, at); if (at.node && !at.ring) occ[at.node] = (occ[at.node] || 0) + 1; }
  const ringCount = {live: 0, retry: 0, failed: 0};
  for (const p of S.particles) if (p.state === 'orbit' && p.ring) ringCount[p.ring]++;

  ctx.save(); ctx.translate(v.x, v.y); ctx.scale(v.s, v.s);
  ctx.font = '600 10px ' + theme.mono;

  // river bed + flowing dashes
  const xA = N.client.x - 40, xB = N.response.x + 40;
  ctx.lineCap = 'round'; ctx.lineWidth = 10;
  const g = ctx.createLinearGradient(xA, 0, xB, 0); g.addColorStop(0, 'rgba(91,157,255,.10)'); g.addColorStop(1, 'rgba(91,157,255,.22)');
  ctx.strokeStyle = g; ctx.beginPath(); ctx.moveTo(xA, y); ctx.lineTo(xB, y); ctx.stroke();
  ctx.save(); ctx.setLineDash([6, 18]); ctx.lineDashOffset = -(t / 30) % 24; ctx.lineWidth = 2; ctx.strokeStyle = 'rgba(91,157,255,.35)';
  ctx.beginPath(); ctx.moveTo(xA, y); ctx.lineTo(xB, y); ctx.stroke(); ctx.restore();
  // the system boundary
  const B = world.box;
  ctx.save(); ctx.setLineDash([10, 8]); ctx.lineDashOffset = -(t / 80) % 18; ctx.strokeStyle = 'rgba(91,157,255,.35)'; ctx.lineWidth = 1.5; rr(ctx, B.x, B.y, B.w, B.h, 22); ctx.stroke();
  ctx.fillStyle = 'rgba(91,157,255,.035)'; rr(ctx, B.x, B.y, B.w, B.h, 22); ctx.fill(); ctx.restore();
  ctx.font = '700 11px ' + theme.mono; ctx.fillStyle = '#5b9dff'; ctx.textAlign = 'left'; ctx.textBaseline = 'middle'; ctx.fillText('SEMITEXA', B.x + 18, B.y + 16);
  ctx.font = '500 9.5px ' + theme.mono; ctx.fillStyle = theme.faint; ctx.fillText('one Swoole process tree · workers · scheduler · queue', B.x + 92, B.y + 16);
  ctx.textAlign = 'right'; ctx.fillText('outside: clients and the wire', B.x - 8, B.y + 16);

  // return lane: RESPONSE → riser → across above LIVE → left column → icons
  const rz = world.pts.riser, lo = world.pts.lane_out, li = world.pts.lane_in, lowest = N['src:api'];
  ctx.save(); ctx.strokeStyle = 'rgba(91,157,255,.18)'; ctx.lineWidth = 4; ctx.lineJoin = 'round'; ctx.lineCap = 'round'; ctx.beginPath();
  ctx.moveTo(N.response.x + N.response.w / 2, y); ctx.lineTo(rz.x, rz.y); ctx.lineTo(lo.x, lo.y); ctx.lineTo(li.x, li.y); ctx.lineTo(li.x, lowest.y); ctx.stroke();
  for (const k of ['human', 'bot', 'api']) { const c = world.pts['col:' + k], sN = N['src:' + k]; ctx.beginPath(); ctx.moveTo(c.x, c.y); ctx.lineTo(sN.x - sN.r, sN.y); ctx.stroke(); }
  ctx.setLineDash([4, 14]); ctx.lineDashOffset = (t / 30) % 18; ctx.lineWidth = 1.5; ctx.strokeStyle = 'rgba(91,157,255,.35)'; ctx.beginPath(); ctx.moveTo(rz.x, rz.y); ctx.lineTo(lo.x, lo.y); ctx.lineTo(li.x, li.y); ctx.lineTo(li.x, lowest.y); ctx.stroke(); ctx.restore();
  ctx.fillStyle = theme.faint; ctx.font = '500 9.5px ' + theme.mono; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText('response → back to the client', (lo.x + li.x) / 2, li.y - 10);
  // connections: icons → trunk → CLIENT
  const tk = world.pts.trunk_in;
  ctx.save(); ctx.strokeStyle = 'rgba(91,157,255,.22)'; ctx.lineWidth = 3; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.beginPath();
  for (const k of ['human', 'bot', 'api']) { const sN = N['src:' + k], tp = world.pts['trunk:' + k]; ctx.moveTo(sN.x + sN.r, sN.y); ctx.lineTo(tp.x, tp.y); }
  ctx.moveTo(tk.x, N['src:human'].y); ctx.lineTo(tk.x, N['src:api'].y); ctx.moveTo(tk.x, y); ctx.lineTo(N.client.x - N.client.w / 2, y); ctx.stroke(); ctx.restore();
  // side links: straight verticals, and the enqueue bus under the database
  vlink(ctx, N.handler.x, y + N.handler.h / 2, N.db.y - N.db.h / 2, 'rgba(245,158,11,.35)');
  vlink(ctx, N.listeners.x, y - N.listeners.h / 2, N.live.y + N.live.r, 'rgba(255,180,84,.30)');
  const ba = world.pts.bus_a, bb = world.pts.bus_b, bc = world.pts.bus_c, qi = world.pts.queue_in;
  ctx.save(); ctx.strokeStyle = 'rgba(52,211,153,.22)'; ctx.lineWidth = 5; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.beginPath();
  ctx.moveTo(N.events.x, y + N.events.h / 2); ctx.lineTo(ba.x, ba.y); ctx.lineTo(bb.x, bb.y); ctx.lineTo(bc.x, bc.y); ctx.lineTo(qi.x, qi.y); ctx.stroke(); ctx.restore();
  ctx.fillStyle = theme.faint; ctx.font = '500 9.5px ' + theme.mono; ctx.textAlign = 'center'; ctx.fillText('enqueue', (ba.x + bb.x) / 2, ba.y - 9);
  // jobs: CRON / QUEUE → job trunk → row → RUN → DONE; failures dip under DONE and RETRY
  const jc = world.pts.jt_cron, jq = world.pts.jt_queue, jr = world.pts.jt_row;
  ctx.save(); ctx.strokeStyle = 'rgba(192,132,252,.25)'; ctx.lineWidth = 6; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.beginPath();
  ctx.moveTo(N.cron.x + N.cron.w / 2, N.cron.y); ctx.lineTo(jc.x, jc.y); ctx.lineTo(jq.x, jq.y); ctx.lineTo(N.queue.x + N.queue.w / 2, N.queue.y);
  ctx.moveTo(jr.x, jr.y); ctx.lineTo(N.done.x, N.done.y); ctx.stroke();
  const ur = world.pts.under_run, uf = world.pts.under_failed;
  ctx.strokeStyle = 'rgba(255,95,109,.18)'; ctx.beginPath(); ctx.moveTo(N.run.x, N.run.y + N.run.h / 2); ctx.lineTo(ur.x, ur.y); ctx.lineTo(uf.x, uf.y); ctx.lineTo(uf.x, N.failed.y + N.failed.r);
  ctx.moveTo(N.retry.x, ur.y); ctx.lineTo(N.retry.x, N.retry.y + N.retry.r); ctx.stroke(); ctx.restore();
  ctx.fillStyle = theme.faint; ctx.font = '500 9.5px ' + theme.mono; ctx.textAlign = 'center'; ctx.fillText('failed →', (ur.x + N.retry.x) / 2, ur.y + 11);

  // nodes
  for (const k of ['src:human', 'src:bot', 'src:api']) drawSource(ctx, N[k], occ[k] || 0, t);
  for (const s of STAGES) drawNode(ctx, N[s.key], occ[s.key] || 0);
  drawDb(ctx, N.db);
  drawRing(ctx, N.live, ringCount.live, 'LIVE', ringCount.live + ' sse', '#ffb454', t);
  drawRing(ctx, N.retry, ringCount.retry, 'RETRY', ringCount.retry + ' waiting', '#ffb454', t);
  drawRing(ctx, N.failed, ringCount.failed, 'FAILED', ringCount.failed + ' jobs', '#ff5f6d', t);
  for (const k of ['cron', 'queue', 'run', 'done']) drawSmall(ctx, N[k], occ[k] || 0, t);

  // sparks handler → db
  for (let i = S.sparks.length - 1; i >= 0; i--) {
    const s = S.sparks[i]; const k = (t - s.t0) / s.dur; if (k >= 1) { S.sparks.splice(i, 1); continue; } if (k < 0) continue;
    const kk = k < .5 ? k * 2 : (1 - k) * 2; const A = world.pts.handler, B = world.pts.db;
    ctx.fillStyle = 'rgba(245,158,11,' + (0.9 - k * 0.5) + ')'; ctx.beginPath(); ctx.arc(A.x + (B.x - A.x) * kk + s.dx * Math.sin(k * Math.PI), A.y + (B.y - A.y) * kk, 2.2, 0, 7); ctx.fill();
  }
  // impulses events → queue
  for (let i = S.impulses.length - 1; i >= 0; i--) {
    const im = S.impulses[i]; const k = (t - im.t0) / im.dur; if (k >= 1) { S.impulses.splice(i, 1); S.flashes.push({t0: t, x: N.queue.x, y: N.queue.y, color: '#34d399', small: true}); continue; }
    const P = alongBus(k);
    ctx.shadowColor = '#34d399'; ctx.shadowBlur = 12; ctx.fillStyle = '#34d399'; ctx.beginPath(); ctx.arc(P.x, P.y, 3.5, 0, 7); ctx.fill(); ctx.shadowBlur = 0;
  }
  // flashes
  for (let i = S.flashes.length - 1; i >= 0; i--) {
    const f = S.flashes[i]; const k = (t - f.t0) / 650; if (k >= 1) { S.flashes.splice(i, 1); continue; }
    ctx.strokeStyle = f.color; ctx.globalAlpha = 1 - k; ctx.lineWidth = 2.5 - k * 2; ctx.beginPath(); ctx.arc(f.x, f.y, (f.small ? 6 : 8) + k * (f.small ? 22 : 34), 0, 7); ctx.stroke(); ctx.globalAlpha = 1;
  }
  // particles
  const labels = S.particles.filter(p => p.state !== 'orbit').length <= 18;
  for (let i = S.particles.length - 1; i >= 0; i--) {
    const p = S.particles[i]; const at = pos.get(p);
    if (p.dead) { S.particles.splice(i, 1); if (p.state !== 'gone') onExit(p); continue; }
    if (p.state !== 'orbit' && t - p.born > 60000) { S.particles.splice(i, 1); continue; }
    if (at.node === 'handler' && p.sparks) { for (let q = 0; q < p.sparks; q++) S.sparks.push({t0: t + q * 90, dur: 520 / S.speed, dx: (Math.random() - .5) * 8}); p.sparks = 0; }
    if (at.node === 'events' && p.queued) { for (let q = 0; q < Math.min(4, p.queued); q++) S.impulses.push({t0: t + q * 120, dur: 1100 / S.speed}); p.queued = 0; }
    p.trail.push({x: at.x, y: at.y}); if (p.trail.length > 9) p.trail.shift();
    const alpha = at.alpha === undefined ? 1 : at.alpha;
    if (p.ring !== 'failed' || p.state !== 'orbit') for (let j = 0; j < p.trail.length - 1; j++) { const q = p.trail[j]; ctx.fillStyle = p.color; ctx.globalAlpha = alpha * (j / p.trail.length) * 0.35; ctx.beginPath(); ctx.arc(q.x, q.y, p.size * (0.3 + j / p.trail.length * 0.6), 0, 7); ctx.fill(); }
    ctx.globalAlpha = alpha; ctx.shadowColor = p.color; ctx.shadowBlur = 14; ctx.fillStyle = p.color; ctx.beginPath(); ctx.arc(at.x, at.y, p.size, 0, 7); ctx.fill(); ctx.shadowBlur = 0;
    if (at.held) { const k = (t % 1000) / 1000; ctx.strokeStyle = p.color; ctx.globalAlpha = alpha * (1 - k); ctx.lineWidth = 1.5; ctx.beginPath(); ctx.arc(at.x, at.y, p.size + k * 14, 0, 7); ctx.stroke(); }
    if (labels && p.state !== 'orbit' && !at.returning) {
      ctx.globalAlpha = alpha * 0.9; ctx.font = '600 10px ' + theme.mono; ctx.fillStyle = theme.text; ctx.textAlign = 'left';
      const lbl = p.name.length > 26 ? p.name.slice(0, 25) + '…' : p.name;
      const onRiver = Math.abs(at.y - y) < 30;
      ctx.fillText(lbl, at.x + 9, onRiver ? at.y - 30 : at.y - 12);
    }
    ctx.globalAlpha = 1;
  }
  ctx.restore();
  drawScrollbars(ctx);
}
function onExit(p) {
  const fin = p.fin; const color = !fin ? theme.ok : fin.outcome === 'exception' || fin.outcome === 'failed' ? theme.danger : fin.outcome === 'rejected' ? theme.warn : fin.outcome === 'queued' ? KIND_COLOR.queue : theme.ok;
  const P = isJob(p.kind) ? world.pts.done : world.pts.response;
  S.flashes.push({t0: now(), x: P.x, y: P.y, color});
}
function vlink(ctx, x, y1, y2, color) { ctx.save(); ctx.strokeStyle = color; ctx.lineWidth = 6; ctx.lineCap = 'round'; ctx.beginPath(); ctx.moveTo(x, y1); ctx.lineTo(x, y2); ctx.stroke(); ctx.restore(); }
function isActive(key) { return S.hover === key || (S.pinned && S.pinned.key === key); }
function drawNode(ctx, n, occ) {
  const {x, y, w, h, stage} = n; const hot = Math.min(1, occ / 3), active = isActive(stage.key);
  if (occ > 0) { ctx.shadowColor = 'rgba(91,157,255,' + (0.35 + hot * 0.5) + ')'; ctx.shadowBlur = 18 + hot * 26; }
  ctx.fillStyle = occ > 0 ? 'rgba(91,157,255,' + (0.18 + hot * 0.25) + ')' : theme.panel; rr(ctx, x - w / 2, y - h / 2, w, h, 10); ctx.fill(); ctx.shadowBlur = 0;
  ctx.strokeStyle = active ? '#5b9dff' : occ > 0 ? 'rgba(91,157,255,.8)' : theme.line; ctx.lineWidth = active ? 2 : 1; rr(ctx, x - w / 2, y - h / 2, w, h, 10); ctx.stroke();
  ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.font = '700 10px ' + theme.mono; ctx.fillStyle = theme.text; ctx.fillText(stage.label, x, y - (stage.phase ? 6 : 0));
  if (stage.phase) { const s = S.stats[stage.phase]; ctx.font = '500 10px ' + theme.mono; ctx.fillStyle = s ? theme.dim : theme.faint; ctx.fillText(s ? fmtMs(s.ema) : '· · ·', x, y + 9); }
  if (occ > 1) { ctx.font = '700 9px ' + theme.mono; ctx.fillStyle = '#5b9dff'; ctx.fillText('×' + occ, x + w / 2 - 10, y - h / 2 + 8); }
  if (S.explain) { ctx.font = '500 9.5px ' + theme.sans; ctx.fillStyle = theme.dim; wrap(ctx, stage.title, x, y + h / 2 + 12, w + 14, 11); }
}
function drawSmall(ctx, n, occ, t) {
  const {x, y, w, h} = n; const color = n.key === 'queue' ? '#34d399' : '#c084fc'; const active = isActive(n.key);
  ctx.fillStyle = occ ? color.replace(')', ',.25)').replace('#', 'rgba(').replace(/rgba\((\w\w)(\w\w)(\w\w)/, (m, r, g, b) => 'rgba(' + parseInt(r, 16) + ',' + parseInt(g, 16) + ',' + parseInt(b, 16)) : theme.panel;
  rr(ctx, x - w / 2, y - h / 2, w, h, 9); ctx.fill();
  ctx.strokeStyle = active || occ ? color : theme.line; ctx.lineWidth = active ? 2 : 1; rr(ctx, x - w / 2, y - h / 2, w, h, 9); ctx.stroke();
  ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.font = '700 10px ' + theme.mono; ctx.fillStyle = theme.text; ctx.fillText(n.label, x, y - (n.key === 'cron' ? 5 : 0));
  if (n.key === 'cron') {
    // the soonest schedule, as a countdown — the presenter's "watch this"
    const next = nextSchedule(); ctx.font = '500 9.5px ' + theme.mono; ctx.fillStyle = next ? '#c084fc' : theme.faint;
    ctx.fillText(next ? 'next in ' + Math.max(0, Math.round((next.nextAt - Date.now()) / 1000)) + 's' : (S.schedules.length ? '–' : 'no schedules'), x, y + 8);
    // a clock hand sweeping once a minute
    const a = -Math.PI / 2 + (Date.now() % 60000) / 60000 * Math.PI * 2, cx = x - w / 2 - 14, cy = y;
    ctx.strokeStyle = theme.line; ctx.lineWidth = 1; ctx.beginPath(); ctx.arc(cx, cy, 8, 0, 7); ctx.stroke();
    ctx.strokeStyle = '#c084fc'; ctx.lineWidth = 1.5; ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(cx + Math.cos(a) * 7, cy + Math.sin(a) * 7); ctx.stroke();
  }
}
function drawDb(ctx, n) {
  const {x, y, w, h} = n; const q = qps(); const active = isActive('db');
  ctx.fillStyle = theme.panel; ctx.strokeStyle = active ? '#f59e0b' : q > 0 ? 'rgba(245,158,11,.8)' : theme.line; ctx.lineWidth = active ? 2 : 1;
  ctx.beginPath(); ctx.ellipse(x, y - h / 2 + 7, w / 2, 7, 0, 0, 7); ctx.fill(); ctx.stroke();
  ctx.beginPath(); ctx.moveTo(x - w / 2, y - h / 2 + 7); ctx.lineTo(x - w / 2, y + h / 2 - 7); ctx.ellipse(x, y + h / 2 - 7, w / 2, 7, 0, Math.PI, 0, true); ctx.lineTo(x + w / 2, y - h / 2 + 7); ctx.fill(); ctx.stroke();
  ctx.beginPath(); ctx.ellipse(x, y - h / 2 + 7, w / 2, 7, 0, 0, 7); ctx.stroke();
  ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.font = '700 10px ' + theme.mono; ctx.fillStyle = theme.text; ctx.fillText('ORM · DB', x, y + 2);
  ctx.font = '500 9.5px ' + theme.mono; ctx.fillStyle = theme.dim; ctx.fillText(q ? q.toFixed(1) + ' q/s' : 'idle', x, y + 14);
}
function drawRing(ctx, n, count, label, sub, color, t) {
  const {x, y, r} = n; const active = isActive(n.key);
  ctx.save(); ctx.setLineDash([3, 6]); ctx.lineDashOffset = -(t / 60) % 9; ctx.strokeStyle = active ? color : count ? color.replace(')', '') && hexA(color, .7) : theme.line; ctx.lineWidth = active ? 2 : 1.2; ctx.beginPath(); ctx.arc(x, y, r, 0, 7); ctx.stroke(); ctx.restore();
  ctx.fillStyle = count ? hexA(color, .07) : 'transparent'; ctx.beginPath(); ctx.arc(x, y, r, 0, 7); ctx.fill();
  ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.font = '700 10px ' + theme.mono; ctx.fillStyle = theme.text; ctx.fillText(label, x, y - 5);
  ctx.font = '500 9.5px ' + theme.mono; ctx.fillStyle = count ? color : theme.faint; ctx.fillText(sub, x, y + 8);
}
function hexA(hex, a) { const n = parseInt(hex.slice(1), 16); return 'rgba(' + (n >> 16) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')'; }
function drawSource(ctx, n, occ, t) {
  const {x, y, r} = n; const kind = n.key; const active = isActive(kind); const w60 = S.clients[kind] || 0;
  ctx.fillStyle = theme.panel; ctx.strokeStyle = active ? '#5b9dff' : occ ? 'rgba(91,157,255,.8)' : theme.line; ctx.lineWidth = active ? 2 : 1;
  ctx.beginPath(); ctx.arc(x, y, r, 0, 7); ctx.fill(); ctx.stroke();
  ctx.strokeStyle = theme.text; ctx.fillStyle = theme.text; ctx.lineWidth = 1.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
  if (kind === 'human') { // head + shoulders
    ctx.beginPath(); ctx.arc(x, y - 5, 4.5, 0, 7); ctx.stroke();
    ctx.beginPath(); ctx.arc(x, y + 9, 9, Math.PI * 1.15, Math.PI * 1.85); ctx.stroke();
  } else if (kind === 'bot') { // head with eyes and an antenna
    rr(ctx, x - 8, y - 5, 16, 12, 3); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(x, y - 5); ctx.lineTo(x, y - 10); ctx.stroke(); ctx.beginPath(); ctx.arc(x, y - 11, 1.5, 0, 7); ctx.fill();
    ctx.beginPath(); ctx.arc(x - 3.5, y + 1, 1.6, 0, 7); ctx.arc(x + 3.5, y + 1, 1.6, 0, 7); ctx.fill();
  } else { // globe
    ctx.beginPath(); ctx.arc(x, y, 8, 0, 7); ctx.stroke();
    ctx.beginPath(); ctx.ellipse(x, y, 3.2, 8, 0, 0, 7); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(x - 8, y); ctx.lineTo(x + 8, y); ctx.stroke();
  }
  ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.font = '700 9px ' + theme.mono; ctx.fillStyle = theme.dim;
  ctx.fillText(kind === 'human' ? 'PEOPLE' : kind === 'bot' ? 'BOTS' : 'PROGRAMS', x, y + r + 9);
  ctx.font = '500 9.5px ' + theme.mono; ctx.fillStyle = w60 ? theme.text : theme.faint; ctx.fillText(String(w60), x - r - 12, y);
}
function wrap(ctx, text, x, y, maxW, lh) { const words = text.split(' '); let line = ''; for (const w of words) { const test = line ? line + ' ' + w : w; if (ctx.measureText(test).width > maxW && line) { ctx.fillText(line, x, y); y += lh; line = w; } else line = test; } if (line) ctx.fillText(line, x, y); }
function drawScrollbars(ctx) {
  const v = S.view, W = world.W, H = world.H, ww = world.w * v.s, wh = world.h * v.s;
  ctx.fillStyle = 'rgba(139,150,177,.35)';
  if (ww > W + 1) { const len = Math.max(30, W * W / ww), pos = (-v.x) / (ww - W) * (W - len); rr(ctx, pos, H - 6, len, 4, 2); ctx.fill(); }
  if (wh > H + 1) { const len = Math.max(30, H * H / wh), pos = (-v.y) / (wh - H) * (H - len); rr(ctx, W - 6, pos, 4, len, 2); ctx.fill(); }
  if (Math.abs(v.s - 1) > 0.01 || ww > W + 1 || wh > H + 1) { ctx.font = '500 10px ' + theme.mono; ctx.fillStyle = theme.faint; ctx.textAlign = 'left'; ctx.textBaseline = 'top'; ctx.fillText(Math.round(v.s * 100) + '% · drag or arrows to pan · wheel or +/− to zoom · dbl-click / 0 to fit', 12, 10); }
}

/* ------------------------------------------------------------ cron */
// 5-field (min hour dom mon dow) or 6-field (sec first) expressions:
// *, */n, a-b, a-b/n, lists. Enough for every schedule this project declares.
function cronField(expr, min, max) {
  const set = new Set();
  for (const part of expr.split(',')) {
    let m = part.match(/^(\*|\d+)(?:-(\d+))?(?:\/(\d+))?$/); if (!m) return null;
    let a = m[1] === '*' ? min : +m[1], b = m[1] === '*' ? max : (m[2] !== undefined ? +m[2] : (m[3] ? max : +m[1])), step = m[3] ? +m[3] : 1;
    for (let i = a; i <= b; i += step) set.add(i);
  }
  return set;
}
function cronNext(expr, fromMs) {
  const f = expr.trim().split(/\s+/); if (f.length !== 5 && f.length !== 6) return null;
  const six = f.length === 6; const [sec, mi, ho, dom, mon, dow] = six ? f : ['0', ...f];
  const F = [cronField(sec, 0, 59), cronField(mi, 0, 59), cronField(ho, 0, 23), cronField(dom, 1, 31), cronField(mon, 1, 12), cronField(dow, 0, 7)];
  if (F.some(x => x === null)) return null;
  if (F[5].has(7)) F[5].add(0);
  const d = new Date(fromMs); d.setMilliseconds(0); d.setSeconds(d.getSeconds() + 1);
  const limit = six ? 86400 * 2 : 60 * 24 * 400;
  for (let i = 0; i < limit; i++) {
    if (!F[4].has(d.getMonth() + 1)) { d.setMonth(d.getMonth() + 1, 1); d.setHours(0, 0, 0); continue; }
    if (!F[3].has(d.getDate()) || !F[5].has(d.getDay())) { d.setDate(d.getDate() + 1); d.setHours(0, 0, 0); continue; }
    if (!F[2].has(d.getHours())) { d.setHours(d.getHours() + 1, 0, 0); continue; }
    if (!F[1].has(d.getMinutes())) { d.setMinutes(d.getMinutes() + 1, 0); continue; }
    if (!F[0].has(d.getSeconds())) { d.setSeconds(d.getSeconds() + 1); continue; }
    return d.getTime();
  }
  return null;
}
function nextSchedule() { let best = null; for (const s of S.schedules) { if (s.nextAt === null || s.nextAt < Date.now()) s.nextAt = cronNext(s.cron, Date.now()) || Infinity; if (s.nextAt !== Infinity && (!best || s.nextAt < best.nextAt)) best = s; } return best; }
function renderCron(force) {
  const box = $('#cron'); if (!box) return;
  if (!S.schedules.length) { box.innerHTML = '<div class="empty">No #[AsScheduledJob] declared.</div>'; return; }
  nextSchedule();
  const rows = S.schedules.slice().sort((a, b) => a.nextAt - b.nextAt);
  box.innerHTML = rows.map(s => {
    const inS = s.nextAt === Infinity ? null : Math.max(0, Math.round((s.nextAt - Date.now()) / 1000));
    const inTxt = inS === null ? '–' : inS < 90 ? inS + 's' : inS < 3600 ? Math.floor(inS / 60) + 'm ' + (inS % 60) + 's' : Math.floor(inS / 3600) + 'h ' + Math.floor((inS % 3600) / 60) + 'm';
    const fresh = now() - s.lastRunAt < 1500;
    return '<div class="cr' + (fresh ? ' fire' : '') + '"><span class="dot"></span><div class="k" title="' + esc(s.jobClass) + '">' + esc(s.key) + '<small>' + esc(s.cron) + (s.maxAttempts > 1 ? ' · ×' + s.maxAttempts + ' / ' + s.backoffS + 's' : '') + '</small></div><div class="n">' + inTxt + '<small>' + (s.runs ? s.runs + ' run' + (s.runs > 1 ? 's' : '') : '') + '</small></div></div>';
  }).join('');
}

/* ------------------------------------------------------------ timeline */
const tl = {canvas: null, ctx: null, W: 0, H: 0};
function layoutTl() { const c = tl.canvas, r = c.getBoundingClientRect(); const d = Math.min(2, window.devicePixelRatio || 1); tl.W = r.width; tl.H = r.height; c.width = Math.round(r.width * d); c.height = Math.round(r.height * d); tl.ctx.setTransform(d, 0, 0, d, 0, 0); }
function drawTimeline(t) {
  const ctx = tl.ctx, W = tl.W, H = tl.H; ctx.clearRect(0, 0, W, H);
  const span = 60000, left = 54, right = W - 12, top = 30, bottom = H - 18;
  const xOf = at => right - (t - at) / span * (right - left);
  const lo = Math.log10(0.1), hi = Math.log10(10000);
  const yOf = ms => bottom - (Math.min(hi, Math.max(lo, Math.log10(Math.max(0.1, ms)))) - lo) / (hi - lo) * (bottom - top);
  ctx.font = '500 10px ' + theme.mono; ctx.fillStyle = theme.dim; ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
  for (const [ms, l] of [[1, '1ms'], [10, '10ms'], [100, '100ms'], [1000, '1s']]) { const yy = yOf(ms); ctx.fillText(l, left - 6, yy); ctx.strokeStyle = theme.line; ctx.globalAlpha = .5; ctx.beginPath(); ctx.moveTo(left, yy); ctx.lineTo(right, yy); ctx.stroke(); ctx.globalAlpha = 1; }
  ctx.textAlign = 'center'; ctx.textBaseline = 'top';
  for (let s = 0; s <= 60; s += 10) ctx.fillText(s === 0 ? 'now' : '-' + s + 's', xOf(t - s * 1000), bottom + 3);
  const bins = new Map(), cut = t - span;
  for (let i = S.finished.length - 1; i >= 0; i--) {
    const f = S.finished[i]; if (f.endedAt < cut) break;
    const b = Math.floor((t - f.endedAt) / 1000); const m = bins.get(b) || (bins.set(b, {}), bins.get(b)); m[f.kind] = (m[f.kind] || 0) + 1;
    if (f.durationMs !== null) {
      const bad = f.outcome === 'exception' || f.outcome === 'failed';
      ctx.fillStyle = bad ? theme.danger : kindColor(f.kind); ctx.globalAlpha = bad ? 1 : .95;
      ctx.beginPath(); ctx.arc(xOf(f.endedAt), yOf(f.durationMs), bad ? 3.6 : 2.6, 0, 7); ctx.fill(); ctx.globalAlpha = 1;
    }
  }
  let maxN = 1; for (const m of bins.values()) maxN = Math.max(maxN, Object.values(m).reduce((a, b) => a + b, 0));
  const bw = (right - left) / 60;
  for (const [b, m] of bins) { let yy = bottom; const x = xOf(t - b * 1000 - 500); for (const [k, n] of Object.entries(m)) { const h = n / maxN * 34; ctx.fillStyle = kindColor(k); ctx.globalAlpha = .55; ctx.fillRect(x - bw / 2 + 1, yy - h, Math.max(1, bw - 2), h); yy -= h; } ctx.globalAlpha = 1; }
  for (const p of S.procs.values()) { if (p.historic || p.at < cut) continue; ctx.strokeStyle = kindColor(p.kind); ctx.beginPath(); ctx.arc(xOf(p.at), top - 6, 2.5, 0, 7); ctx.stroke(); }
}

/* ------------------------------------------------------------ DOM panels */
function hungCoroutines() {
  const out = [];
  for (const w of S.coroutines) for (const c of w.longest || []) {
    if (c.ms < HUNG_MS) continue;
    let owner = null;
    for (const p of S.procs.values()) if (p.worker === w.pid && p.cid === c.cid) { owner = p; break; }
    if (owner && owner.kind === 'sse') continue; // long by design
    out.push({pid: w.pid, cid: c.cid, ms: c.ms, frame: c.frame, owner});
  }
  return out.sort((a, b) => b.ms - a.ms);
}
function renderWorkers() {
  const box = $('#wlist'); const ws = [...S.workers.values()].filter(w => now() - w.lastAt < 600000 || w.inflight.size).sort((a, b) => (+a.pid || 0) - (+b.pid || 0));
  const co = new Map(S.coroutines.map(c => [c.pid, c]));
  if (!ws.length) box.innerHTML = '<div class="empty">No worker has spoken yet.</div>';
  else {
    const max = Math.max(1, ...ws.map(w => Math.max(...w.hist)));
    box.innerHTML = ws.map(w => {
      const active = now() - w.lastAt < 700, c = co.get(w.pid);
      const dots = [...w.inflight.values()].slice(0, 24).map(p => '<i class="' + (p.stale ? 'stale' : (p.kind === 'sse' ? 'sse' : (p.kind === 'http' ? '' : 'job'))) + '"></i>').join('');
      const bars = w.hist.map(n => '<i style="height:' + Math.max(1, Math.round(n / max * 14)) + 'px"></i>').join('');
      const age = Math.round((now() - w.lastAt) / 1000);
      // Coroutine occupancy: busy now out of the pool ceiling, with the peak
      // as a tick — the load of this worker at a glance, not a bare count.
      let occ = '';
      if (c && c.max > 0) {
        const pct = Math.min(100, c.num / c.max * 100), peakPct = Math.min(100, c.peak / c.max * 100);
        const lvl = pct > 75 ? ' hot' : pct > 40 ? ' warm' : '';
        const m60 = occMax60(c.pid);
        occ = '<div class="occ' + lvl + '" title="' + c.num + ' coroutines now · max ' + m60 + ' in the last 60 s · peak ' + c.peak + ' since worker start · ceiling ' + c.max + ' (' + esc(c.maxSource) + ') · snapshot ' + (c.ageS || 0) + 's old · the coroutine taking the snapshot is not counted"><div class="bar"><i style="width:' + Math.max(pct > 0 ? 1.5 : 0, pct) + '%"></i><b style="left:' + peakPct + '%"></b></div><span><strong>' + c.num + '</strong> busy <em>/ ' + fmtCount(c.max) + '</em> · 60s max ' + m60 + ' · peak ' + c.peak + '</span></div>';
      } else if (c) occ = '<div class="occ"><span>' + c.total + ' co</span></div>';
      return '<div class="w' + (active ? ' active' : '') + '"><div class="pid">w' + w.pid + '<small>' + (age < 2 ? 'now' : age < 60 ? age + 's ago' : Math.round(age / 60) + 'm ago') + '</small></div><div class="flight">' + dots + '</div><div class="n">' + [...w.inflight.values()].filter(p => !p.stale).length + '<small>in flight</small></div>' + occ + '<div class="spark">' + bars + '</div></div>';
    }).join('');
  }
  // coroutines
  const hung = hungCoroutines(); const cbox = $('#coro');
  const totalCo = S.coroutines.reduce((a, c) => a + (c.num || c.total || 0), 0), totalMax = S.coroutines.reduce((a, c) => a + (c.max || 0), 0), totalPeak = S.coroutines.reduce((a, c) => a + (c.peak || 0), 0);
  cbox.innerHTML = (S.coroutines.length ? '<div class="row"><span class="k">busy now · ' + S.coroutines.length + ' workers reporting</span><b>' + totalCo + (totalMax ? ' <em class="dim">/ ' + fmtCount(totalMax) + '</em>' : '') + '</b></div><div class="row"><span class="k">peak since worker start</span><b>' + totalPeak + '</b></div>' + S.coroutines.map(c => '<div class="row"><span class="k">w' + c.pid + '</span><b>' + (c.num || c.total) + (c.max ? ' <em class="dim">/ ' + fmtCount(c.max) + '</em>' : '') + ' <em class="dim">· peak ' + c.peak + '</em></b></div>').join('') : '<div class="row"><span class="k">coroutines</span><b class="dim">no snapshot yet</b></div>') +
    (hung.length ? hung.slice(0, 6).map(h => '<div class="hung" title="' + esc(h.frame) + '"><b>w' + h.pid + ' · cid ' + h.cid + '</b><span>' + fmtMs(h.ms) + '</span><small>' + esc(h.owner ? h.owner.name : (h.frame || 'no frame')) + (h.owner ? ' · ' + esc(h.frame) : ' · no journal process behind it') + '</small></div>').join('') + (hung.length > 6 ? '<div class="row"><span class="k">…and ' + (hung.length - 6) + ' more</span></div>' : '') : '<div class="row"><span class="k">hung (&gt; ' + HUNG_MS / 1000 + 's, not sse)</span><b class="ok">0</b></div>');
  const w60 = window60(); const cnt = k => w60.filter(f => f.kind === k).length;
  const liveSse = [...S.procs.values()].filter(p => p.kind === 'sse' && !p.stale).length;
  const stale = [...S.procs.values()].filter(p => p.stale).length;
  const retry = S.particles.filter(p => p.state === 'orbit' && p.ring === 'retry').length;
  const failed = S.particles.filter(p => p.state === 'orbit' && p.ring === 'failed').length;
  $('#bg').innerHTML =
    '<div class="row"><span class="k"><i style="background:#ffb454"></i>SSE sessions open</span><b>' + liveSse + '</b></div>' +
    '<div class="row"><span class="k"><i style="background:#c084fc"></i>scheduler runs · 60s</span><b>' + cnt('scheduler') + '</b></div>' +
    '<div class="row"><span class="k"><i style="background:#34d399"></i>queue jobs · 60s</span><b>' + cnt('queue') + '</b></div>' +
    '<div class="row"><span class="k"><i style="background:#ffb454"></i>waiting for retry</span><b>' + retry + '</b></div>' +
    '<div class="row"><span class="k"><i style="background:#ff5f6d"></i>failed · 10 min</span><b' + (failed ? ' class="bad"' : '') + '>' + failed + '</b></div>' +
    (stale ? '<div class="row"><span class="k"><i style="background:var(--faint)"></i>stale (no end line)</span><b>' + stale + '</b></div>' : '');
  renderCron(false);
}
function renderTiles() {
  const w60 = window60(); const t = now();
  const last5 = w60.filter(f => f.endedAt >= t - 5000).length / 5;
  // Latency percentiles are about requests and jobs; a session's duration is
  // how long a client stayed and would swamp p95 the moment one tab closes.
  const durs = w60.filter(f => f.kind !== 'sse').map(f => f.durationMs).filter(d => d !== null);
  const inflight = [...S.procs.values()].filter(p => !p.stale && p.kind !== 'sse').length;
  const workers = [...S.workers.values()].filter(w => t - w.lastAt < 60000).length;
  const p50 = percentile(durs, .5), p95 = percentile(durs, .95), q = qps();
  const set = (id, v, hot) => { const el = $(id); el.querySelector('b').innerHTML = v; el.classList.toggle('hot', !!hot); };
  set('#t-rps', last5.toFixed(1) + '<small>/s</small>');
  set('#t-flight', String(inflight), inflight > 8);
  set('#t-workers', String(workers));
  set('#t-p50', p50 === null ? '–' : fmtMs(p50).replace(' ', '<small>') + '</small>');
  set('#t-p95', p95 === null ? '–' : fmtMs(p95).replace(' ', '<small>') + '</small>', p95 !== null && p95 > 500);
  set('#t-q', q.toFixed(1) + '<small>/s</small>');
  const errs = w60.filter(f => f.outcome === 'exception' || f.outcome === 'failed').length;
  set('#t-err', String(errs), errs > 0);
  const hung = hungCoroutines().length; set('#t-hung', String(hung), hung > 0);
  const badge = (id, v, bad) => { const el = $(id); if (!el) return; el.textContent = v; el.hidden = v === '' || v === '0'; el.classList.toggle('bad', !!bad); };
  badge('#tb-workers', String(workers), false); badge('#tb-coro', String(hung), hung > 0); badge('#tb-cron', String(S.schedules.length), false);
  badge('#tb-system', String(S.particles.filter(p => p.state === 'orbit' && p.ring === 'failed').length), true);
}
function addTicker(fin, historic) {
  const box = $('#tlist');
  if (box.firstElementChild && box.firstElementChild.classList.contains('empty')) box.innerHTML = '';
  const el = document.createElement('div');
  // An SSE session is SUPPOSED to live long: its duration is how long the
  // client stayed, not how slow the server was, so it never reads as slow.
  const d = fin.durationMs === null ? 0 : fin.durationMs, session = fin.kind === 'sse', slow = !session && d > 1000, hot = !session && d > 200;
  el.className = 't ' + fin.kind + (slow ? ' slow' : '') + (fin.outcome !== 'ok' ? ' ' + fin.outcome : '');
  el.dataset.id = fin.id; if (historic) el.style.animation = 'none';
  const barW = d <= 0 ? 0 : session ? 0 : Math.min(100, Math.max(2, (Math.log10(1 + d) / Math.log10(30001)) * 100));
  let ph = '';
  if (fin.phases) {
    const parts = PHASE_KEYS.filter(k => typeof fin.phases[k] === 'number').map(k => [k, fin.phases[k]]);
    const sum = parts.reduce((a, [, v]) => a + v, 0) || 1;
    ph = '<div class="ph">' + parts.map(([k, v]) => '<i class="' + k + '" style="width:' + (v / sum * 100).toFixed(1) + '%" title="' + k + ' ' + fmtMs(v) + '"></i>').join('') + '</div>';
  }
  const sub = fin.phases ? ((fin.phases.by || '') + (fin.phases.q ? ' · ' + fin.phases.q + ' q' : '') + (fin.phases.queued ? ' · queued ' + fin.phases.queued : '') + (fin.phases.detail ? ' · ' + fin.phases.detail : '')) : (fin.error ? fin.error : (fin.kind === 'http' && fin.client ? fin.client : ''));
  el.innerHTML = '<div class="bar" style="width:' + barW + '%"></div>' +
    (fin.trace ? '<a class="go" href="/__trace?file=' + encodeURIComponent(fin.trace) + '" title="open waterfall"></a>' : '') +
    '<div class="kind">' + esc(fin.kind) + '</div>' +
    '<div class="name" title="' + esc(fin.name) + (fin.error ? ' — ' + esc(fin.error) : '') + '">' + esc(fin.name) + (sub ? '<small>' + esc(sub) + '</small>' : '') + ph + '</div>' +
    '<div class="w">' + (fin.worker ? 'w' + fin.worker : '') + '</div>' +
    '<div class="ms' + (slow ? ' slow' : hot ? ' hot' : session ? ' session' : '') + '">' + fmtMs(fin.durationMs) + (fin.outcome !== 'ok' ? '<small>' + esc(fin.outcome) + '</small>' : session ? '<small>session</small>' : (fin.trace ? '<small>trace →</small>' : '')) + '</div>';
  box.prepend(el);
  while (box.children.length > 60) box.lastElementChild.remove();
}
function esc(s) { return String(s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c])); }

/* ------------------------------------------------------------ tooltip */
function nodeAt(wx, wy) {
  const N = world.nodes;
  for (const s of STAGES) { const n = N[s.key]; if (Math.abs(wx - n.x) <= n.w / 2 + 4 && Math.abs(wy - n.y) <= n.h / 2 + 4) return {key: s.key, stage: s, n}; }
  for (const k of ['db', 'cron', 'queue', 'run', 'done']) { const n = N[k]; if (Math.abs(wx - n.x) <= n.w / 2 && Math.abs(wy - n.y) <= n.h / 2) return {key: k, n}; }
  for (const k of ['live', 'retry', 'failed', 'src:human', 'src:bot', 'src:api']) { const n = N[k]; if (Math.hypot(wx - n.x, wy - n.y) <= n.r) return {key: n.key, n}; }
  return null;
}
function tipHtml(hit) {
  const w60 = window60();
  if (hit.stage) {
    const s = hit.stage; const st = s.phase && S.stats[s.phase]; let nums = '', list = '';
    if (st) { const total = PHASE_KEYS.reduce((a, k) => a + (S.stats[k] ? S.stats[k].ema || 0 : 0), 0) || 1;
      nums = '<div class="nums"><div><b>' + fmtMs(st.ema) + '</b><span>avg now</span></div><div><b>' + fmtMs(percentile(st.samples, .95)) + '</b><span>p95</span></div><div><b>' + Math.round((st.ema || 0) / total * 100) + '%</b><span>of pipeline</span></div></div>'; }
    else if (s.phase) nums = '<p><em>No phase data yet — switch on <b>stage</b> to record every request, or add <code>?__trace=1</code> to one.</em></p>';
    if (s.key === 'handler') { const top = [...S.handlers.entries()].sort((a, b) => b[1] - a[1]).slice(0, 6); if (top.length) list = '<ul>' + top.map(([n, c]) => '<li><b>' + esc(n) + '</b><span>×' + c + '</span></li>').join('') + '</ul>'; }
    if (s.key === 'listeners' && w60.length) { const ns = w60.map(f => f.phases && f.phases.n).filter(Boolean); if (ns.length) list = '<ul><li><b>listeners per request</b><span>' + (ns.reduce((a, b) => a + b, 0) / ns.length).toFixed(1) + '</span></li></ul>'; }
    if (s.key === 'events') { const qd = w60.reduce((a, f) => a + ((f.phases && f.phases.queued) || 0), 0); list = '<ul><li><b>queued from here · 60s</b><span>' + qd + '</span></li></ul>'; }
    if (s.key === 'client' || s.key === 'response') { const byKind = {}; for (const f of w60) byKind[f.kind] = (byKind[f.kind] || 0) + 1; list = '<ul>' + Object.entries(byKind).map(([k, c]) => '<li><b>' + k + '</b><span>' + c + ' / 60s</span></li>').join('') + '</ul>'; }
    return '<h4>' + esc(s.title) + '<code>' + (s.phase ? 'span ' + esc(spanName(s.phase)) : esc(s.label.toLowerCase())) + '</code></h4><p>' + esc(s.desc) + '</p>' + nums + list;
  }
  const side = SIDE[hit.key]; let extra = '';
  if (hit.key === 'db') { const qs = w60.map(f => f.phases && f.phases.q).filter(Boolean), qms = w60.map(f => f.phases && f.phases.qms).filter(Boolean); extra = '<div class="nums"><div><b>' + qps().toFixed(1) + '</b><span>q / s</span></div><div><b>' + (qs.length ? (qs.reduce((a, b) => a + b, 0) / qs.length).toFixed(1) : '–') + '</b><span>q / request</span></div><div><b>' + (qms.length ? fmtMs(qms.reduce((a, b) => a + b, 0) / qms.length) : '–') + '</b><span>db ms / req</span></div></div>'; }
  if (hit.key === 'live') { const open = [...S.procs.values()].filter(p => p.kind === 'sse'); extra = '<ul>' + open.slice(0, 8).map(p => '<li><b>' + esc(p.name) + '</b><span>' + (p.stale ? 'stale' : 'w' + p.worker) + '</span></li>').join('') + (open.length > 8 ? '<li><span>+' + (open.length - 8) + ' more</span></li>' : '') + '</ul>'; }
  if (hit.key === 'retry') { const ps = S.particles.filter(p => p.state === 'orbit' && p.ring === 'retry'); extra = ps.length ? '<ul>' + ps.slice(0, 8).map(p => '<li><b>' + esc(p.name) + '</b><span>attempt ' + (p.attempt || 1) + ' · ' + esc((p.error || '').slice(0, 60)) + '</span></li>').join('') + '</ul>' : '<p><em>Nothing is waiting.</em></p>'; }
  if (hit.key === 'failed') { const fs = S.failures.slice(-10).reverse(); extra = fs.length ? '<ul>' + fs.map(f => '<li><b>' + esc(f.name) + '</b><span>' + fmtAge((now() - f.at) / 1000) + ' ago</span></li><li class="why">' + esc(f.error) + '</li>').join('') + '</ul>' : '<p><em>No failures in the last 10 minutes.</em></p>'; }
  if (hit.key === 'cron') { extra = S.schedules.length ? '<ul>' + S.schedules.slice(0, 10).map(s => '<li><b>' + esc(s.key) + '</b><span>' + esc(s.cron) + '</span></li>').join('') + '</ul>' : ''; }
  if (hit.key === 'queue' || hit.key === 'run' || hit.key === 'done') { const jobs = w60.filter(f => isJob(f.kind)); extra = jobs.length ? '<ul>' + jobs.slice(-6).reverse().map(f => '<li><b>' + esc(f.name) + '</b><span>' + f.kind + ' · ' + fmtMs(f.durationMs) + (f.outcome !== 'ok' ? ' · ' + f.outcome : '') + '</span></li>').join('') + '</ul>' : '<p><em>Nothing ran in the last minute.</em></p>'; }
  if (hit.key === 'human' || hit.key === 'bot' || hit.key === 'api') { const agents = new Map(); for (const p of S.finished.slice(-400)) if (p.kind === 'http' && p.client === hit.key) agents.set(p.route, (agents.get(p.route) || 0) + 1); const top = [...agents.entries()].sort((a, b) => b[1] - a[1]).slice(0, 6); extra = '<div class="nums"><div><b>' + (S.clients[hit.key] || 0) + '</b><span>since open</span></div><div><b>' + w60.filter(f => f.kind === 'http' && f.client === hit.key).length + '</b><span>last 60s</span></div></div>' + (top.length ? '<ul>' + top.map(([n, c]) => '<li><b>' + esc(n) + '</b><span>×' + c + '</span></li>').join('') + '</ul>' : ''); }
  return '<h4>' + esc(side.title) + '</h4><p>' + esc(side.desc) + '</p>' + extra;
}
function spanName(phase) { return {gate: 'auth.pre_hydration_gate', hydrate: 'payload.hydrate_and_validate', resolve: 'resource.resolve', auth: 'pipeline.auth_check', listeners: 'pipeline.listener', handler: 'pipeline.handler', completed: 'pipeline.handler_completed', render: 'response.render'}[phase] || phase; }
function showTip(hit, pinned) {
  const tip = $('#tip'); tip.innerHTML = tipHtml(hit); tip.classList.add('show'); tip.classList.toggle('pinned', !!pinned);
  const sp = toScreen(hit.n.x, hit.n.y + (hit.n.r || hit.n.h / 2)); const box = $('#river').getBoundingClientRect(); const tw = tip.offsetWidth, th = tip.offsetHeight;
  let lx = sp.x + 14, ly = sp.y + 10; if (lx + tw > box.width - 8) lx = sp.x - tw - 14; if (ly + th > box.height - 8) ly = Math.max(8, sp.y - th - 40);
  tip.style.left = Math.max(8, lx) + 'px'; tip.style.top = ly + 'px';
}
function hideTip() { if (S.pinned) return; $('#tip').classList.remove('show'); S.hover = null; }

/* ------------------------------------------------------------ controls */
function setLed(state, why) {
  const d = $('#led'); d.className = 'dot' + (S.paused ? ' paused' : state === 'off' ? ' off' : '');
  const via = T.mode === 'sse' ? 'SSE stream' : T.mode === 'polling' ? 'polling 250 ms (SSE unavailable)' : 'connecting…';
  d.title = state === 'off' ? ('feed unreachable: ' + (why || '')) : 'following the journal over ' + via;
  $('#meta').textContent = S.paused ? 'paused' : state === 'off' ? 'feed unreachable — retrying' : (S.stage ? 'stage mode · every request records its phases' : 'live · begin/end of every process; phases for traced requests');
  const tr = $('#transport'); if (tr) { tr.textContent = T.mode === 'sse' ? 'sse' : T.mode === 'polling' ? 'poll' : '…'; tr.className = 'tr ' + T.mode; tr.title = via; }
}
async function refreshStage() { try { const r = await fetch('/__observatory/stage', {cache: 'no-store'}); const d = await r.json(); S.stage = !!d.stage; S.stageAvailable = !!d.available; } catch (e) { } paintStage(); }
async function toggleStage() { if (!S.stageAvailable) return; try { const r = await fetch('/__observatory/stage', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'on=' + (S.stage ? 0 : 1)}); const d = await r.json(); S.stage = !!d.stage; } catch (e) { } paintStage(); }
function paintStage() { const b = $('#b-stage'); b.classList.toggle('on', S.stage); b.disabled = !S.stageAvailable; b.title = S.stageAvailable ? 'Record a phase breakdown for EVERY request (dev only, nothing written to var/trace, lapses after 12 h)' : 'Stage mode needs APP_ENV=dev'; setLed(S.pollFail ? 'off' : 'ok'); }
function toggleDemo() {
  if (S.demo) { clearTimeout(S.demo); S.demo = null; $('#b-demo').classList.remove('on'); return; }
  $('#b-demo').classList.add('on');
  const fire = () => { if (!S.demo) return; const paths = S.recentPaths.length ? S.recentPaths : ['/']; const path = paths[Math.floor(Math.random() * paths.length)];
    fetch(path, {cache: 'no-store', credentials: 'same-origin', headers: {'X-Observatory-Demo': '1'}}).catch(() => {}); S.demo = setTimeout(fire, 180 + Math.random() * 520); };
  S.demo = setTimeout(fire, 0);
}
function setSpeed(v) { S.speed = v; document.querySelectorAll('#speed button').forEach(b => b.classList.toggle('on', +b.dataset.v === v)); }
function togglePause() { S.paused = !S.paused; if (S.paused && T.es) { T.es.close(); T.es = null; T.mode = 'connecting'; } $('#b-pause').classList.toggle('on', S.paused); $('#b-pause').querySelector('span').textContent = S.paused ? 'resume' : 'pause'; setLed(S.pollFail ? 'off' : 'ok'); if (!S.paused) schedule(0); }
function toggleExplain() { S.explain = !S.explain; $('#b-explain').classList.toggle('on', S.explain); }
function fullscreen() { if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen().catch(() => {}); }

function bindView(rc) {
  const local = e => { const r = rc.getBoundingClientRect(); return {x: e.clientX - r.left, y: e.clientY - r.top}; };
  rc.addEventListener('wheel', e => { e.preventDefault(); const l = local(e); if (e.ctrlKey || !e.shiftKey && Math.abs(e.deltaY) >= Math.abs(e.deltaX)) zoomAt(l.x, l.y, Math.pow(1.0015, -e.deltaY)); else { S.view.x -= e.deltaX || e.deltaY; clampView(); } }, {passive: false});
  rc.addEventListener('pointerdown', e => { rc.setPointerCapture(e.pointerId); const l = local(e); S.pointers.set(e.pointerId, l);
    if (S.pointers.size === 2) { const [a, b] = [...S.pointers.values()]; S.pinch = {d: Math.hypot(a.x - b.x, a.y - b.y), s: S.view.s, cx: (a.x + b.x) / 2, cy: (a.y + b.y) / 2}; S.drag = null; }
    else S.drag = {sx: l.x, sy: l.y, vx: S.view.x, vy: S.view.y, moved: false}; });
  rc.addEventListener('pointermove', e => { const l = local(e);
    if (S.pointers.has(e.pointerId)) S.pointers.set(e.pointerId, l);
    if (S.pinch && S.pointers.size === 2) { const [a, b] = [...S.pointers.values()]; const d = Math.hypot(a.x - b.x, a.y - b.y); zoomAt(S.pinch.cx, S.pinch.cy, (S.pinch.s * d / S.pinch.d) / S.view.s); return; }
    if (S.drag) { const dx = l.x - S.drag.sx, dy = l.y - S.drag.sy; if (Math.hypot(dx, dy) > 3) S.drag.moved = true; if (S.drag.moved) { S.view.x = S.drag.vx + dx; S.view.y = S.drag.vy + dy; clampView(); rc.style.cursor = 'grabbing'; } return; }
    const w = toWorld(l.x, l.y), hit = nodeAt(w.x, w.y); rc.style.cursor = hit ? 'pointer' : 'grab'; if (S.pinned) return; if (hit) { S.hover = hit.key; showTip(hit, false); } else hideTip(); });
  const up = e => { S.pointers.delete(e.pointerId); if (S.pointers.size < 2) S.pinch = null;
    if (S.drag) { const wasClick = !S.drag.moved; S.drag = null; rc.style.cursor = 'grab'; if (wasClick) { const l = local(e), w = toWorld(l.x, l.y), hit = nodeAt(w.x, w.y);
      if (S.pinned && (!hit || hit.key === S.pinned.key)) { S.pinned = null; $('#tip').classList.remove('show', 'pinned'); return; } if (hit) { S.pinned = hit; showTip(hit, true); } } } };
  rc.addEventListener('pointerup', up); rc.addEventListener('pointercancel', up);
  rc.addEventListener('dblclick', () => fitView());
  rc.addEventListener('mouseleave', hideTip);
}

/* ------------------------------------------------------------ boot */
function boot() {
  // Dev tool: the state is inspectable from the console on purpose.
  window.__observatory = {S, world};
  world.canvas = $('#river-canvas'); world.ctx = world.canvas.getContext('2d');
  tl.canvas = $('#tl-canvas'); tl.ctx = tl.canvas.getContext('2d');
  readTheme(); layout(); layoutTl();
  new ResizeObserver(() => { layout(); layoutTl(); }).observe($('#river'));
  new ResizeObserver(() => layoutTl()).observe($('#time'));
  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', readTheme);
  bindView(world.canvas);
  $('#b-stage').addEventListener('click', toggleStage); $('#b-demo').addEventListener('click', toggleDemo); $('#b-pause').addEventListener('click', togglePause);
  $('#b-explain').addEventListener('click', toggleExplain); $('#b-full').addEventListener('click', fullscreen); $('#b-fit').addEventListener('click', fitView);
  $('#z-in').addEventListener('click', () => zoomAt(world.W / 2, world.H / 2, 1.25)); $('#z-out').addEventListener('click', () => zoomAt(world.W / 2, world.H / 2, 1 / 1.25)); $('#z-fit').addEventListener('click', fitView);
  document.querySelectorAll('.pan button').forEach(b => b.addEventListener('click', () => { const d = 120; S.view.x += +b.dataset.x * d; S.view.y += +b.dataset.y * d; clampView(); }));
  document.querySelectorAll('.tabs button').forEach(b => b.addEventListener('click', () => { document.querySelectorAll('.tabs button').forEach(x => x.classList.toggle('on', x === b)); document.querySelectorAll('.pane').forEach(pn => pn.hidden = pn.id !== 'pane-' + b.dataset.tab); }));
  document.querySelectorAll('#speed button').forEach(b => b.addEventListener('click', () => setSpeed(+b.dataset.v)));
  document.addEventListener('keydown', e => {
    if (e.target && /input|textarea/i.test(e.target.tagName)) return;
    if (e.key === ' ') { e.preventDefault(); togglePause(); }
    else if (e.key === 's') toggleStage(); else if (e.key === 'd') toggleDemo(); else if (e.key === 'f') fullscreen(); else if (e.key === 'e') toggleExplain(); else if (e.key === '0') fitView();
    else if (e.key === '1') setSpeed(1); else if (e.key === '2') setSpeed(0.5); else if (e.key === '3') setSpeed(0.25);
    else if (e.key === '+' || e.key === '=') zoomAt(world.W / 2, world.H / 2, 1.2); else if (e.key === '-') zoomAt(world.W / 2, world.H / 2, 1 / 1.2);
    else if (e.key.startsWith('Arrow')) { e.preventDefault(); const d = 80; S.view.x += e.key === 'ArrowLeft' ? d : e.key === 'ArrowRight' ? -d : 0; S.view.y += e.key === 'ArrowUp' ? d : e.key === 'ArrowDown' ? -d : 0; clampView(); }
  });
  refreshStage(); setInterval(refreshStage, 15000);
  loadSchedules(); setInterval(loadSchedules, 60000);
  schedule(0);
  let lastPanels = 0;
  const frame = t => {
    if (!document.hidden) {
      drawRiver(t);
      if (t - lastPanels > 400) { lastPanels = t; renderWorkers(); renderTiles(); drawTimeline(t); if (S.pinned) showTip(S.pinned, true); }
    }
    requestAnimationFrame(frame);
  };
  requestAnimationFrame(frame);
}
document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', boot) : boot();
})();

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
  redisCache: {title:'Redis Cache', desc:'The CacheManager service: scoped values, tags and single-flight coordination. Drawn because CACHE_DRIVER=redis; the default is array, per-worker and coroutine-safe, and this framework\u2019s own doctor warns against redis under Swoole. Dashed because it is drawn from configuration: the journal records no span when a value is cached, so this says how the system is wired, not what it did.'},
  redis:  {title:'Redis', desc:'Here as the store behind the cache, and only that. It used to claim SSE sessions and scope invalidation too \u2014 those live in semitexa/ssr, which this package does not depend on and cannot ask, so the claim went rather than being left unverifiable. Dashed: topology, not measurement.'},
  nats:   {title:'NATS / JetStream', desc:'The asynchronous queue boundary: events leave the request here and queue workers consume them. Drawn only when it is the resolved transport \u2014 EVENTS_ASYNC defaults to 0, which means in-memory and no boundary at all, and with async on but no NATS installed the transport is the database. Dashed: publish and consume timings are not recorded as spans.'},
  refused:{title:'Refused', desc:'Requests the pipeline turned away instead of serving — outcome "rejected", written by the request.short_circuit mark with the reason the refusing stage gave. A refusal is not a failure: nothing broke, the system declined. They leave the river where they were stopped rather than flying on to RESPONSE, because a refused request never reached one. Windowed to 60 s, like every other rate here — a gated site refuses constantly, and a ring that only ever grew would stop being readable by lunchtime.'},
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
  cursor: null, paused: false, speed: 1, stage: false, stageAvailable: true, explain: false, cinematic: false,
  procs: new Map(), finished: [], particles: [], flashes: [], sparks: [], impulses: [],
  lastRowAt: 0, pollFail: 0,
  workers: new Map(), stats: {}, handlers: new Map(), recentPaths: [], demo: null, pinned: null, hover: null,
  clients: {human: 0, bot: 0, api: 0},
  schedules: [], scheduleByClass: new Map(), coroutines: [],
  failures: [], // {name, kind, error, at, attempt}
  heroId: null,
  // What THIS project runs, read from the page rather than assumed. The panel
  // is opened by people who do not yet know how the system is wired, so a box
  // naming a product is a claim, and every claim here has to be earned.
  // ObservatoryTopology resolves both: the transport through core's own
  // QueueConfig, the driver as an explicit opt-in that is absent by default.
  topology: {queueTransport: 'in-memory', cacheDriver: null},
  logs: new Map(), // span name -> {state:'loading'|'ok'|'denied'|'error', lines, reason}
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
    resetDerived();
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
      error: ctx.error || null, retry: ctx.retry === true, attempt: ctx.attempt || (open && open.attempt) || 1, schedule: ctx.schedule || null,
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
// A bootstrap batch REPLACES the picture; it does not extend it. The reader
// sends one when the cursor is stale, the journal rotated, or catch-up grew
// past its cap, and its rows include ends the page may already have counted.
// Keeping the old derived state double-counted the ticker, the rates and the
// percentiles, and left worker dots for processes that ended long ago.
// Raised in review of semitexa-dev#78.
function resetDerived() {
  S.procs.clear();
  S.particles.length = 0;
  S.finished.length = 0;
  S.failures.length = 0;
  S.heroId = null;
  S.flashes.length = 0;
  S.sparks.length = 0;
  S.impulses.length = 0;
  S.workers.clear();
  occHist.clear();
  S.stats = {};
  S.handlers.clear();
  S.clients = {human: 0, bot: 0, api: 0};
  for (const s of S.schedules) { s.lastRunAt = 0; s.runs = 0; }
  const box = $('#tlist');
  if (box) box.innerHTML = '<div class="empty">Reconnected — rebuilding from the journal…</div>';
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
    method: rec.method || '', path: rec.path || '', agent: rec.agent || '', born: now(), wps: [], trail: [], state: 'moving', fin: null, dead: false,
    angle: Math.random() * Math.PI * 2, size: rec.kind === 'sse' ? 4.5 : 5, ring: null, lastPosition: null};
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
  if (fin.outcome === 'rejected') { planRefused(p, fin, from); return; }
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
// A refused request stops where it was refused and turns up into REFUSED.
//
// WHERE it stops is read, not assumed. request.short_circuit is not the gate's
// private mark — a resource or an auth check can raise it too — so the exit is
// the last stage that recorded a phase, and only a refusal with no phases at
// all falls back to the gate. Drawing every refusal as a gate refusal would be
// the same class of lie as drawing a transport nobody runs.
function planRefused(p, fin, from) {
  const ph = fin.phases || {};
  let stop = IDX.gate;
  for (let i = 0; i < STAGES.length; i++) { const s = STAGES[i]; if (s.phase && typeof ph[s.phase] === 'number') stop = i; }
  const start = Math.max(0, from);
  const T = travelMs(fin.durationMs);
  const hops = Math.max(1, stop - start + (from < 0 ? 1 : 0));
  const move = T * 0.5 / hops;
  let t = 0; const wps = [];
  if (from < 0) { wps.push({pt: srcKey(p), t: 0}, {pt: 'trunk:' + p.client, t: move * 0.45}, {pt: 'trunk_in', t: move * 0.7}); t += move; }
  for (let i = start; i <= stop; i++) { wps.push({pt: STAGES[i].key, t}); if (i < stop) t += move; }
  // The turn upwards is deliberately slower than the run in: the moment of
  // refusal is the only thing worth watching in this particle's whole life.
  const turn = Math.max(420, T * 0.5) / S.speed;
  wps.push({pt: 'refused_in', t: t + turn * 0.6}, {pt: 'refused', t: t + turn});
  p.wps = wps; p.born = now(); p.state = 'toOrbit'; p.ring = 'refused'; p.color = '#ffb454'; p.total = t + turn;
  p.reason = (fin.phases && fin.phases.detail) || null;
  p.refusedAt = STAGES[stop].label.toLowerCase();
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
  // Two ways to earn a retry: a scheduler run with attempts left under its
  // schedule, or a queue message the worker requeued (the end line says so).
  const retriable = fin.retry === true || (p.kind === 'scheduler' && sched && (fin.attempt || 1) < sched.maxAttempts);
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
  //  - infrastructure sits on a separate rail below the request pipeline;
  //  - the enqueue impulse enters NATS, then runs left to QUEUE;
  //  - the jobs lane flows left to right below everything else.
  N.live = {x: N.listeners.x, y: y - 160, r: 50, side: 'live', key: 'live'};
  // REFUSED sits above GATE at the same height as LIVE, and INSIDE the system
  // boundary: a refusal is something Semitexa did, not something that never
  // arrived. Its one vertical link runs straight up from GATE, which nothing
  // else crosses — the return lane passes above the boundary, not through it.
  N.refused = {x: N.gate.x, y: y - 160, r: 46, side: 'refused', key: 'refused'};
  N.db = {x: N.handler.x, y: y + 104, w: 82, h: 46, side: 'db', key: 'db'};
  N.redis = {x: N.resource.x, y: y + 104, w: 108, h: 46, side: 'redis', key: 'redis', label: 'REDIS', sub: 'cache store', color: '#ef4444'};
  N.redisCache = {x: N.auth.x, y: y + 104, w: 116, h: 46, side: 'redisCache', key: 'redisCache', label: 'REDIS CACHE', sub: 'scopes · tags', color: '#f97316'};
  N.nats = {x: N.events.x, y: y + 104, w: 104, h: 46, side: 'nats', key: 'nats', label: 'NATS', sub: 'JetStream', color: '#22d3ee'};
  // Every route is a Manhattan path: horizontal or vertical, never slanted.
  //   sources  → trunk at trunkX → ROUTER
  //   RESPONSE → right riser → lane above LIVE → left column → icons
  //   EVENTS   ↓ NATS ↓ bus ← left ↓ into QUEUE from its LEFT
  //   CRON/QUEUE → job trunk on their RIGHT → jobs row → RUN → DONE
  //   RUN ↓ under DONE and RETRY → up into RETRY or FAILED
  const laneY = y - 252, busY = y + 160, jy = y + 250, underY = jy + 84;
  N.cron  = {x: x0 + 10, y: jy - 38, w: 92, h: 34, side: 'cron', key: 'cron', label: 'CRON'};
  N.queue = {x: x0 + 10, y: jy + 38, w: 92, h: 34, side: 'queue', key: 'queue', label: 'QUEUE'};
  N.run   = {x: N.resource.x, y: jy, w: 84, h: 36, side: 'run', key: 'run', label: 'RUN'};
  N.done  = {x: N.auth.x, y: jy, w: 84, h: 36, side: 'done', key: 'done', label: 'DONE'};
  N.retry = {x: N.handler.x - gap * 0.35, y: jy, r: 46, side: 'retry', key: 'retry'};
  N.failed = {x: N.render.x - gap * 0.3, y: jy, r: 46, side: 'failed', key: 'failed'};
  for (const k of ['src:human', 'src:bot', 'src:api', 'live', 'refused', 'db', 'redis', 'redisCache', 'nats', 'cron', 'queue', 'run', 'done', 'retry', 'failed']) world.pts[k] = {x: N[k].x, y: N[k].y};
  world.pts.refused_in = {x: N.refused.x, y: N.refused.y + N.refused.r};
  const colX = 30, trunkX = N.router.x - N.router.w / 2 - 44, riserX = N.response.x + N.response.w / 2 + 40;
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
    const speed = p.ring === 'live' ? 0.6 : p.ring === 'retry' ? 1.1 : p.ring === 'refused' ? 0.35 : 0.05;
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
function alongBus(k) { // EVENTS → NATS → bus → QUEUE, parameterised by length
  const pts = [world.pts.events, ...(hasNats() ? [world.pts.nats] : []), world.pts.bus_a, world.pts.bus_b, world.pts.bus_c, world.pts.queue_in, world.pts.queue];
  const seg = []; let L = 0; for (let i = 0; i < pts.length - 1; i++) { const l = Math.hypot(pts[i + 1].x - pts[i].x, pts[i + 1].y - pts[i].y); seg.push(l); L += l; }
  let d = k * L; for (let i = 0; i < seg.length; i++) { if (d <= seg[i] || i === seg.length - 1) { const f = seg[i] ? Math.min(1, d / seg[i]) : 1; return {x: pts[i].x + (pts[i + 1].x - pts[i].x) * f, y: pts[i].y + (pts[i + 1].y - pts[i].y) * f}; } d -= seg[i]; }
  return pts[pts.length - 1];
}
function bez(a, b, k) { // vertical S-curve between two points, parameter k in [0,1]
  const c1 = {x: a.x, y: a.y + (b.y - a.y) * 0.5}, c2 = {x: b.x, y: a.y + (b.y - a.y) * 0.5}; const m = 1 - k;
  return {x: m * m * m * a.x + 3 * m * m * k * c1.x + 3 * m * k * k * c2.x + k * k * k * b.x, y: m * m * m * a.y + 3 * m * m * k * c1.y + 3 * m * k * k * c2.y + k * k * k * b.y};
}

function drawSignalField(ctx, t, B) {
  ctx.save(); rr(ctx, B.x, B.y, B.w, B.h, 22); ctx.clip();
  const glow = ctx.createRadialGradient(B.x + B.w * .52, world.y, 20, B.x + B.w * .52, world.y, B.w * .58);
  glow.addColorStop(0, S.cinematic ? 'rgba(48,144,255,.12)' : 'rgba(91,157,255,.045)'); glow.addColorStop(1, 'rgba(91,157,255,0)');
  ctx.fillStyle = glow; ctx.fillRect(B.x, B.y, B.w, B.h);
  const step = 64, drift = (t / 90) % step; ctx.lineWidth = 1;
  ctx.strokeStyle = S.cinematic ? 'rgba(74,151,255,.075)' : 'rgba(91,157,255,.035)'; ctx.beginPath();
  for (let x = B.x - step + drift; x < B.x + B.w + step; x += step) { ctx.moveTo(x, B.y); ctx.lineTo(x, B.y + B.h); }
  for (let y = B.y; y < B.y + B.h; y += step) { ctx.moveTo(B.x, y); ctx.lineTo(B.x + B.w, y); }
  ctx.stroke();
  ctx.fillStyle = S.cinematic ? 'rgba(77,174,255,.42)' : 'rgba(91,157,255,.18)';
  for (let x = B.x + drift; x < B.x + B.w; x += step * 2) for (let y = B.y + step; y < B.y + B.h; y += step * 2) { ctx.beginPath(); ctx.arc(x, y, 1.2, 0, 7); ctx.fill(); }
  ctx.restore();
}

function drawRiver(t) {
  const ctx = world.ctx, W = world.W, H = world.H, y = world.y, N = world.nodes, v = S.view;
  ctx.clearRect(0, 0, W, H);
  const occ = {}, pos = new Map();
  for (const p of S.particles) { const at = positionOf(p, t); pos.set(p, at); if (at.node && !at.ring) occ[at.node] = (occ[at.node] || 0) + 1; }
  const ringCount = {live: 0, retry: 0, failed: 0, refused: 0};
  for (const p of S.particles) if (p.state === 'orbit' && p.ring) ringCount[p.ring]++;

  ctx.save(); ctx.translate(v.x, v.y); ctx.scale(v.s, v.s);
  ctx.font = '600 10px ' + theme.mono;

  const B = world.box;
  if (S.cinematic) drawSignalField(ctx, t, B);
  // river bed + flowing dashes
  const xA = N.router.x - 40, xB = N.response.x + 40;
  ctx.lineCap = 'round'; ctx.lineWidth = 10;
  const g = ctx.createLinearGradient(xA, 0, xB, 0); g.addColorStop(0, 'rgba(91,157,255,.10)'); g.addColorStop(1, 'rgba(91,157,255,.22)');
  ctx.strokeStyle = g; ctx.beginPath(); ctx.moveTo(xA, y); ctx.lineTo(xB, y); ctx.stroke();
  ctx.save(); ctx.setLineDash([6, 18]); ctx.lineDashOffset = -(t / 30) % 24; ctx.lineWidth = 2; ctx.strokeStyle = 'rgba(91,157,255,.35)';
  ctx.beginPath(); ctx.moveTo(xA, y); ctx.lineTo(xB, y); ctx.stroke(); ctx.restore();
  // the system boundary
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
  // connections: the three concrete client types merge directly into ROUTER
  const tk = world.pts.trunk_in;
  ctx.save(); ctx.strokeStyle = 'rgba(91,157,255,.22)'; ctx.lineWidth = 3; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.beginPath();
  for (const k of ['human', 'bot', 'api']) { const sN = N['src:' + k], tp = world.pts['trunk:' + k]; ctx.moveTo(sN.x + sN.r, sN.y); ctx.lineTo(tp.x, tp.y); }
  ctx.moveTo(tk.x, N['src:human'].y); ctx.lineTo(tk.x, N['src:api'].y); ctx.moveTo(tk.x, y); ctx.lineTo(N.router.x - N.router.w / 2, y); ctx.stroke(); ctx.restore();
  // infrastructure topology: cache is a service over Redis; live state and
  // invalidation also use Redis; asynchronous events cross NATS.
  vlink(ctx, N.handler.x, y + N.handler.h / 2, N.db.y - N.db.h / 2, 'rgba(245,158,11,.35)');
  vlink(ctx, N.listeners.x, y - N.listeners.h / 2, N.live.y + N.live.r, 'rgba(255,180,84,.30)');
  // REFUSED gets no static link, deliberately. A line drawn up from one stage
  // would claim refusals come from THAT stage, and they do not: today the only
  // request.short_circuit is raised by validation, so every refusal leaves at
  // HYDRATE, not at the GATE the ring sits above — and a later stage could
  // raise it tomorrow. RETRY and FAILED already work this way; what connects a
  // ring to the river is the path each particle actually takes into it.
  // The LISTENERS -> REDIS link that used to sit here is gone, and its absence
  // is the point. It claimed SSE sessions and scope invalidation ride Redis,
  // which lives in semitexa/ssr - a package this one does not depend on and
  // cannot interrogate. It was also the only crossing in the picture: its
  // horizontal run at y+70 passed under the CacheManager vertical, in a file
  // whose layout comment promises nothing crosses anything. Removing the claim
  // removed the crossing; there was never a version of it that was both true
  // and untangled.
  if (hasRedisCache()) {
    routeLink(ctx, [[N.handler.x, y + N.handler.h / 2], [N.handler.x, y + 58], [N.redisCache.x, y + 58], [N.redisCache.x, N.redisCache.y - N.redisCache.h / 2]], 'rgba(249,115,22,.34)');
    routeLink(ctx, [[N.redisCache.x - N.redisCache.w / 2, N.redisCache.y], [N.redis.x + N.redis.w / 2, N.redis.y]], 'rgba(239,68,68,.35)');
  }
  if (hasNats()) vlink(ctx, N.events.x, y + N.events.h / 2, N.nats.y - N.nats.h / 2, 'rgba(34,211,238,.36)');
  const ba = world.pts.bus_a, bb = world.pts.bus_b, bc = world.pts.bus_c, qi = world.pts.queue_in;
  ctx.save(); ctx.strokeStyle = 'rgba(52,211,153,.22)'; ctx.lineWidth = 5; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.beginPath();
  ctx.moveTo(N.events.x, hasNats() ? N.nats.y + N.nats.h / 2 : y + N.events.h / 2); ctx.lineTo(ba.x, ba.y); ctx.lineTo(bb.x, bb.y); ctx.lineTo(bc.x, bc.y); ctx.lineTo(qi.x, qi.y); ctx.stroke(); ctx.restore();
  ctx.fillStyle = theme.faint; ctx.font = '500 9.5px ' + theme.mono; ctx.textAlign = 'center';
  if (hasRedisCache()) {
    ctx.fillText('CacheManager', (N.handler.x + N.redisCache.x) / 2, y + 50);
    // Above the link, not on it: at y-10 this sat on the REDIS frame and on its
    // own neighbour, and two labels in the same pixels read as neither.
    ctx.fillText('store \u00b7 tags', (N.redisCache.x + N.redis.x) / 2, N.redis.y - 30);
  }
  ctx.fillText(busLabel(), (ba.x + bb.x) / 2, ba.y - 9);
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
  for (const s of STAGES) drawNode(ctx, N[s.key], occ[s.key] || 0, t);
  drawDb(ctx, N.db);
  for (const k of infraShown()) drawInfra(ctx, N[k], t);
  drawRing(ctx, N.live, ringCount.live, 'LIVE', ringCount.live + ' sse', '#ffb454', t);
  drawRing(ctx, N.refused, ringCount.refused, 'REFUSED', ringCount.refused + ' \u00b7 60s', '#ffb454', t);
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
    // Orbiters are state, not motion, so they normally outlive the sweep —
    // but REFUSED is the one ring fed by ordinary traffic. A site with a login
    // wall refuses bots all day, and a ring that only ever grew would be
    // unreadable within the hour, so a refusal ages out like everything else
    // and the ring's own label scopes itself to 60 s rather than lying.
    if ((p.state !== 'orbit' || p.ring === 'refused') && t - p.born > 60000) { S.particles.splice(i, 1); continue; }
    if (at.node === 'handler' && p.sparks) { for (let q = 0; q < p.sparks; q++) S.sparks.push({t0: t + q * 90, dur: 520 / S.speed, dx: (Math.random() - .5) * 8}); p.sparks = 0; }
    if (at.node === 'events' && p.queued) { for (let q = 0; q < Math.min(4, p.queued); q++) S.impulses.push({t0: t + q * 120, dur: 1100 / S.speed}); p.queued = 0; }
    p.lastPosition = at;
    p.trail.push({x: at.x, y: at.y}); if (p.trail.length > (S.cinematic ? 28 : 9)) p.trail.shift();
    const alpha = at.alpha === undefined ? 1 : at.alpha;
    if ((p.ring !== 'failed' || p.state !== 'orbit') && p.trail.length > 1) {
      // The ribbon is cinema's alone. A stroke with a shadowBlur is the most
      // expensive thing this loop can do, per particle per frame, and it was
      // running in the default view too — so a mode sold as opt-in was billing
      // everybody. The dotted trail below is what the picture always had.
      if (S.cinematic) {
        ctx.save(); ctx.strokeStyle = p.color; ctx.globalAlpha = alpha * .42; ctx.lineWidth = 4; ctx.lineCap = 'round';
        ctx.shadowColor = p.color; ctx.shadowBlur = 16; ctx.beginPath(); ctx.moveTo(p.trail[0].x, p.trail[0].y);
        for (let j = 1; j < p.trail.length; j++) ctx.lineTo(p.trail[j].x, p.trail[j].y); ctx.stroke(); ctx.restore();
      }
      for (let j = 0; j < p.trail.length - 1; j++) { const q = p.trail[j]; ctx.fillStyle = p.color; ctx.globalAlpha = alpha * (j / p.trail.length) * (S.cinematic ? .42 : 0.35); ctx.beginPath(); ctx.arc(q.x, q.y, p.size * (0.3 + j / p.trail.length * 0.6), 0, 7); ctx.fill(); }
    }
    ctx.globalAlpha = alpha; ctx.shadowColor = p.color; ctx.shadowBlur = S.cinematic ? 25 : 14; ctx.fillStyle = p.color; ctx.beginPath(); ctx.arc(at.x, at.y, p.size + (S.cinematic ? 1 : 0), 0, 7); ctx.fill(); ctx.shadowBlur = 0;
    if (S.cinematic && p.state !== 'orbit') { ctx.strokeStyle = p.color; ctx.globalAlpha = alpha * .35; ctx.lineWidth = 1; ctx.beginPath(); ctx.arc(at.x, at.y, p.size + 6 + Math.sin(t / 180 + p.angle) * 2, 0, 7); ctx.stroke(); }
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
function routeLink(ctx, pts, color) { ctx.save(); ctx.strokeStyle = color; ctx.lineWidth = 4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.beginPath(); ctx.moveTo(pts[0][0], pts[0][1]); for (let i = 1; i < pts.length; i++) ctx.lineTo(pts[i][0], pts[i][1]); ctx.stroke(); ctx.restore(); }
function isActive(key) { return S.hover === key || (S.pinned && S.pinned.key === key); }
function drawNode(ctx, n, occ, t) {
  const {x, y, w, h, stage} = n; const hot = Math.min(1, occ / 3), active = isActive(stage.key);
  if (occ > 0) { const wave = (t / 720) % 1; ctx.strokeStyle = 'rgba(91,157,255,' + (0.42 * (1 - wave)) + ')'; ctx.lineWidth = 2; rr(ctx, x - w / 2 - wave * 13, y - h / 2 - wave * 8, w + wave * 26, h + wave * 16, 12 + wave * 5); ctx.stroke(); }
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
function drawInfra(ctx, n, t) {
  const {x, y, w, h, color} = n, active = isActive(n.key);
  if (n.key === 'nats' && S.impulses.length) { const wave = (t / 620) % 1; ctx.strokeStyle = hexA(color, .45 * (1 - wave)); ctx.lineWidth = 2; rr(ctx, x - w / 2 - wave * 12, y - h / 2 - wave * 7, w + wave * 24, h + wave * 14, 12); ctx.stroke(); }
  ctx.fillStyle = hexA(color, active ? .16 : .07); rr(ctx, x - w / 2, y - h / 2, w, h, 10); ctx.fill();
  // DASHED, and every other box in the picture is solid. These three are the
  // only nodes drawn from configuration rather than from measurement: the
  // journal records no span when a value is cached or an event is published,
  // so their frames say "this is wired up", not "this is what happened". A
  // solid frame here would borrow the authority of the boxes that are counting.
  ctx.save(); ctx.setLineDash([4, 3]); ctx.lineDashOffset = -(t / 220) % 7;
  ctx.strokeStyle = active ? color : hexA(color, .58); ctx.lineWidth = active ? 2 : 1.2;
  rr(ctx, x - w / 2, y - h / 2, w, h, 10); ctx.stroke(); ctx.restore();
  ctx.fillStyle = color; ctx.beginPath(); ctx.arc(x - w / 2 + 11, y - h / 2 + 10, 2.5, 0, 7); ctx.fill();
  ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.font = '700 10px ' + theme.mono; ctx.fillStyle = theme.text; ctx.fillText(n.label, x, y - 6);
  ctx.font = '500 9px ' + theme.mono; ctx.fillStyle = active ? color : theme.dim; ctx.fillText(n.sub, x, y + 9);
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
/* A long-lived coroutine is one of three things, not two: a session, a stuck
   request, or standing work that said what it is waiting for. Only the middle
   one is a problem, and only it belongs on the tile — six explained coroutines
   on an idle machine teach an operator to ignore the number, and then it cannot
   warn about the seventh that is real. */
function classifyCoroutines() {
  const hung = [], standing = [];
  for (const w of S.coroutines) for (const c of w.longest || []) {
    if (c.ms < HUNG_MS) continue;
    let owner = null;
    for (const p of S.procs.values()) if (p.worker === w.pid && p.cid === c.cid) { owner = p; break; }
    if (owner && owner.kind === 'sse') continue; // a session: long by design
    const row = {pid: w.pid, cid: c.cid, ms: c.ms, frame: c.frame, owner, standing: c.standing || null};
    (row.standing ? standing : hung).push(row);
  }
  const byMs = (a, b) => b.ms - a.ms;
  return {hung: hung.sort(byMs), standing: standing.sort(byMs)};
}
function hungCoroutines() { return classifyCoroutines().hung; }
function renderWorkers() {
  const box = $('#wlist'); const ws = [...S.workers.values()].filter(w => now() - w.lastAt < 600000 || w.inflight.size).sort((a, b) => (+a.pid || 0) - (+b.pid || 0));
  const co = new Map(S.coroutines.map(c => [c.pid, c]));
  if (!ws.length) box.innerHTML = '<div class="empty">No worker has spoken yet.</div>';
  else {
    const max = Math.max(1, ...ws.map(w => Math.max(...w.hist)));
    box.innerHTML = ws.map(w => {
      const active = now() - w.lastAt < 700, c = co.get(w.pid);
      const dots = [...w.inflight.values()].slice(0, 24).map(p => '<i class="' + (p.stale ? 'stale' : (p.kind === 'sse' ? 'sse' : (p.kind === 'http' ? '' : 'job'))) + '"></i>').join('');
      const bars = w.hist.map(n => '<i class="h' + Math.max(1, Math.round(n / max * 14)) + '"></i>').join('');
      const age = Math.round((now() - w.lastAt) / 1000);
      // Coroutine occupancy: busy now out of the pool ceiling, with the peak
      // as a tick — the load of this worker at a glance, not a bare count.
      let occ = '';
      if (c && c.max > 0) {
        const pct = Math.min(100, c.num / c.max * 100), peakPct = Math.min(100, c.peak / c.max * 100);
        const lvl = pct > 75 ? ' hot' : pct > 40 ? ' warm' : '';
        const m60 = occMax60(c.pid);
        occ = '<div class="occ' + lvl + '" title="' + c.num + ' coroutines now · max ' + m60 + ' in the last 60 s · peak ' + c.peak + ' since worker start · ceiling ' + c.max + ' (' + esc(c.maxSource) + ') · snapshot ' + (c.ageS || 0) + 's old · the coroutine taking the snapshot is not counted"><div class="bar"><i class="w' + pctClass(pct, pct > 0 ? 2 : 0) + '"></i><b class="l' + pctClass(peakPct) + '"></b></div><span><strong>' + c.num + '</strong> busy <em>/ ' + fmtCount(c.max) + '</em> · 60s max ' + m60 + ' · peak ' + c.peak + '</span></div>';
      } else if (c) occ = '<div class="occ"><span>' + c.total + ' co</span></div>';
      return '<div class="w' + (active ? ' active' : '') + '"><div class="pid">w' + w.pid + '<small>' + (age < 2 ? 'now' : age < 60 ? age + 's ago' : Math.round(age / 60) + 'm ago') + '</small></div><div class="flight">' + dots + '</div><div class="n">' + [...w.inflight.values()].filter(p => !p.stale).length + '<small>in flight</small></div>' + occ + '<div class="spark">' + bars + '</div></div>';
    }).join('');
  }
  // coroutines
  const {hung, standing} = classifyCoroutines(); const cbox = $('#coro');
  const totalCo = S.coroutines.reduce((a, c) => a + (c.num || c.total || 0), 0), totalMax = S.coroutines.reduce((a, c) => a + (c.max || 0), 0), totalPeak = S.coroutines.reduce((a, c) => a + (c.peak || 0), 0);
  cbox.innerHTML = (S.coroutines.length ? '<div class="row"><span class="k">busy now · ' + S.coroutines.length + ' workers reporting</span><b>' + totalCo + (totalMax ? ' <em class="dim">/ ' + fmtCount(totalMax) + '</em>' : '') + '</b></div><div class="row"><span class="k">peak since worker start</span><b>' + totalPeak + '</b></div>' + S.coroutines.map(c => '<div class="row"><span class="k">w' + c.pid + '</span><b>' + (c.num || c.total) + (c.max ? ' <em class="dim">/ ' + fmtCount(c.max) + '</em>' : '') + ' <em class="dim">· peak ' + c.peak + '</em></b></div>').join('') : '<div class="row"><span class="k">coroutines</span><b class="dim">no snapshot yet</b></div>') +
    (hung.length ? hung.slice(0, 6).map(h => '<div class="hung" title="' + esc(h.frame) + '"><b>w' + h.pid + ' · cid ' + h.cid + '</b><span>' + fmtMs(h.ms) + '</span><small>' + esc(h.owner ? h.owner.name : (h.frame || 'no frame')) + (h.owner ? ' · ' + esc(h.frame) : ' · no journal process behind it') + '</small></div>').join('') + (hung.length > 6 ? '<div class="row"><span class="k">…and ' + (hung.length - 6) + ' more</span></div>' : '') : '<div class="row"><span class="k">hung (&gt; ' + HUNG_MS / 1000 + 's, not sse)</span><b class="ok">0</b></div>') +
    // Listed after the hung count, and never added to it: these are the ones
    // that told us why they are parked.
    (standing.length ? standing.slice(0, 6).map(h => '<div class="standing" title="' + esc(h.standing.reason) + '"><b>w' + h.pid + ' · ' + esc(h.standing.label) + '</b><span>' + fmtMs(h.ms) + '</span><small>' + esc(h.standing.reason) + '</small></div>').join('') + (standing.length > 6 ? '<div class="row"><span class="k">…and ' + (standing.length - 6) + ' more standing</span></div>' : '') : '');
  const w60 = window60(); const cnt = k => w60.filter(f => f.kind === k).length;
  const liveSse = [...S.procs.values()].filter(p => p.kind === 'sse' && !p.stale).length;
  const stale = [...S.procs.values()].filter(p => p.stale).length;
  const retry = S.particles.filter(p => p.state === 'orbit' && p.ring === 'retry').length;
  const failed = S.particles.filter(p => p.state === 'orbit' && p.ring === 'failed').length;
  $('#bg').innerHTML =
    '<div class="row"><span class="k"><i class="k-sse"></i>SSE sessions open</span><b>' + liveSse + '</b></div>' +
    '<div class="row"><span class="k"><i class="k-scheduler"></i>scheduler runs · 60s</span><b>' + cnt('scheduler') + '</b></div>' +
    '<div class="row"><span class="k"><i class="k-queue"></i>queue jobs · 60s</span><b>' + cnt('queue') + '</b></div>' +
    '<div class="row"><span class="k"><i class="k-retry"></i>waiting for retry</span><b>' + retry + '</b></div>' +
    '<div class="row"><span class="k"><i class="k-failed"></i>failed · 10 min</span><b' + (failed ? ' class="bad"' : '') + '>' + failed + '</b></div>' +
    (stale ? '<div class="row"><span class="k"><i class="k-stale"></i>stale (no end line)</span><b>' + stale + '</b></div>' : '');
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
  const p50 = percentile(durs, .5), p95 = percentile(durs, .95);
  const set = (id, v, hot, title) => { const el = $(id); el.querySelector('b').innerHTML = v; el.classList.toggle('hot', !!hot); if (title !== undefined) el.title = title; };
  set('#t-rps', last5.toFixed(1) + '<small>/s</small>');
  set('#t-flight', String(inflight), inflight > 8);
  set('#t-p50', p50 === null ? '–' : fmtMs(p50).replace(' ', '<small>') + '</small>');
  set('#t-p95', p95 === null ? '–' : fmtMs(p95).replace(' ', '<small>') + '</small>', p95 !== null && p95 > 500);
  // A share, not a tally: ten failures out of ten is a different system from
  // ten out of ten thousand, and the tally reads the same either way. An empty
  // window has no rate at all — nothing failed out of nothing is not 0.0 %, so
  // it says so rather than reporting health nobody measured. The counts the
  // old tile showed are still here, in the title.
  const errs = w60.filter(f => f.outcome === 'exception' || f.outcome === 'failed').length;
  set('#t-err',
    w60.length === 0 ? '–' : (errs / w60.length * 100).toFixed(1) + '<small>%</small>',
    errs > 0,
    w60.length === 0 ? 'nothing finished in the last 60 s' : errs + ' of ' + w60.length + ' processes failed in the last 60 s');
  const hung = hungCoroutines().length;
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
  // A class, not el.style.animation: a strict style-src refuses a CSSOM write
  // exactly as it refuses an inline <style>, and this one runs for every
  // historic row on load — five refusals before the reader has touched
  // anything.
  el.dataset.id = fin.id; el.classList.toggle('historic', !!historic);
  const barW = d <= 0 ? 0 : session ? 0 : Math.min(100, Math.max(2, (Math.log10(1 + d) / Math.log10(30001)) * 100));
  let ph = '';
  if (fin.phases) {
    const parts = PHASE_KEYS.filter(k => typeof fin.phases[k] === 'number').map(k => [k, fin.phases[k]]);
    const sum = parts.reduce((a, [, v]) => a + v, 0) || 1;
    ph = '<div class="ph">' + parts.map(([k, v]) => '<i class="' + k + ' w' + pctClass(v / sum * 100) + '" title="' + k + ' ' + fmtMs(v) + '"></i>').join('') + '</div>';
  }
  const sub = fin.phases ? ((fin.phases.by || '') + (fin.phases.q ? ' · ' + fin.phases.q + ' q' : '') + (fin.phases.queued ? ' · queued ' + fin.phases.queued : '') + (fin.phases.detail ? ' · ' + fin.phases.detail : '')) : (fin.error ? fin.error : (fin.kind === 'http' && fin.client ? fin.client : ''));
  el.innerHTML = '<div class="bar w' + pctClass(barW) + '"></div>' +
    (fin.trace ? '<a class="go" href="/__trace?file=' + encodeURIComponent(fin.trace) + '" title="open waterfall"></a>' : '') +
    '<div class="kind">' + esc(fin.kind) + '</div>' +
    '<div class="name" title="' + esc(fin.name) + (fin.error ? ' — ' + esc(fin.error) : '') + '">' + esc(fin.name) + (sub ? '<small>' + esc(sub) + '</small>' : '') + ph + '</div>' +
    '<div class="w">' + (fin.worker ? 'w' + fin.worker : '') + '</div>' +
    '<div class="ms' + (slow ? ' slow' : hot ? ' hot' : session ? ' session' : '') + '">' + fmtMs(fin.durationMs) + (fin.outcome !== 'ok' ? '<small>' + esc(fin.outcome) + '</small>' : session ? '<small>session</small>' : (fin.trace ? '<small>trace →</small>' : '')) + '</div>';
  box.prepend(el);
  while (box.children.length > 60) box.lastElementChild.remove();
}
function esc(s) { return String(s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c])); }

/* ------------------------------------------------------------ live spotlight */
function spotlightStage(p, at) {
  if (!at) return 'entering';
  if (at.returning) return 'response → client';
  if (at.ring) return String(p.ring || 'resident').toUpperCase();
  if (typeof at.node === 'string' && at.node.startsWith('src:')) return 'client';
  if (typeof at.stageIdx === 'number' && STAGES[at.stageIdx]) return STAGES[at.stageIdx].label.toLowerCase();
  if (typeof at.node === 'string') return at.node.replace(/[_:]/g, ' ');
  return 'in transit';
}
function renderSpotlight(t) {
  const box = $('#spotlight'); if (!box) return;
  let hero = S.heroId ? S.particles.find(p => p.id === S.heroId && !p.dead && p.state !== 'orbit') : null;
  if (!hero) {
    // OLDEST first, and on purpose. Under a burst it holds one process for its
    // whole journey instead of flicking between them, and the particle that
    // stays oldest longest is a request that is not finishing — which is the
    // one worth watching. Sticky via heroId until it dies, for the same reason.
    hero = S.particles.filter(p => !p.dead && p.state !== 'orbit').sort((a, b) => a.born - b.born)[0] || null;
    S.heroId = hero ? hero.id : null;
  }
  if (!hero) {
    box.className = 'spotlight idle'; $('#spot-status').textContent = 'live process'; $('#spot-stage').textContent = 'waiting';
    $('#spot-title').textContent = 'Waiting for the next process…'; $('#spot-route').textContent = 'Every light is backed by the process journal.';
    $('#spot-kind').textContent = '—'; $('#spot-worker').textContent = 'worker —'; $('#spot-time').textContent = '—'; $('#spot-ph').innerHTML = '';
    return;
  }
  // hero.lastPosition ONLY. The fallback here used to be positionOf(), which
  // is not a query: it advances p.state and can set p.dead. A renderer that
  // moves the simulation it is describing, at a different t from the draw
  // loop, is a bug waiting for the frame where those two t values disagree.
  // A particle drawn at least once has lastPosition; one that has not been
  // drawn yet simply has no stage to report for another 16 ms.
  const at = hero.lastPosition, fin = hero.fin, ph = fin && fin.phases;
  box.className = 'spotlight ' + hero.kind; $('#spot-status').textContent = fin ? 'process journey' : 'live process';
  $('#spot-stage').textContent = spotlightStage(hero, at);
  $('#spot-title').textContent = hero.path ? ((hero.method || 'GET') + ' ' + hero.path) : hero.name;
  $('#spot-route').textContent = hero.path && hero.name !== hero.path ? hero.name : (hero.route || 'journal process');
  $('#spot-kind').textContent = hero.kind; $('#spot-worker').textContent = hero.worker ? 'worker ' + hero.worker : 'worker —';
  $('#spot-time').textContent = fin ? fmtMs(fin.durationMs) : fmtMs(Math.max(0, t - hero.born));
  if (!ph) { $('#spot-ph').innerHTML = ''; return; }
  const parts = PHASE_KEYS.filter(k => typeof ph[k] === 'number').map(k => [k, ph[k]]);
  const sum = parts.reduce((n, pair) => n + pair[1], 0) || 1;
  $('#spot-ph').innerHTML = parts.map(pair => '<i class="' + pair[0] + ' w' + pctClass(pair[1] / sum * 100) + '" title="' + pair[0] + ' ' + fmtMs(pair[1]) + '"></i>').join('');
}

/* ------------------------------------------------------------ tooltip */
function nodeAt(wx, wy) {
  const N = world.nodes;
  for (const s of STAGES) { const n = N[s.key]; if (Math.abs(wx - n.x) <= n.w / 2 + 4 && Math.abs(wy - n.y) <= n.h / 2 + 4) return {key: s.key, stage: s, n}; }
  for (const k of ['db', ...infraShown(), 'cron', 'queue', 'run', 'done']) { const n = N[k]; if (Math.abs(wx - n.x) <= n.w / 2 && Math.abs(wy - n.y) <= n.h / 2) return {key: k, n}; }
  for (const k of ['live', 'refused', 'retry', 'failed', 'src:human', 'src:bot', 'src:api']) { const n = N[k]; if (Math.hypot(wx - n.x, wy - n.y) <= n.r) return {key: n.key, n}; }
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
    if (s.key === 'response') { const byKind = {}; for (const f of w60) byKind[f.kind] = (byKind[f.kind] || 0) + 1; list = '<ul>' + Object.entries(byKind).map(([k, c]) => '<li><b>' + k + '</b><span>' + c + ' / 60s</span></li>').join('') + '</ul>'; }
    return '<h4>' + esc(s.title) + '<code>' + (s.phase ? 'span ' + esc(spanName(s.phase)) : esc(s.label.toLowerCase())) + '</code></h4><p>' + esc(s.desc) + '</p>' + nums + list + (s.phase && S.pinned && S.pinned.key === s.key ? logsHtml(spanName(s.phase)) : '');
  }
  const side = SIDE[hit.key]; let extra = '';
  if (hit.key === 'db') { const qs = w60.map(f => f.phases && f.phases.q).filter(Boolean), qms = w60.map(f => f.phases && f.phases.qms).filter(Boolean); extra = '<div class="nums"><div><b>' + qps().toFixed(1) + '</b><span>q / s</span></div><div><b>' + (qs.length ? (qs.reduce((a, b) => a + b, 0) / qs.length).toFixed(1) : '–') + '</b><span>q / request</span></div><div><b>' + (qms.length ? fmtMs(qms.reduce((a, b) => a + b, 0) / qms.length) : '–') + '</b><span>db ms / req</span></div></div>'; }
  if (hit.key === 'live') { const open = [...S.procs.values()].filter(p => p.kind === 'sse'); extra = '<ul>' + open.slice(0, 8).map(p => '<li><b>' + esc(p.name) + '</b><span>' + (p.stale ? 'stale' : 'w' + p.worker) + '</span></li>').join('') + (open.length > 8 ? '<li><span>+' + (open.length - 8) + ' more</span></li>' : '') + '</ul>'; }
  if (hit.key === 'refused') {
    const rs = S.particles.filter(p => p.ring === 'refused');
    // An empty ring is ambiguous and must not be read as "nobody was turned
    // away": outcome=rejected is derived from trace events, so with stage mode
    // off a refusal is journalled as a plain ok and never reaches this ring.
    extra = rs.length
      ? '<ul>' + rs.slice(-8).reverse().map(p => '<li><b>' + esc(p.path || p.name) + '</b><span>' + esc(p.refusedAt || 'gate') + '</span></li>' + (p.reason ? '<li class="why">' + esc(p.reason) + '</li>' : '')).join('') + '</ul>'
      : (S.stage
        ? '<p><em>Nothing was turned away in the last 60 s.</em></p>'
        : '<p><em>Nothing here — but <b>stage</b> is off, so a refusal is recorded as a plain <code>ok</code> and never reaches this ring. Switch it on to see refusals at all.</em></p>');
  }
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
/* A percentage as a CLASS, rounded to whole percent.
 *
 * Not `style="width:42.7%"`. A strict Content-Security-Policy refuses an inline
 * style attribute exactly as it refuses an inline <script>, and these four
 * places are the panel's bars — so under a policy every bar rendered flat while
 * the rest of the page worked. MEASURED on a busy app: 299 refusals in a single
 * load. Whole percent is finer than the eye resolves on a bar this size, and
 * `.w0`…`.w100` is a few KB of stylesheet no policy can object to.
 */
function pctClass(pct, floor) { return Math.min(100, Math.max(floor || 0, Math.round(pct || 0))); }

// Logs are fetched when a block is PINNED, never on hover. Hovering is how a
// reader scans the picture; a request per node crossed would be a lot of I/O
// to answer a question nobody asked yet.
async function loadLogs(block) {
  if (!block || S.logs.has(block)) return;
  S.logs.set(block, {state: 'loading', lines: []});
  try {
    const r = await fetch('/__observatory/logs?block=' + encodeURIComponent(block), {cache: 'no-store', headers: {Accept: 'application/json'}});
    const d = await r.json();
    S.logs.set(block, d.allowed === false
      ? {state: 'denied', lines: [], reason: d.reason || 'not available here'}
      : {state: 'ok', lines: Array.isArray(d.lines) ? d.lines : []});
  } catch (e) {
    S.logs.set(block, {state: 'error', lines: []});
  }
}
function logsHtml(block) {
  if (!block) return '';
  const got = S.logs.get(block);
  if (!got || got.state === 'loading') return '<p class="logs"><em>reading the journal…</em></p>';
  if (got.state === 'denied') return '<p class="logs"><em>' + esc(got.reason) + '</em></p>';
  if (got.state === 'error') return '<p class="logs"><em>the log reader did not answer.</em></p>';
  // An empty list is NOT good news and must not read like it: the window is
  // the tail of the file, so quiet here means quiet recently, and without
  // stage mode there is no block on a line at all.
  if (!got.lines.length) {
    return '<p class="logs"><em>No lines from this block in the recent tail of app.log'
      + (S.stage ? '.' : ' — and <b>stage</b> is off, so nothing is being attributed to a block at all.') + '</em></p>';
  }
  return '<ul class="logs">' + got.lines.slice(-8).reverse().map(l =>
    '<li class="lv-' + esc(l.level) + '"><b>' + esc(l.message) + '</b><span>' + esc((l.ts || '').slice(11, 19)) + '</span></li>'
  ).join('') + '</ul>';
}
function hideTip() { if (S.pinned) return; $('#tip').classList.remove('show'); S.hover = null; }

/* The pointer over the canvas takes one of three fixed shapes, so it is a
   class rather than a CSSOM write — same reason as the historic row above. */
function setCursor(el, shape) { el.classList.remove('cur-grab', 'cur-grabbing', 'cur-pointer'); el.classList.add('cur-' + shape); }

/* ------------------------------------------------------------ controls */
function readTopology() {
  const el = document.querySelector('.obs'); if (!el) return;
  const t = el.dataset.queueTransport || 'in-memory', c = el.dataset.cacheDriver || '';
  S.topology = {queueTransport: t, cacheDriver: c || null};
}
// A named box appears only where that product is in effect. Redis backs the
// cache only when the cache asked for it; NATS carries events only when it is
// the resolved transport. Everything else keeps the transport-agnostic wording
// the picture used before, which was true under every configuration.
const infraShown = () => [...(hasRedisCache() ? ['redisCache', 'redis'] : []), ...(hasNats() ? ['nats'] : [])];
const hasRedisCache = () => S.topology.cacheDriver === 'redis';
const hasNats = () => S.topology.queueTransport === 'nats';
// What to call the hand-off when no product is named. 'enqueue' is what the bus
// said for years and it is true whatever carries it; in-memory earns a stronger
// word, because there is no boundary at all - the event never leaves the request.
function busLabel() {
  const t = S.topology.queueTransport;
  return t === 'in-memory' ? 'dispatched in-process' : t === 'nats' ? 'async transport' : 'enqueue \u00b7 ' + t;
}
function setCinema(on) {
  S.cinematic = !!on; document.documentElement.classList.toggle('cinematic', S.cinematic);
  const button = $('#b-cinema'); if (button) button.classList.toggle('on', S.cinematic);
  readTheme();
}
function toggleCinema() { setCinema(!S.cinematic); }
function setLed(state, why) {
  const d = $('#led'); d.className = 'dot' + (S.paused ? ' paused' : state === 'off' ? ' off' : '');
  const via = T.mode === 'sse' ? 'SSE stream' : T.mode === 'polling' ? 'polling 250 ms (SSE unavailable)' : 'connecting…';
  // The dot's own tooltip carries the state in words. A prose line beside it
  // used to say the same thing into #meta — a div the redesign left `hidden`
  // and never styled, so this wrote, every tick, into something no reader
  // could see. The header says it now through the dot and the transport chip.
  d.title = state === 'off'
    ? ('feed unreachable: ' + (why || '') + ' — retrying')
    : (S.paused ? 'paused' : 'following the journal over ' + via + (S.stage ? ' · stage mode: every request records its phases' : ''));
  const tr = $('#transport'); if (tr) { tr.textContent = T.mode === 'sse' ? 'sse' : T.mode === 'polling' ? 'poll' : '…'; tr.className = 'tr ' + T.mode; tr.title = via; }
}
async function refreshStage() { try { const r = await fetch('/__observatory/stage', {cache: 'no-store'}); const d = await r.json(); S.stage = !!d.stage; S.stageAvailable = !!d.available; } catch (e) { } paintStage(); }
async function toggleStage() { if (!S.stageAvailable) return; try { const r = await fetch('/__observatory/stage', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'on=' + (S.stage ? 0 : 1)}); const d = await r.json(); S.stage = !!d.stage; } catch (e) { } paintStage(); }
function paintStage() { const b = $('#b-stage'); b.classList.toggle('on', S.stage); b.disabled = !S.stageAvailable; b.title = S.stageAvailable ? 'Record a phase breakdown for EVERY request (dev only, nothing written to var/trace, lapses after 12 h)' : 'Stage mode needs APP_ENV=dev'; setLed(S.pollFail ? 'off' : 'ok'); }
function toggleDemo() {
  if (S.demo) { clearTimeout(S.demo); S.demo = null; $('#b-demo').classList.remove('on'); return; }
  // No setCinema(true) here. Demo load generates traffic; cinema changes how
  // the picture is lit. Coupling them meant pressing one silently did the
  // other and turning demo off left the lighting changed, with no way back
  // that the button admitted to.
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
    if (S.drag) { const dx = l.x - S.drag.sx, dy = l.y - S.drag.sy; if (Math.hypot(dx, dy) > 3) S.drag.moved = true; if (S.drag.moved) { S.view.x = S.drag.vx + dx; S.view.y = S.drag.vy + dy; clampView(); setCursor(rc, 'grabbing'); } return; }
    const w = toWorld(l.x, l.y), hit = nodeAt(w.x, w.y); setCursor(rc, hit ? 'pointer' : 'grab'); if (S.pinned) return; if (hit) { S.hover = hit.key; showTip(hit, false); } else hideTip(); });
  const up = e => { S.pointers.delete(e.pointerId); if (S.pointers.size < 2) S.pinch = null;
    if (S.drag) { const wasClick = !S.drag.moved; S.drag = null; setCursor(rc, 'grab'); if (wasClick) { const l = local(e), w = toWorld(l.x, l.y), hit = nodeAt(w.x, w.y);
      if (S.pinned && (!hit || hit.key === S.pinned.key)) { S.pinned = null; $('#tip').classList.remove('show', 'pinned'); return; } if (hit) { S.pinned = hit; if (hit.stage && hit.stage.phase) loadLogs(spanName(hit.stage.phase)); showTip(hit, true); } } } };
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
  readTopology();
  readTheme(); setCinema(new URLSearchParams(location.search).get('cinema') === '1'); layout(); layoutTl();
  new ResizeObserver(() => { layout(); layoutTl(); }).observe($('#river'));
  new ResizeObserver(() => layoutTl()).observe($('#time'));
  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', readTheme);
  bindView(world.canvas);
  $('#b-stage').addEventListener('click', toggleStage); $('#b-demo').addEventListener('click', toggleDemo); $('#b-cinema').addEventListener('click', toggleCinema); $('#b-pause').addEventListener('click', togglePause);
  $('#b-explain').addEventListener('click', toggleExplain); $('#b-full').addEventListener('click', fullscreen); $('#b-fit').addEventListener('click', fitView);
  $('#z-in').addEventListener('click', () => zoomAt(world.W / 2, world.H / 2, 1.25)); $('#z-out').addEventListener('click', () => zoomAt(world.W / 2, world.H / 2, 1 / 1.25)); $('#z-fit').addEventListener('click', fitView);
  document.querySelectorAll('.pan button').forEach(b => b.addEventListener('click', () => { const d = 120; S.view.x += +b.dataset.x * d; S.view.y += +b.dataset.y * d; clampView(); }));
  document.querySelectorAll('.tabs button').forEach(b => b.addEventListener('click', () => { document.querySelectorAll('.tabs button').forEach(x => x.classList.toggle('on', x === b)); document.querySelectorAll('.pane').forEach(pn => pn.hidden = pn.id !== 'pane-' + b.dataset.tab); }));
  document.querySelectorAll('#speed button').forEach(b => b.addEventListener('click', () => setSpeed(+b.dataset.v)));
  document.addEventListener('keydown', e => {
    if (e.target && /input|textarea/i.test(e.target.tagName)) return;
    if (e.key === ' ') { e.preventDefault(); togglePause(); }
    else if (e.key === 's') toggleStage(); else if (e.key === 'd') toggleDemo(); else if (e.key === 'c') toggleCinema(); else if (e.key === 'f') fullscreen(); else if (e.key === 'e') toggleExplain(); else if (e.key === '0') fitView();
    else if (e.key === '1') setSpeed(1); else if (e.key === '2') setSpeed(0.5); else if (e.key === '3') setSpeed(0.25);
    else if (e.key === '+' || e.key === '=') zoomAt(world.W / 2, world.H / 2, 1.2); else if (e.key === '-') zoomAt(world.W / 2, world.H / 2, 1 / 1.2);
    else if (e.key.startsWith('Arrow')) { e.preventDefault(); const d = 80; S.view.x += e.key === 'ArrowLeft' ? d : e.key === 'ArrowRight' ? -d : 0; S.view.y += e.key === 'ArrowUp' ? d : e.key === 'ArrowDown' ? -d : 0; clampView(); }
  });
  refreshStage(); setInterval(refreshStage, 15000);
  loadSchedules(); setInterval(loadSchedules, 60000);
  schedule(0);
  let lastPanels = 0, lastSpotlight = 0;
  const frame = t => {
    if (!document.hidden) {
      drawRiver(t);
      if (t - lastSpotlight > 160) { lastSpotlight = t; renderSpotlight(t); }
      if (t - lastPanels > 400) { lastPanels = t; renderWorkers(); renderTiles(); drawTimeline(t); if (S.pinned) showTip(S.pinned, true); }
    }
    requestAnimationFrame(frame);
  };
  requestAnimationFrame(frame);
}
document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', boot) : boot();
})();

/* Semitexa dev toolbar — the bar at the bottom of every page in dev.
   The page carries only #semitexa-devbar with data-* facts about the route
   that rendered it; this script draws the bar inside a shadow root (so the
   site's CSS and the bar's cannot touch each other) and asks the journal for
   what only exists after the response left: status, time, queries. */
(() => {
  'use strict';

  const seed = document.getElementById('semitexa-devbar');
  // Inside the Explorer's preview or any other frame the host page has its own bar.
  if (!seed || window.top !== window || seed.dataset.drawn) return;
  // Not under browser automation (Playwright, WebDriver): the release clone
  // runs its smoke tests with APP_ENV=dev, and a fixed bar over the bottom
  // edge would sit on top of whatever a test tries to click there.
  if (navigator.webdriver) return;
  seed.dataset.drawn = '1';
  const d = seed.dataset;
  const COLLAPSED_KEY = 'semitexa.devbar.collapsed';

  const store = {
    get: () => { try { return localStorage.getItem(COLLAPSED_KEY) === '1'; } catch { return false; } },
    set: (v) => { try { localStorage.setItem(COLLAPSED_KEY, v ? '1' : '0'); } catch { /* storage off */ } },
  };

  const host = document.createElement('div');
  host.setAttribute('data-semitexa-devbar', '');
  const root = host.attachShadow({ mode: 'open' });
  const css = document.createElement('link');
  css.rel = 'stylesheet';
  css.href = '/__toolbar/asset/toolbar.css';
  root.append(css);

  const el = (tag, attrs = {}, ...kids) => {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') n.className = v;
      else if (k === 'text') n.textContent = v;
      else n.setAttribute(k, v === true ? '' : v);
    }
    for (const kid of kids.flat()) if (kid !== null && kid !== undefined && kid !== false) n.append(kid);
    return n;
  };
  const short = (fqcn) => (fqcn ? String(fqcn).split('\\').pop() : '');
  const statusTone = (s) => (!s ? 'mute' : s < 300 ? 'ok' : s < 400 ? 'redir' : s < 500 ? 'warn' : 'err');

  /* ---------- pieces ---------- */
  const status = el('span', { class: 'pill mute', text: '…', title: 'HTTP status' });
  const time = el('span', { class: 'cell', title: 'Server time for this request' }, el('b', { text: '…' }), ' ms');
  const queries = el('span', { class: 'cell', hidden: true, title: 'SQL queries (from the recorded trace)' });
  const traceSlot = el('span', { class: 'cell' });
  const routeBtn = el('button', { type: 'button', class: 'cell route', title: 'Route details' },
    el('span', { class: 'm ' + (d.method || '').toLowerCase(), text: d.method || '?' }),
    el('span', { class: 'mono', text: d.route || d.path || '?' }),
    d.handler ? el('span', { class: 'dim', text: short(d.handler) }) : null);

  const details = el('div', { class: 'details', hidden: true });
  const meta = el('dl');
  const addMeta = (k, v) => { if (v) meta.append(el('dt', { text: k }), el('dd', { text: v })); };
  // The server knows the ROUTED path (locale prefix stripped); the address
  // bar knows what was actually served.
  addMeta('Served path', location.pathname);
  addMeta('Route', d.route);
  addMeta('Name', d.name);
  addMeta('Kind', d.kind);
  addMeta('Access', d.access);
  addMeta('Module', d.module);
  addMeta('Payload', d.payload);
  addMeta('Handler', d.handler);
  addMeta('Process', d.process);
  const phases = el('div', { class: 'phases' });
  details.append(meta, phases);
  routeBtn.addEventListener('click', () => { details.hidden = !details.hidden; });

  const explorerBtn = el('button', { type: 'button', class: 'cell act', title: 'Call this route with other input (API Explorer)', text: 'API explorer' });
  const collapseBtn = el('button', { type: 'button', class: 'cell icon', title: 'Hide the toolbar', text: '✕' });
  const bar = el('div', { class: 'bar', role: 'toolbar', 'aria-label': 'Semitexa dev toolbar' },
    el('span', { class: 'brand', title: 'Semitexa dev toolbar', text: 'SX' }),
    status, routeBtn, time, queries, traceSlot,
    el('span', { class: 'spacer' }),
    explorerBtn,
    el('a', { class: 'cell', href: '/__observatory', target: '_blank', rel: 'noopener', text: 'Observatory ↗' }),
    collapseBtn);

  const mini = el('button', { type: 'button', class: 'mini', title: 'Show the Semitexa dev toolbar' },
    el('span', { class: 'brand', text: 'SX' }), el('span', { class: 'mini-status', text: '…' }));

  /* ---------- explorer dialog ---------- */
  const frame = el('iframe', { title: 'API Explorer' });
  const detach = el('a', { href: '/__explorer', target: '_blank', rel: 'noopener', text: 'open in new tab ↗' });
  const closeDialog = el('button', { type: 'button', class: 'icon', 'aria-label': 'Close', text: '✕' });
  const dialog = el('dialog', { class: 'explorer' },
    el('header', {}, el('b', { text: 'API Explorer' }), el('span', { class: 'dim', text: 'calls are real' }), el('span', { class: 'spacer' }), detach, closeDialog),
    frame);
  const explorerUrl = () => {
    const p = new URLSearchParams({ kind: d.kind || 'page' });
    if (d.routeId) p.set('route', d.routeId);
    return '/__explorer#' + p.toString();
  };
  explorerBtn.addEventListener('click', () => {
    if (!frame.src) frame.src = explorerUrl();
    dialog.showModal();
  });
  closeDialog.addEventListener('click', () => dialog.close());
  detach.addEventListener('click', () => {
    // Until the frame has loaded it sits on about:blank; hand over where it is going instead.
    let href = explorerUrl();
    try {
      const current = frame.contentWindow.location.href;
      if (current.startsWith(location.origin + '/')) href = current;
    } catch { /* keep the destination */ }
    detach.href = href;
  });

  /* ---------- collapse ---------- */
  const spacer = el('div', { class: 'page-spacer' });
  function setCollapsed(c) {
    bar.hidden = c; details.hidden = true; mini.hidden = !c;
    // Keep the page's own footer reachable while the bar covers the bottom edge.
    spacer.hidden = c;
    store.set(c);
  }
  collapseBtn.addEventListener('click', () => setCollapsed(true));
  mini.addEventListener('click', () => setCollapsed(false));

  root.append(details, bar, mini, dialog);
  document.body.append(spacer, host);
  spacer.style.height = '38px';
  setCollapsed(store.get());

  /* ---------- trace ---------- */
  function renderTrace(end) {
    if (end && end.trace) {
      traceSlot.replaceChildren(el('a', { href: '/__trace?file=' + encodeURIComponent(end.trace), target: '_blank', rel: 'noopener', text: 'Trace ↗' }));
      return;
    }
    const url = new URL(location.href);
    url.searchParams.set('__trace', '1');
    traceSlot.replaceChildren(el('a', { href: url.toString(), title: 'Reload this page with a full trace: queries, phases, waterfall', text: '● Record trace' }));
  }

  function renderPhases(p) {
    if (!p) return;
    // Stage timings are the numeric keys; n/q/qms/queued are counts, not stages.
    const stages = Object.entries(p).filter(([k, v]) => typeof v === 'number' && !['n', 'q', 'qms', 'queued'].includes(k));
    if (!stages.length) return;
    phases.replaceChildren(el('h4', { text: 'Where the time went' }),
      el('table', {}, stages.map(([k, v]) => el('tr', {}, el('td', { class: 'mono', text: k }), el('td', { class: 'num', text: v.toFixed(1) + ' ms' })))));
  }

  /* ---------- journal ---------- */
  async function load(attempt) {
    if (!d.process) {
      status.textContent = '—';
      time.firstChild.textContent = '—';
      renderTrace(null);
      return;
    }
    let data = null;
    try {
      const res = await fetch('/__toolbar/process/' + encodeURIComponent(d.process), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      data = res.ok ? await res.json() : null;
    } catch { /* retried below */ }

    const end = data && data.end;
    if (!end) {
      // The end line is written after the response is sent; give it a moment.
      if (attempt < 10) setTimeout(() => load(attempt + 1), 300);
      else { status.textContent = '?'; renderTrace(null); }
      return;
    }
    const code = end.context && end.context.http_status;
    status.textContent = code ? String(code) : (end.context && end.context.exception ? 'ERR' : '?');
    status.className = 'pill ' + statusTone(code);
    mini.lastChild.textContent = `${status.textContent} · ${Math.round(end.durationMs)} ms`;
    mini.className = 'mini ' + statusTone(code);
    time.firstChild.textContent = end.durationMs >= 100 ? Math.round(end.durationMs) : end.durationMs.toFixed(1);
    if (end.phases && typeof end.phases.q === 'number') {
      queries.hidden = false;
      queries.replaceChildren(el('b', { text: String(end.phases.q) }), ` queries · ${end.phases.qms.toFixed(1)} ms`);
    }
    renderPhases(end.phases);
    renderTrace(end);
  }
  load(0);
})();

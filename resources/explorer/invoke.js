/* API Explorer — the request builder inside the route dialog.
   Listens for `explorer:route` (fired by explorer.js once a route's contract
   has loaded) and draws: method + URL, prepared variations, a form generated
   from the contract with a raw-JSON twin, headers, and the response.

   Every request carries `X-Semitexa-Trace: explorer-<random>`, so its full
   waterfall is recorded; /__explorer/trace resolves the marker to the trace
   file and the result links to it. The call itself is a plain same-origin
   fetch — real cookies, real CSRF, real side effects — which is the point:
   the Observatory in another tab shows what it did to the system. */
(() => {
  'use strict';

  const X = window.SemitexaExplorer;
  if (!X) return;
  const { el, short } = X;
  const $ = (id) => document.getElementById(id);

  const BODYLESS = new Set(['GET', 'HEAD', 'DELETE']);
  const HISTORY_KEY = 'semitexa.explorer.history';
  const HISTORY_MAX = 15;
  const ACCESS_NOTES = {
    protected: 'Protected route — it runs as whoever this browser is logged in as. Log in on the site first, or expect 401.',
    service: 'Service route — machine authentication. Add its credential under Headers (e.g. Authorization: Bearer …), or expect 401.',
  };

  /* ---------- sample values ---------- */
  const today = () => new Date().toISOString().slice(0, 10);
  const FORMAT_SAMPLES = {
    email: () => 'dev@example.com',
    url: () => 'https://example.com',
    uuid: () => (crypto.randomUUID ? crypto.randomUUID() : '00000000-0000-4000-8000-000000000000'),
    date: today,
    datetime: () => new Date().toISOString().slice(0, 19) + 'Z',
    phone: () => '+380501234567',
    slug: () => 'sample-slug',
    password: () => 'Secret123!',
    currency: () => 'USD',
    locale: () => 'en',
    color: () => '#5b9dff',
  };

  function baseType(t) {
    return String(t || 'mixed').replace(/^\?/, '').split('|').filter((x) => x !== 'null')[0] || 'mixed';
  }

  function sample(field, hint) {
    if (hint.enum && hint.enum.length) return hint.enum[0];
    if (hint.default !== undefined) return hint.default;
    switch (baseType(field.type)) {
      case 'int': return 1;
      case 'float': return 1.5;
      case 'bool': return true;
      case 'array': return [];
      default: return hint.format && FORMAT_SAMPLES[hint.format] ? FORMAT_SAMPLES[hint.format]() : 'sample';
    }
  }

  function wrongTypeValue(field) {
    switch (baseType(field.type)) {
      case 'int': case 'float': return 'not-a-number';
      case 'bool': return 'maybe';
      case 'array': return 'not-an-array';
      default: return null;
    }
  }

  function pathSample(name, requirement) {
    if (requirement && /\\d|\[0-9]/.test(requirement)) return '1';
    if (/id$/i.test(name)) return '1';
    return 'sample';
  }

  /* ---------- variations ---------- */
  function buildPresets(ctx) {
    const { fields, hints } = ctx;
    const hintOf = (f) => hints[f.name] || {};
    const all = () => Object.fromEntries(fields.map((f) => [f.name, sample(f, hintOf(f))]));
    const required = fields.filter((f) => f.required);
    const minimal = () => Object.fromEntries(required.map((f) => [f.name, sample(f, hintOf(f))]));
    const presets = [];

    presets.push({ id: 'minimal', label: 'Minimal', expect: '2xx', note: 'Required fields only', values: minimal() });
    if (fields.length > required.length) {
      presets.push({ id: 'full', label: 'Full example', expect: '2xx', note: 'Every field with a sample value', values: all() });
    }
    if (required.length) {
      presets.push({ id: 'empty', label: 'Empty', expect: '422', note: 'No input at all', values: {} });
    }
    for (const f of required) {
      const v = minimal(); delete v[f.name];
      presets.push({ id: 'missing-' + f.name, label: 'Without ' + f.name, expect: '422', note: `Required field ${f.name} left out`, values: v });
    }
    for (const f of fields) {
      const bad = wrongTypeValue(f);
      if (bad === null) continue;
      const v = all(); v[f.name] = bad;
      presets.push({ id: 'type-' + f.name, label: f.name + ': wrong type', expect: '422', note: `${f.name} expects ${f.type}, gets ${JSON.stringify(bad)}`, values: v });
    }
    for (const f of fields) {
      const cases = (hintOf(f).enum || []).slice(1, 4);
      for (const c of cases) {
        const v = all(); v[f.name] = c;
        presets.push({ id: `enum-${f.name}-${c}`, label: `${f.name} = ${c}`, expect: '2xx', note: 'Another allowed value', values: v });
      }
    }
    return presets;
  }

  /* ---------- request model ---------- */
  function currentRequest(ctx) {
    const method = ctx.ui.method.value;
    let path = ctx.route.path;
    for (const p of ctx.route.path_params) {
      const input = ctx.ui.pathInputs[p];
      path = path.replace(new RegExp('\\{' + p + '(?::[^}]*)?\\}'), encodeURIComponent(input ? input.value : ''));
    }
    const values = readValues(ctx);
    const headers = { Accept: ctx.ui.accept.value };
    for (const line of ctx.ui.headers.value.split('\n')) {
      const i = line.indexOf(':');
      if (i > 0) headers[line.slice(0, i).trim()] = line.slice(i + 1).trim();
    }

    let url = path;
    let body;
    if (BODYLESS.has(method)) {
      const qs = new URLSearchParams();
      for (const [k, v] of Object.entries(values)) {
        if (Array.isArray(v)) v.forEach((x) => qs.append(k + '[]', String(x)));
        else if (v !== null && v !== undefined) qs.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v));
      }
      const s = qs.toString();
      if (s) url += '?' + s;
    } else if (ctx.ui.encoding.value === 'form') {
      const fd = new URLSearchParams();
      for (const [k, v] of Object.entries(values)) {
        if (Array.isArray(v)) v.forEach((x) => fd.append(k + '[]', String(x)));
        else if (v !== null && v !== undefined) fd.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v));
      }
      body = fd.toString();
      headers['Content-Type'] = 'application/x-www-form-urlencoded';
    } else {
      body = JSON.stringify(values);
      headers['Content-Type'] = 'application/json';
    }
    return { method, url, headers, body, values };
  }

  function readValues(ctx) {
    if (ctx.rawMode) {
      try { return JSON.parse(ctx.ui.raw.value || '{}'); } catch { return {}; }
    }
    const out = {};
    for (const f of ctx.fields) {
      const row = ctx.ui.rows[f.name];
      if (!row || !row.on.checked) continue;
      out[f.name] = row.get();
    }
    return out;
  }

  function applyValues(ctx, values) {
    for (const f of ctx.fields) {
      const row = ctx.ui.rows[f.name];
      if (!row) continue;
      const has = Object.prototype.hasOwnProperty.call(values, f.name);
      row.on.checked = has;
      if (has) row.set(values[f.name]);
    }
    ctx.ui.raw.value = JSON.stringify(values, null, 2);
    refreshUrl(ctx);
  }

  /* ---------- form ---------- */
  function fieldRow(ctx, f) {
    const hint = ctx.hints[f.name] || {};
    const type = baseType(f.type);
    const on = el('input', { type: 'checkbox', title: 'Send this field' });
    let input, get, set;

    if (hint.enum && hint.enum.length) {
      input = el('select', {}, hint.enum.map((c) => el('option', { value: String(c), text: String(c) })));
      get = () => { const v = input.value; return hint.enum.find((c) => String(c) === v) ?? v; };
      set = (v) => { input.value = String(v); };
    } else if (type === 'bool') {
      input = el('select', {}, el('option', { value: 'true', text: 'true' }), el('option', { value: 'false', text: 'false' }));
      get = () => (input.value === 'true' ? true : input.value === 'false' ? false : input.value);
      set = (v) => {
        // A wrong-type variation sends a non-boolean; give it an option to show.
        const s = String(v);
        if (![...input.options].some((o) => o.value === s)) input.append(el('option', { value: s, text: s }));
        input.value = s;
      };
    } else if (type === 'array') {
      input = el('input', { type: 'text', placeholder: '["a","b"] as JSON' });
      get = () => { try { return JSON.parse(input.value); } catch { return input.value; } };
      set = (v) => { input.value = typeof v === 'string' ? v : JSON.stringify(v); };
    } else {
      input = el('input', { type: 'text', placeholder: hint.format || type });
      get = () => {
        const v = input.value;
        if ((type === 'int' || type === 'float') && v.trim() !== '' && !Number.isNaN(Number(v))) return Number(v);
        return v;
      };
      set = (v) => { input.value = v === null || v === undefined ? '' : (typeof v === 'object' ? JSON.stringify(v) : String(v)); };
    }
    const sync = () => { on.checked = true; ctx.ui.raw.value = JSON.stringify(readValues(ctx), null, 2); refreshUrl(ctx); };
    input.addEventListener('input', sync);
    input.addEventListener('change', sync);
    on.addEventListener('change', () => { ctx.ui.raw.value = JSON.stringify(readValues(ctx), null, 2); refreshUrl(ctx); });

    ctx.ui.rows[f.name] = { on, get, set };
    const tags = [
      el('code', { class: 'ex-ftype', text: (f.nullable ? '?' : '') + (f.type || 'mixed') }),
      f.required ? el('span', { class: 'ex-req', text: 'required' }) : null,
      hint.format ? el('span', { class: 'ex-tag', text: hint.format }) : null,
    ];
    return el('label', { class: 'ex-field' }, on, el('span', { class: 'ex-fname mono', text: f.name }), input, el('span', { class: 'ex-ftags' }, tags));
  }

  function refreshUrl(ctx) {
    const r = currentRequest(ctx);
    ctx.ui.url.textContent = r.url;
    ctx.ui.encodingWrap.hidden = BODYLESS.has(r.method);
    ctx.ui.where.textContent = BODYLESS.has(r.method) ? 'sent as query string' : 'sent as request body';
  }

  /* ---------- history ---------- */
  function loadHistory() {
    try { return JSON.parse(localStorage.getItem(HISTORY_KEY) || '{}') || {}; } catch { return {}; }
  }
  function saveHistory(routeId, entry) {
    try {
      const all = loadHistory();
      all[routeId] = [entry, ...(all[routeId] || [])].slice(0, HISTORY_MAX);
      localStorage.setItem(HISTORY_KEY, JSON.stringify(all));
    } catch { /* private window or storage off — history is a convenience */ }
  }
  function renderHistory(ctx) {
    const items = loadHistory()[ctx.route.id] || [];
    ctx.ui.history.replaceChildren(...(items.length ? items.map((h) => {
      const b = el('button', { type: 'button', class: 'ex-hist' },
        el('span', { class: 'ex-pill ' + statusClass(h.status), text: String(h.status || 'ERR') }),
        el('span', { class: 'mono', text: h.method + ' ' + h.url }),
        el('span', { class: 'ex-dim', text: new Date(h.at).toLocaleTimeString() + ' · ' + h.ms + ' ms' }));
      b.addEventListener('click', () => { ctx.ui.method.value = h.method; applyValues(ctx, h.values || {}); });
      return b;
    }) : [el('p', { class: 'ex-empty', text: 'No calls yet from this browser.' })]));
  }

  const statusClass = (s) => (!s ? 'err' : s < 300 ? 'ok' : s < 400 ? 'redir' : s < 500 ? 'warn' : 'err');

  /* ---------- send ---------- */
  function csrfToken() {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : null;
  }
  const token = () => 'explorer-' + Array.from(crypto.getRandomValues(new Uint8Array(8)), (b) => b.toString(16).padStart(2, '0')).join('');

  async function send(ctx) {
    const req = currentRequest(ctx);
    const marker = token();
    const headers = { ...req.headers, 'X-Semitexa-Trace': marker };
    if (!BODYLESS.has(req.method)) {
      const t = csrfToken();
      if (t && !headers['X-CSRF-Token']) headers['X-CSRF-Token'] = t;
    }

    ctx.ui.send.disabled = true;
    ctx.ui.result.replaceChildren(el('p', { class: 'ex-empty', text: `Sending ${req.method} ${req.url}…` }));
    const started = performance.now();
    let res, text, error;
    try {
      res = await fetch(req.url, { method: req.method, headers, body: req.body, credentials: 'same-origin' });
      text = await res.text();
    } catch (e) {
      error = e;
    }
    const ms = Math.round(performance.now() - started);
    ctx.ui.send.disabled = false;

    saveHistory(ctx.route.id, { at: Date.now(), method: req.method, url: req.url, status: res ? res.status : 0, ms, values: req.values });
    renderHistory(ctx);
    renderResult(ctx, { req, res, text, error, ms, marker });
  }

  function renderResult(ctx, { req, res, text, error, ms, marker }) {
    if (error) {
      ctx.ui.result.replaceChildren(el('p', { class: 'ex-error', text: 'Network error: ' + error.message }));
      return;
    }
    const type = res.headers.get('content-type') || '';
    const expect = ctx.activePreset ? ctx.activePreset.expect : null;
    const verdict = expect ? judge(res.status, expect) : null;
    const traceLink = el('a', { class: 'ex-trace', target: '_blank', rel: 'noopener', hidden: true, text: 'Trace ↗' });
    const summary = el('div', { class: 'ex-summary' },
      el('span', { class: 'ex-pill ' + statusClass(res.status), text: `${res.status} ${res.statusText}` }),
      el('span', { class: 'ex-dim', text: `${ms} ms · ${formatBytes(text.length)} · ${type || 'no content-type'}` }),
      res.redirected ? el('span', { class: 'ex-dim', text: 'redirected → ' + new URL(res.url).pathname }) : null,
      verdict === null ? null : el('span', { class: 'ex-expect ' + verdict.tone, title: verdict.why, text: verdict.text }),
      el('span', { class: 'ex-spacer' }),
      traceLink,
      el('a', { href: '/__observatory', target: '_blank', rel: 'noopener', text: 'Observatory ↗' }));

    const tabs = [['Body', bodyView(text, type)], ['Headers', headersView(res.headers)]];
    if (type.includes('text/html')) tabs.splice(1, 0, ['Preview', previewView(text, req, res)]);
    ctx.ui.result.replaceChildren(summary, tabbed(tabs));
    locateTrace(marker, traceLink, 0);
  }

  /**
   * Did the variation get the answer it was built to provoke? 401/403 are
   * neither: the request was stopped before the handler, so a "wrong type"
   * variation that meets a 401 proved nothing about validation.
   */
  function judge(status, expect) {
    if ((status === 401 || status === 403) && expect !== String(status)) {
      return { tone: 'blocked', text: `⚠ ${status}: stopped by auth — ${expect} not tested`,
        why: 'The request never reached the handler, so this variation proved nothing. Authenticate and send again.' };
    }
    const met = expect === '2xx' ? status >= 200 && status < 400
      : expect === '4xx' ? status >= 400 && status < 500
      : String(status) === expect;
    return met
      ? { tone: 'ok', text: '✓ expected ' + expect, why: 'The route answered as this variation predicts.' }
      : { tone: 'miss', text: `✗ expected ${expect}, got ${status}`, why: 'The route answered differently from what this variation predicts.' };
  }

  async function locateTrace(marker, link, attempt) {
    try {
      const res = await fetch('/__explorer/trace?token=' + encodeURIComponent(marker), { headers: { Accept: 'application/json' } });
      const data = res.ok ? await res.json() : {};
      if (data.file) {
        link.href = '/__trace?file=' + encodeURIComponent(data.file);
        link.hidden = false;
        return;
      }
    } catch { /* retried below */ }
    if (attempt < 8) setTimeout(() => locateTrace(marker, link, attempt + 1), 250);
  }

  const formatBytes = (n) => (n < 1024 ? n + ' B' : (n / 1024).toFixed(1) + ' KB');

  function bodyView(text, type) {
    let shown = text;
    if (type.includes('json')) {
      try { shown = JSON.stringify(JSON.parse(text), null, 2); } catch { /* not JSON after all */ }
    }
    if (shown.length > 200000) shown = shown.slice(0, 200000) + '\n… (truncated for display)';
    return el('pre', { class: 'ex-body', text: shown || '(empty body)' });
  }

  function previewView(html, req, res) {
    // A GET is safe to ask again, so the preview loads the real URL: the page
    // renders with its own scripts and styles, exactly as a visitor sees it.
    if (req.method === 'GET') {
      return el('div', { class: 'ex-block' },
        el('span', { class: 'ex-dim', text: 'Live render of ' + new URL(res.url).pathname + ' (loaded again in the frame)' }),
        el('iframe', { class: 'ex-preview', src: res.url, loading: 'lazy', title: 'Rendered page' }));
    }
    // Anything else must not be re-sent: show the returned HTML as a static
    // snapshot — styles resolve against this origin, no script runs.
    const frame = el('iframe', { class: 'ex-preview', sandbox: 'allow-same-origin', title: 'Returned HTML' });
    frame.srcdoc = '<base href="' + location.origin + '/">' + html;
    return el('div', { class: 'ex-block' },
      el('span', { class: 'ex-dim', text: 'Static snapshot of the returned HTML — scripts do not run' }), frame);
  }

  function headersView(headers) {
    const rows = [];
    headers.forEach((v, k) => rows.push(el('tr', {}, el('td', { class: 'mono', text: k }), el('td', { class: 'mono', text: v }))));
    return el('table', { class: 'ex-table' }, el('tbody', {}, rows));
  }

  function tabbed(tabs) {
    const bar = el('div', { class: 'ex-tabs', role: 'tablist' });
    const panes = el('div', { class: 'ex-panes' });
    tabs.forEach(([label, view], i) => {
      const b = el('button', { type: 'button', role: 'tab', 'aria-selected': String(i === 0), text: label });
      const pane = el('div', { role: 'tabpanel', hidden: i !== 0 }, view);
      b.addEventListener('click', () => {
        [...bar.children].forEach((x) => x.setAttribute('aria-selected', String(x === b)));
        [...panes.children].forEach((x) => { x.hidden = x !== pane; });
      });
      bar.append(b); panes.append(pane);
    });
    return el('div', { class: 'ex-result-tabs' }, bar, panes);
  }

  /* ---------- SSE ---------- */
  function streamView(ctx) {
    const log = el('pre', { class: 'ex-body', text: '' });
    const start = el('button', { type: 'button', class: 'ex-send', text: 'Open stream' });
    const stop = el('button', { type: 'button', class: 'ex-btn', text: 'Close', disabled: true });
    let source = null;
    const line = (s) => { log.textContent = (s + '\n' + log.textContent).slice(0, 50000); };
    start.addEventListener('click', () => {
      const { url } = currentRequest(ctx);
      source = new EventSource(url, { withCredentials: true });
      line('→ connecting ' + url);
      source.onopen = () => line('✓ open');
      source.onmessage = (e) => line(`[message] ${e.data}`);
      source.onerror = () => line('✗ error / reconnecting');
      start.disabled = true; stop.disabled = false;
    });
    stop.addEventListener('click', () => { source?.close(); line('■ closed'); start.disabled = false; stop.disabled = true; });
    $('ex-route').addEventListener('close', () => source?.close(), { once: true });
    return el('div', { class: 'ex-stream' }, el('div', { class: 'ex-row' }, start, stop), log);
  }

  /* ---------- draw ---------- */
  document.addEventListener('explorer:route', (e) => {
    const { route, contract } = e.detail;
    const host = $('ex-invoke');
    if (!host) return;

    const fields = ((contract && contract.input && contract.input.fields) || (contract && contract.fields) || [])
      .filter((f) => !route.path_params.includes(f.name));
    const hints = e.detail.hints || {};
    const ctx = { route, fields, hints, ui: { rows: {}, pathInputs: {} }, rawMode: false, activePreset: null };

    const ui = ctx.ui;
    ui.method = el('select', { class: 'ex-method', 'aria-label': 'Method' }, route.methods.map((m) => el('option', { value: m, text: m })));
    ui.url = el('code', { class: 'ex-url' });
    ui.send = el('button', { type: 'button', class: 'ex-send', text: 'Send', title: 'Ctrl+Enter' });
    ui.accept = el('select', { 'aria-label': 'Accept' },
      ['text/html', 'application/json', '*/*', 'text/event-stream'].map((a) => el('option', { value: a, text: a })));
    ui.accept.value = route.kind === 'page' ? 'text/html' : route.kind === 'stream' ? 'text/event-stream' : 'application/json';
    ui.headers = el('textarea', { class: 'ex-headers', rows: '2', placeholder: 'Extra headers, one per line — X-Tenant: acme', spellcheck: 'false' });
    ui.encoding = el('select', { 'aria-label': 'Body encoding' }, el('option', { value: 'json', text: 'JSON body' }), el('option', { value: 'form', text: 'Form body' }));
    ui.encodingWrap = el('span', {}, ui.encoding);
    ui.where = el('span', { class: 'ex-dim' });
    ui.raw = el('textarea', { class: 'ex-raw', rows: '8', spellcheck: 'false', hidden: true, 'aria-label': 'Raw JSON input' });
    ui.result = el('div', { class: 'ex-result' });
    ui.history = el('div', { class: 'ex-history' });

    const pathBox = route.path_params.length
      ? el('div', { class: 'ex-params' }, route.path_params.map((p) => {
          const input = el('input', { type: 'text', value: pathSample(p, route.requirements[p]), placeholder: route.requirements[p] || p });
          input.addEventListener('input', () => refreshUrl(ctx));
          ui.pathInputs[p] = input;
          return el('label', { class: 'ex-field' }, el('span', { class: 'ex-fname mono', text: '{' + p + '}' }), input,
            el('span', { class: 'ex-ftags' }, route.requirements[p] ? el('code', { class: 'ex-ftype', text: route.requirements[p] }) : null));
        }))
      : null;

    const form = el('div', { class: 'ex-form' }, fields.map((f) => fieldRow(ctx, f)));
    const rawToggle = el('button', { type: 'button', class: 'ex-btn', text: 'Raw JSON' });
    rawToggle.addEventListener('click', () => {
      if (!ctx.rawMode) { ui.raw.value = JSON.stringify(readValues(ctx), null, 2); }
      else { try { applyValues(ctx, JSON.parse(ui.raw.value || '{}')); } catch { /* keep form as is */ } }
      ctx.rawMode = !ctx.rawMode;
      ui.raw.hidden = !ctx.rawMode; form.hidden = ctx.rawMode;
      rawToggle.textContent = ctx.rawMode ? 'Form' : 'Raw JSON';
      refreshUrl(ctx);
    });
    ui.raw.addEventListener('input', () => refreshUrl(ctx));

    const presets = buildPresets(ctx);
    const presetBar = el('div', { class: 'ex-presets' }, presets.map((p) => {
      const b = el('button', { type: 'button', class: 'ex-preset', title: p.note, 'data-id': p.id },
        p.label, el('span', { class: 'ex-expect-tag', text: p.expect }));
      b.addEventListener('click', () => {
        ctx.activePreset = p;
        [...presetBar.children].forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
        if (ctx.rawMode) ui.raw.value = JSON.stringify(p.values, null, 2);
        applyValues(ctx, p.values);
      });
      return b;
    }));

    ui.method.addEventListener('change', () => refreshUrl(ctx));
    ui.encoding.addEventListener('change', () => refreshUrl(ctx));
    ui.send.addEventListener('click', () => send(ctx));
    host.onkeydown = (ev) => { if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); send(ctx); } };

    const isStream = route.kind === 'stream';
    host.replaceChildren(...[
      el('div', { class: 'ex-bar' }, ui.method, ui.url, isStream ? null : ui.send),
      ACCESS_NOTES[route.access] ? el('p', { class: 'ex-note', text: ACCESS_NOTES[route.access] }) : null,
      pathBox ? el('div', { class: 'ex-block' }, el('h3', { text: 'Path' }), pathBox) : null,
      fields.length ? el('div', { class: 'ex-block' },
        el('div', { class: 'ex-block-head' }, el('h3', { text: 'Variations' }), el('span', { class: 'ex-dim', text: 'prepared from the contract — click to fill' })),
        presetBar) : null,
      fields.length ? el('div', { class: 'ex-block' },
        el('div', { class: 'ex-block-head' }, el('h3', { text: 'Input' }), ui.where, el('span', { class: 'ex-spacer' }), ui.encodingWrap, rawToggle),
        form, ui.raw) : null,
      el('details', { class: 'ex-block' }, el('summary', { text: 'Headers' }),
        el('div', { class: 'ex-row' }, el('span', { class: 'ex-dim', text: 'Accept' }), ui.accept), ui.headers),
      el('div', { class: 'ex-block' }, el('h3', { text: 'Response' }), isStream ? streamView(ctx) : ui.result),
      isStream ? null : el('details', { class: 'ex-block' }, el('summary', { text: 'History' }), ui.history),
    ].filter(Boolean));

    if (presets.length) presetBar.firstChild.click();
    refreshUrl(ctx);
    renderHistory(ctx);
    if (!isStream) ui.result.replaceChildren(el('p', { class: 'ex-empty', text: 'Press Send (Ctrl+Enter). The call is real — watch its effect in the Observatory.' }));
  });
})();

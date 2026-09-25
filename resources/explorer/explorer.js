/* API Explorer — find any route by kind, then open it.
   State lives in the URL hash (kind, q, all, route) so a view survives a
   reload and can be handed to a separate tab. No inline anything: this file
   is served same-origin from /__explorer/asset/explorer.js. */
(() => {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const el = (tag, attrs = {}, ...kids) => {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') n.className = v;
      else if (k === 'text') n.textContent = v;
      else n.setAttribute(k, v === true ? '' : v);
    }
    for (const kid of kids.flat()) if (kid !== null && kid !== undefined) n.append(kid);
    return n;
  };
  const short = (fqcn) => (fqcn ? String(fqcn).split('\\').pop() : '');

  const state = { groups: [], routes: [], kind: 'page', q: '', everywhere: false, cursor: 0, shown: [] };

  /* ---------- hash state ---------- */
  function readHash() {
    const p = new URLSearchParams(location.hash.slice(1));
    return { kind: p.get('kind'), q: p.get('q') || '', all: p.get('all') === '1', route: p.get('route') };
  }
  function writeHash(route) {
    const p = new URLSearchParams();
    p.set('kind', state.kind);
    if (state.q) p.set('q', state.q);
    if (state.everywhere) p.set('all', '1');
    if (route) p.set('route', route);
    history.replaceState(null, '', '#' + p.toString());
  }
  function routeHref(id) {
    const p = new URLSearchParams({ kind: state.kind, route: id });
    return '/__explorer#' + p.toString();
  }

  /* ---------- groups ---------- */
  function renderGroups() {
    const box = $('ex-groups');
    box.replaceChildren(...state.groups.map((g) => {
      const b = el('button', { type: 'button', class: 'ex-group', 'aria-pressed': String(g.kind === state.kind), 'data-kind': g.kind },
        g.label, el('b', { text: String(g.count) }));
      b.addEventListener('click', () => { state.kind = g.kind; state.cursor = 0; renderGroups(); renderList(); writeHash(); $('ex-q').focus(); });
      return b;
    }));
  }

  /* ---------- search ---------- */
  function haystack(r) {
    return [r.path, r.name, r.module, short(r.handler), short(r.payload), r.methods.join(' ')].filter(Boolean).join(' ').toLowerCase();
  }
  function matches(r, terms) {
    const h = r._h || (r._h = haystack(r));
    return terms.every((t) => h.includes(t));
  }
  function highlight(text, terms) {
    if (!terms.length) return [text];
    const lower = text.toLowerCase();
    const marks = new Array(text.length).fill(false);
    for (const t of terms) {
      let i = lower.indexOf(t);
      while (i !== -1) { for (let j = i; j < i + t.length; j++) marks[j] = true; i = lower.indexOf(t, i + t.length); }
    }
    const out = [];
    let i = 0;
    while (i < text.length) {
      let j = i;
      while (j < text.length && marks[j] === marks[i]) j++;
      const part = text.slice(i, j);
      out.push(marks[i] ? el('mark', { text: part }) : part);
      i = j;
    }
    return out;
  }

  function renderList() {
    const terms = state.q.toLowerCase().split(/\s+/).filter(Boolean);
    const pool = state.everywhere ? state.routes : state.routes.filter((r) => r.kind === state.kind);
    state.shown = pool.filter((r) => matches(r, terms));
    state.cursor = Math.min(state.cursor, Math.max(0, state.shown.length - 1));
    const labels = Object.fromEntries(state.groups.map((g) => [g.kind, g.label]));

    $('ex-list').replaceChildren(...state.shown.slice(0, 400).map((r, i) => {
      const li = el('li', { class: 'ex-item', role: 'option', tabindex: '-1', 'aria-selected': String(i === state.cursor), 'data-id': r.id },
        methodBadges(r.methods),
        el('span', { class: 'ex-path' }, highlight(r.path, terms)),
        state.everywhere ? el('span', { class: 'ex-kind', text: labels[r.kind] || r.kind }) : el('span'),
        el('span', { class: 'ex-sub', text: [r.name, r.module, short(r.handler)].filter(Boolean).join(' · ') }));
      li.addEventListener('click', () => { state.cursor = i; openRoute(r.id); });
      return li;
    }));
    const total = pool.length;
    $('ex-status').textContent = state.shown.length === total
      ? `${total} routes`
      : `${state.shown.length} of ${total} routes` + (state.shown.length > 400 ? ' — first 400 shown, refine the search' : '');
  }

  function moveCursor(delta) {
    if (!state.shown.length) return;
    state.cursor = (state.cursor + delta + state.shown.length) % state.shown.length;
    const items = $('ex-list').children;
    for (let i = 0; i < items.length; i++) items[i].setAttribute('aria-selected', String(i === state.cursor));
    items[state.cursor]?.scrollIntoView({ block: 'nearest' });
  }

  function methodBadges(methods) {
    return el('span', { class: 'ex-methods' }, methods.map((m) => el('span', { class: 'ex-m ' + m.toLowerCase(), text: m })));
  }

  /* ---------- route dialog ---------- */
  const dialog = $('ex-route');

  // Bumped by every openRoute(): a response for a route the user has since
  // left must not draw into the dialog that now shows another one.
  let routeRequest = 0;

  async function openRoute(id) {
    const ticket = ++routeRequest;
    const summary = state.routes.find((r) => r.id === id);
    $('ex-route-title').textContent = summary ? summary.path : id;
    $('ex-route-methods').replaceChildren(summary ? methodBadges(summary.methods) : '');
    $('ex-route-tab').href = routeHref(id);
    $('ex-route-body').replaceChildren(el('p', { class: 'ex-empty', text: 'Loading contract…' }));
    if (!dialog.open) dialog.showModal();
    writeHash(id);

    try {
      const res = await fetch('/__explorer/catalog?id=' + encodeURIComponent(id), { headers: { Accept: 'application/json' } });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const detail = await res.json();
      if (ticket !== routeRequest) return;
      renderRoute(detail);
      // explorer-invoke.js fills the #ex-invoke section renderRoute just drew.
      document.dispatchEvent(new CustomEvent('explorer:route', { detail }));
    } catch (e) {
      if (ticket !== routeRequest) return;
      $('ex-route-body').replaceChildren(el('p', { class: 'ex-empty', text: 'Could not load this route: ' + e.message }));
    }
  }

  function renderRoute({ route, contract }) {
    const meta = el('dl', { class: 'ex-meta' });
    const add = (k, v) => { if (v) meta.append(el('dt', { text: k }), el('dd', { text: v })); };
    add('Kind', (state.groups.find((g) => g.kind === route.kind) || {}).label);
    add('Name', route.name);
    add('Module', route.module);
    add('Access', route.access);
    add('Payload', route.payload);
    add('Handler', route.handler);
    add('Response', route.response);
    add('Profiles', route.profiles.join(', '));
    add('Transport', route.transport);

    const fields = (contract && contract.input && contract.input.fields) || (contract && contract.fields) || [];
    const pathParams = new Set(route.path_params);
    const table = fields.length
      ? el('table', { class: 'ex-table' },
          el('thead', {}, el('tr', {}, ['Field', 'Type', 'In', 'Required'].map((h) => el('th', { text: h })))),
          el('tbody', {}, fields.map((f) => el('tr', {},
            el('td', { class: 'mono', text: f.name }),
            el('td', { class: 'mono', text: (f.nullable ? '?' : '') + (f.type || 'mixed') }),
            el('td', { text: pathParams.has(f.name) ? 'path' : 'query / body' }),
            el('td', {}, f.required ? el('span', { class: 'ex-req', text: 'yes' }) : 'no')))))
      : el('p', { class: 'ex-empty', text: contract ? 'This route takes no input fields.' : 'No contract could be assembled for this route.' });

    $('ex-route-body').replaceChildren(
      el('section', { class: 'ex-sec', id: 'ex-invoke' }),
      el('details', { class: 'ex-sec' }, el('summary', { text: 'Route' }), meta),
      el('details', { class: 'ex-sec' }, el('summary', { text: 'Contract' }), table),
    );
  }

  function closeRoute() {
    if (dialog.open) dialog.close();
  }
  dialog.addEventListener('close', () => { routeRequest++; writeHash(); $('ex-q').focus(); });
  $('ex-route-close').addEventListener('click', closeRoute);
  dialog.addEventListener('click', (e) => { if (e.target === dialog) closeRoute(); });

  /* ---------- input ---------- */
  const q = $('ex-q');
  q.addEventListener('input', () => { state.q = q.value.trim(); state.cursor = 0; renderList(); writeHash(); });
  q.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); moveCursor(1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); moveCursor(-1); }
    else if (e.key === 'Enter' && state.shown[state.cursor]) { e.preventDefault(); openRoute(state.shown[state.cursor].id); }
  });
  $('ex-everywhere').addEventListener('change', (e) => { state.everywhere = e.target.checked; state.cursor = 0; renderList(); writeHash(); });
  document.addEventListener('keydown', (e) => {
    if (e.key === '/' && document.activeElement !== q && !dialog.open && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName || '')) {
      e.preventDefault(); q.focus(); q.select();
    }
  });

  // Inside the Observatory's dialog (an iframe) offer the way out to a tab.
  if (window.top !== window) {
    $('ex-detach').hidden = false;
    document.documentElement.classList.add('embedded');
  }
  $('ex-detach').addEventListener('click', (e) => { e.currentTarget.href = location.href; });

  /* ---------- boot ---------- */
  async function boot() {
    try {
      const res = await fetch('/__explorer/catalog', { headers: { Accept: 'application/json' } });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      state.groups = data.groups.filter((g) => g.count > 0 || g.kind !== 'graphql');
      state.routes = data.routes;
    } catch (e) {
      $('ex-status').textContent = 'Could not load the route catalog: ' + e.message;
      return;
    }

    const h = readHash();
    if (h.kind && state.groups.some((g) => g.kind === h.kind)) state.kind = h.kind;
    state.q = h.q; state.everywhere = h.all;
    q.value = state.q; $('ex-everywhere').checked = state.everywhere;
    renderGroups();
    renderList();
    if (h.route) openRoute(h.route); else q.focus();
  }

  window.SemitexaExplorer = { state, openRoute, el, methodBadges, short };
  boot();
})();

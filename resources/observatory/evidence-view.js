/* Observatory · Evidence view.
 *
 * The review-evidence store (var/evidence/, `bin/semitexa ai:evidence`) as a
 * table: search, filters by kind / data / visibility, a created-on date range,
 * sortable columns and pages — all answered by the server
 * (/__observatory/evidence), so the browser never holds the whole store.
 *
 * Runs under the panel's strict CSP: no inline script or style, and every
 * value from the store reaches the page through textContent or a URL built
 * here — never innerHTML. Exposes window.SemitexaEvidenceView.mount(host).
 */
(function () {
  'use strict';

  const DEFAULTS = {q: '', kind: '', data: '', visibility: '', from: '', to: '', sort: 'created', dir: 'desc', page: '1', per: '25'};
  const KEYS = Object.keys(DEFAULTS);
  const COLUMNS = [
    {key: 'created', label: 'Recorded', sort: 'created'},
    {key: 'kind', label: 'Kind', sort: 'kind'},
    {key: 'data', label: 'Data', sort: 'data'},
    {key: 'file', label: 'File', sort: 'file'},
    {key: 'size', label: 'Size', sort: 'size', cls: 'num'},
    {key: 'expires', label: 'Expires', sort: 'expires', cls: 'col-expires'},
    {key: 'note', label: 'Note', cls: 'ev-note-cell'},
  ];
  const KINDS = ['screenshot', 'recording', 'trace', 'log', 'graph-export', 'report'];
  const IMAGE = /\.(png|jpe?g|gif|webp|avif)$/i;
  const VIDEO = /\.(webm|mp4)$/i;
  const HTML = /\.html?$/i;
  const TEXT = /\.(json|ndjson|txt|log|md|csv|xml|ya?ml|dot|svg|ts|js|php)$/i;

  function el(tag, attrs, ...children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') node.className = v;
      else if (k === 'text') node.textContent = v;
      else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
      else node.setAttribute(k, v === true ? '' : String(v));
    }
    for (const c of children.flat()) if (c !== null && c !== undefined && c !== false) node.append(c instanceof Node ? c : String(c));
    return node;
  }
  const bytes = n => n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : n >= 1024 ? Math.round(n / 1024) + ' KB' : n + ' B';
  const day = iso => String(iso || '').slice(0, 10);
  const stamp = iso => String(iso || '').replace('T', ' ').slice(0, 16);

  /** The state lives in the address (#evidence?…), so a reload or a link lands on the same page. */
  function readHash() {
    const s = Object.assign({}, DEFAULTS);
    const m = /^#evidence(?:\?(.*))?$/.exec(location.hash);
    if (m && m[1]) {
      const p = new URLSearchParams(m[1]);
      for (const k of KEYS) if (p.has(k)) s[k] = p.get(k) || '';
    }
    return s;
  }
  function writeHash(s) {
    const p = new URLSearchParams();
    for (const k of KEYS) if (s[k] !== DEFAULTS[k] && s[k] !== '') p.set(k, s[k]);
    const q = p.toString();
    history.replaceState(null, '', '#evidence' + (q ? '?' + q : ''));
  }

  function mount(host, endpoint) {
    const fileEndpoint = endpoint + '/file';
    let state = readHash();
    let selected = null;
    let seq = 0;
    let timer = 0;

    const search = el('input', {type: 'search', placeholder: 'id, file, note, source, agent…', 'aria-label': 'Search evidence', value: state.q});
    const kind = el('select', {'aria-label': 'Kind'});
    const data = el('select', {'aria-label': 'Data'}, el('option', {value: '', text: 'any data'}), el('option', {value: 'synthetic', text: 'synthetic'}), el('option', {value: 'real', text: 'real'}));
    const vis = el('select', {'aria-label': 'Visibility'}, el('option', {value: '', text: 'any'}), el('option', {value: 'private', text: 'private'}), el('option', {value: 'published', text: 'published'}));
    const from = el('input', {type: 'date', 'aria-label': 'Recorded from'});
    const to = el('input', {type: 'date', 'aria-label': 'Recorded to'});
    const per = el('select', {'aria-label': 'Rows per page'}, ...[10, 25, 50, 100].map(n => el('option', {value: String(n), text: n + ' / page'})));
    const reset = el('button', {type: 'button', class: 'btn', text: 'reset'});

    const note = el('div', {class: 'ev-note', 'aria-live': 'polite'});
    const table = el('table', {class: 'ev-table'});
    const list = el('div', {class: 'ev-list panel'}, table);
    const pager = el('nav', {class: 'ev-pager panel', 'aria-label': 'Pages'});
    const side = el('aside', {class: 'ev-side panel'}, el('p', {class: 'ev-hint', text: 'Select a row to see its passport and a preview.'}));
    const bar = el('div', {class: 'ev-bar panel'},
      el('label', {class: 'ev-search'}, 'search', search),
      el('label', {}, 'kind', kind),
      el('label', {}, 'data', data),
      el('label', {}, 'visibility', vis),
      el('label', {}, 'from', from),
      el('label', {}, 'to', to),
      el('label', {}, 'rows', per),
      reset);
    host.replaceChildren(el('div', {class: 'ev'}, bar, note, list, pager, side));

    const sync = () => {
      search.value = state.q; data.value = state.data; vis.value = state.visibility;
      from.value = state.from; to.value = state.to; per.value = state.per;
    };
    const set = (patch, resetPage = true) => {
      state = Object.assign({}, state, patch, resetPage ? {page: '1'} : {});
      writeHash(state);
      load();
    };

    search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => set({q: search.value.trim()}), 250); });
    kind.addEventListener('change', () => set({kind: kind.value}));
    data.addEventListener('change', () => set({data: data.value}));
    vis.addEventListener('change', () => set({visibility: vis.value}));
    from.addEventListener('change', () => set({from: from.value}));
    to.addEventListener('change', () => set({to: to.value}));
    per.addEventListener('change', () => set({per: per.value}));
    reset.addEventListener('click', () => { state = Object.assign({}, DEFAULTS); sync(); writeHash(state); load(); });

    function load() {
      const mine = ++seq;
      const p = new URLSearchParams();
      for (const k of KEYS) if (state[k] !== '') p.set(k, state[k]);
      host.setAttribute('aria-busy', 'true');
      fetch(endpoint + '?' + p.toString(), {headers: {Accept: 'application/json'}, credentials: 'same-origin'})
        .then(r => r.json().then(body => ({ok: r.ok, body})))
        .then(({ok, body}) => {
          if (mine !== seq) return; // a newer request owns the table
          host.removeAttribute('aria-busy');
          if (!ok) { renderError(body && body.message ? body.message : 'The evidence list could not be read.'); return; }
          render(body);
        })
        .catch(() => { if (mine === seq) { host.removeAttribute('aria-busy'); renderError('The evidence list could not be read.'); } });
    }

    function renderError(message) {
      note.replaceChildren(el('span', {class: 'ev-err', role: 'alert', text: message}));
      table.replaceChildren();
      pager.replaceChildren();
    }

    function render(body) {
      // The server clamps a page past the end: follow it, so the address tells the truth.
      if (String(body.page) !== state.page) { state.page = String(body.page); writeHash(state); }
      renderKinds(body.kinds || {});
      renderNote(body);
      renderTable(body.items || []);
      renderPager(body.page, body.pages);
    }

    function renderKinds(counts) {
      const total = Object.values(counts).reduce((a, b) => a + b, 0);
      kind.replaceChildren(el('option', {value: '', text: 'all kinds (' + total + ')'}),
        ...KINDS.map(k => el('option', {value: k, text: k + ' (' + (counts[k] || 0) + ')'})));
      kind.value = state.kind;
    }

    function renderNote(body) {
      const parts = [el('span', {}, el('b', {text: String(body.total)}), ' of ', el('b', {text: String(body.stored)}), ' item(s) match')];
      if (body.inbox_pending > 0) parts.push(el('span', {class: 'ev-warn', text: body.inbox_pending + ' file(s) in the inbox — the next `ai:evidence` command records them'}));
      for (const u of body.unregistered || []) parts.push(el('span', {class: 'ev-warn', text: 'outside the store: ' + u.dir + ' — ' + u.files + ' file(s), ' + bytes(u.bytes) + ', oldest ' + (u.oldest || '?')}));
      for (const name of body.unreadable || []) parts.push(el('span', {class: 'ev-warn', text: 'var/evidence/' + name + ' has no readable passport'}));
      note.replaceChildren(...parts);
    }

    function renderTable(items) {
      const head = el('tr', {}, ...COLUMNS.map(c => {
        const sortState = c.sort && state.sort === c.sort ? (state.dir === 'asc' ? 'ascending' : 'descending') : null;
        const inner = c.sort
          ? el('button', {type: 'button', text: c.label, title: 'sort by ' + c.label.toLowerCase(), onclick: () => {
              const dir = state.sort === c.sort ? (state.dir === 'asc' ? 'desc' : 'asc') : (c.sort === 'created' || c.sort === 'size' || c.sort === 'expires' ? 'desc' : 'asc');
              // A new order starts at its first page: page 2 of another order is nobody's question.
              set({sort: c.sort, dir});
            }})
          : el('span', {text: c.label});
        return el('th', {scope: 'col', class: c.cls || null, 'aria-sort': c.sort ? (sortState || 'none') : null}, inner);
      }));
      const rows = items.map(item => {
        const tags = [el('span', {class: 'ev-tag ' + item.data, text: item.data})];
        if (item.visibility === 'published') tags.push(' ', el('span', {class: 'ev-tag published', text: 'published'}));
        const tr = el('tr', {tabindex: '0', 'data-id': item.id, class: item.id === selected ? 'sel' : null},
          el('td', {class: 'when', title: item.created_at, text: stamp(item.created_at)}),
          el('td', {text: item.kind}),
          el('td', {}, ...tags),
          el('td', {class: 'ev-file', title: item.path, text: item.file}),
          el('td', {class: 'num', text: bytes(item.bytes)}),
          el('td', {class: 'when col-expires'}, item.expired ? el('span', {class: 'ev-tag expired', text: 'expired'}) : day(item.expires_at)),
          el('td', {class: 'ev-note-cell', text: item.note}));
        const open = () => { selected = item.id; table.querySelectorAll('tr.sel').forEach(r => r.classList.remove('sel')); tr.classList.add('sel'); renderSide(item); };
        tr.addEventListener('click', open);
        tr.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
        return tr;
      });
      const bodyEl = rows.length ? el('tbody', {}, ...rows)
        : el('tbody', {}, el('tr', {}, el('td', {colspan: String(COLUMNS.length), class: 'ev-empty', text: 'Nothing matches. Evidence appears here when it is recorded with bin/semitexa ai:evidence add.'})));
      table.replaceChildren(el('thead', {}, head), bodyEl);
    }

    /** First, last, the current page and two either side; gaps shown as … */
    function renderPager(page, pages) {
      const go = n => set({page: String(n)}, false);
      const btn = (label, n, opts = {}) => el('button', {type: 'button', class: 'btn', text: label, disabled: opts.disabled || false,
        'aria-current': opts.current ? 'page' : null, 'aria-label': opts.aria || null, onclick: () => go(n)});
      const nums = [];
      let last = 0;
      for (let n = 1; n <= pages; n++) {
        if (n === 1 || n === pages || Math.abs(n - page) <= 2) {
          if (n - last > 1) nums.push(el('span', {class: 'ev-gap', text: '…'}));
          nums.push(btn(String(n), n, {current: n === page}));
          last = n;
        }
      }
      pager.replaceChildren(
        btn('‹', page - 1, {disabled: page <= 1, aria: 'previous page'}),
        ...nums,
        btn('›', page + 1, {disabled: page >= pages, aria: 'next page'}),
        el('span', {class: 'ev-of', text: 'page ' + page + ' of ' + pages}));
    }

    function renderSide(item) {
      const url = fileEndpoint + '?id=' + encodeURIComponent(item.id);
      const preview = el('div', {class: 'ev-preview'});
      if (IMAGE.test(item.file)) preview.append(el('img', {src: url, alt: item.note || item.file, loading: 'lazy'}));
      else if (VIDEO.test(item.file)) preview.append(el('video', {src: url, controls: true, preload: 'metadata'}));
      else if (HTML.test(item.file)) preview.append(el('p', {class: 'ev-hint'}, 'A page of its own (', bytes(item.bytes), '). ', el('a', {href: url, target: '_blank', rel: 'noopener noreferrer', class: 'link', text: 'open it in a new tab ↗'}), ' — it runs sandboxed, away from this panel.'));
      else if (TEXT.test(item.file)) {
        const pre = el('pre', {text: 'Loading…'});
        preview.append(pre);
        fetch(url, {credentials: 'same-origin'}).then(r => r.ok ? r.text() : Promise.reject(r.status))
          .then(t => { pre.textContent = t.length > 65536 ? t.slice(0, 65536) + '\n… (first 64 KB of ' + bytes(item.bytes) + ')' : t; })
          .catch(() => { pre.textContent = 'Could not read the file.'; });
      } else preview.append(el('p', {class: 'ev-hint'}, 'No preview for this kind of file. ', el('a', {href: url, class: 'link', text: 'download'})));

      const pubs = item.publications && item.publications.length
        ? item.publications.map(p => el('div', {text: p.to + ' — ' + stamp(p.approved_at) + ' by ' + p.approved_by}))
        : ['private: never approved for publication'];
      const fields = [
        ['id', el('code', {text: item.id})], ['kind', item.kind], ['data', item.data], ['recorded', stamp(item.created_at)],
        ['expires', item.expired ? 'expired ' + day(item.expires_at) : day(item.expires_at)], ['by', item.created_by],
        ['source', item.source], ['stored', el('code', {text: item.path})], ['size', bytes(item.bytes)],
        ['sha256', el('code', {text: item.sha256})], ['published', el('div', {}, ...pubs)],
      ];
      side.replaceChildren(
        el('h3', {text: item.file}),
        item.note ? el('p', {class: 'ev-hint', text: item.note}) : null,
        preview,
        el('dl', {}, ...fields.flatMap(([k, v]) => [el('dt', {text: k}), el('dd', {}, v)])),
        el('p', {class: 'ev-hint'}, 'To publish it, the operator runs in their own terminal: ', el('code', {text: 'bin/semitexa ai:evidence publish ' + item.id + ' --to="…"'})));
    }

    sync();
    load();
    return {
      // Coming back to the view: the address says what the table shows again.
      reload: () => { writeHash(state); load(); },
      focusSearch: () => search.focus(),
      fromHash: () => { const next = readHash(); if (KEYS.some(k => next[k] !== state[k])) { state = next; sync(); load(); } },
    };
  }

  window.SemitexaEvidenceView = {mount};
})();

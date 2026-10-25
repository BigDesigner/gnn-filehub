/* GNN Filehub - Zero Dependency Native Public Drag & Drop, Manager, Password & Register JS */

/*
 * Live theming: measures the page's actual rendered background color and toggles
 * .filehub-theme-dark on every .filehub-container so our cards always match the theme's
 * CURRENT state — whatever mechanism the theme uses to switch light/dark (OS preference,
 * a manual toggle button, a class or data-attribute on <html>/<body>) — instead of guessing
 * from a single signal like prefers-color-scheme, which can disagree with what the page is
 * actually showing.
 */
(function () {
  function relativeLuminance(rgbString) {
    var m = rgbString && rgbString.match(/[\d.]+/g);
    if (!m || m.length < 3) return 1; // assume light if we can't tell
    var r = Number(m[0]), g = Number(m[1]), b = Number(m[2]);
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  }

  function getEffectiveBackgroundColor(startEl) {
    var el = startEl;
    while (el && el !== document.documentElement) {
      var bg = getComputedStyle(el).backgroundColor;
      if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') {
        return bg;
      }
      el = el.parentElement;
    }
    var htmlBg = getComputedStyle(document.documentElement).backgroundColor;
    if (htmlBg && htmlBg !== 'rgba(0, 0, 0, 0)') return htmlBg;
    return getComputedStyle(document.body).backgroundColor;
  }

  // Themes that explicitly mark their mode (data-theme="dark", a "dark"/"light" class, or the
  // color-scheme CSS property) are trusted first, since that flips the instant a toggle button
  // is clicked. Measuring the rendered background is only a fallback for themes that don't
  // expose any such signal — on some sites the visual background can lag behind the toggle by
  // a beat (transitions, caching/CSS-combination plugins reordering rules, etc.), so preferring
  // the theme's own explicit signal is more reliable than inferring from paint state.
  function detectExplicitThemeSignal() {
    var html = document.documentElement;
    var body = document.body;

    var htmlTheme = (html.getAttribute('data-theme') || '').toLowerCase();
    var bodyTheme = (body.getAttribute('data-theme') || '').toLowerCase();
    var dt = htmlTheme || bodyTheme;
    if (dt) {
      if (dt.indexOf('dark') !== -1) return 'dark';
      if (dt.indexOf('light') !== -1) return 'light';
    }

    if (html.classList.contains('dark') || body.classList.contains('dark') || body.classList.contains('dark-mode')) {
      return 'dark';
    }
    if (html.classList.contains('light') || body.classList.contains('light') || body.classList.contains('light-mode')) {
      return 'light';
    }

    var colorScheme = getComputedStyle(html).colorScheme;
    if (colorScheme === 'dark') return 'dark';
    if (colorScheme === 'light') return 'light';

    return null;
  }

  function applyFilehubTheming() {
    var containers = document.querySelectorAll('.filehub-container');
    if (!containers.length) return;

    var explicit = detectExplicitThemeSignal();

    containers.forEach(function (container) {
      var isDark;
      if (explicit) {
        isDark = explicit === 'dark';
      } else {
        var bg = getEffectiveBackgroundColor(container.parentElement || document.body);
        isDark = relativeLuminance(bg) < 0.5;
      }
      container.classList.toggle('filehub-theme-dark', isDark);
    });
  }

  function init() {
    applyFilehubTheming();

    if (typeof MutationObserver !== 'undefined') {
      var observer = new MutationObserver(function () {
        applyFilehubTheming();
      });
      observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme', 'style'] });
      observer.observe(document.body, { attributes: true, attributeFilter: ['class', 'data-theme', 'style'] });
    }

    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      if (mq.addEventListener) {
        mq.addEventListener('change', applyFilehubTheming);
      } else if (mq.addListener) {
        mq.addListener(applyFilehubTheming);
      }
    }

    // Belt-and-suspenders: some toggles change styling through mechanisms our observer can't
    // see (a swapped stylesheet, a framework re-render). Re-checking every couple of seconds
    // guarantees we self-correct quickly regardless of the theme's exact mechanism.
    setInterval( applyFilehubTheming, 2000 );
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

document.addEventListener('DOMContentLoaded', function () {
  'use strict';
  if (!window.filehub_vars) return;
  const config = window.filehub_vars;
  const t = key => config.strings[key] || key;
  const base = new URL(config.api_url);
  function endpoint(path) {
    const url = new URL(base.href);
    const split = path.indexOf('?');
    const route = split < 0 ? path : path.slice(0, split);
    if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/?$/, '/') + route);
    else url.pathname = base.pathname.replace(/\/?$/, '/') + route;
    if (split >= 0) new URLSearchParams(path.slice(split + 1)).forEach((value, key) => url.searchParams.set(key, value));
    return url;
  }
  const node = (tag, text, cls) => {
    const el = document.createElement(tag);
    if (text !== undefined) el.textContent = String(text);
    if (cls) el.className = cls;
    return el;
  };
  const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
  async function api(path, options = {}, token = '', attempts = 3) {
    for (let attempt = 0; ; attempt++) {
      try {
        const headers = { 'X-WP-Nonce': config.nonce, ...options.headers };
        if (token) headers['X-FileHub-Token'] = token;
        const response = await fetch(endpoint(path), { credentials: 'same-origin', ...options, headers });
        const data = await response.json();
        if (!response.ok || data.success === false) {
          const error = new Error(data.message || data.error || t('error'));
          error.status = response.status;
          throw error;
        }
        return { data, response };
      } catch (error) {
        if (error.name === 'AbortError' || attempt >= attempts || (error.status && ![409,429,500,502,503,504].includes(error.status))) throw error;
        await sleep(Math.min(8000, 500 * Math.pow(2, attempt)));
      }
    }
  }
  const json = data => ({ method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });

  document.querySelectorAll('.filehub-container input[type="password"]').forEach(input => {
    const button = node('button', t('show'), 'filehub-password-toggle');
    button.type = 'button'; button.setAttribute('aria-label', t('show'));
    input.insertAdjacentElement('afterend', button);
    button.addEventListener('click', () => {
      const visible = input.type === 'password'; input.type = visible ? 'text' : 'password';
      button.textContent = t(visible ? 'hide' : 'show'); button.setAttribute('aria-pressed', String(visible));
    });
  });

  const drop = document.getElementById('filehub-dropzone');
  const input = document.getElementById('filehub-file-input');
  const status = document.getElementById('filehub-status-text');
  const bar = document.getElementById('filehub-progress-bar');
  const fill = document.getElementById('filehub-progress-fill');
  const cancel = document.getElementById('filehub-upload-cancel');
  let active = false, cancelled = false, controller = null, session = null, currentFile = null;
  const queue = [];
  function progress(percent, message) {
    if (bar) { bar.style.display = 'block'; bar.setAttribute('role', 'progressbar'); bar.setAttribute('aria-valuemin', '0'); bar.setAttribute('aria-valuemax', '100'); bar.setAttribute('aria-valuenow', String(percent)); bar.setAttribute('aria-label', t('uploading')); }
    if (fill) fill.style.width = percent + '%';
    if (status) status.textContent = message;
  }
  function storageKey(file) { return 'filehub:' + base.href + ':' + config.user_id + ':' + file.name + ':' + file.size + ':' + file.lastModified; }
  function stored(file) { try { return JSON.parse(sessionStorage.getItem(storageKey(file))); } catch (_) { return null; } }
  function remember(file, data) { try { sessionStorage.setItem(storageKey(file), JSON.stringify(data)); } catch (_) {} }
  function forget(file) { try { sessionStorage.removeItem(storageKey(file)); } catch (_) {} }
  async function upload(file) {
    const saved = stored(file); let state;
    session = null;
    if (saved && /^[a-f0-9-]{36}$/.test(saved.id) && /^[a-f0-9]{64}$/.test(saved.token)) {
      session = saved;
      try { state = (await api('uploads/' + saved.id, { signal: controller.signal }, saved.token)).data; }
      catch (error) { if (![404,410].includes(error.status)) throw error; session = null; forget(file); }
    }
    if (!session) {
      const result = await api('uploads', { ...json({ filename: file.name, size: file.size }), signal: controller.signal }, '', 0);
      state = result.data; session = { id: state.upload_id, token: state.upload_token };
      remember(file, session);
    }
    if (state.status === 'done' && Number.isInteger(state.attachment_id)) { forget(file); return; }
    const received = new Set(state.received || []);
    const hashes = state.received_hashes || {};
    const started = performance.now(); let sent = 0;
    if (state.status === 'receiving') {
      for (let index = 0; index < state.total_chunks; index++) {
        if (cancelled) throw new DOMException(t('cancelled'), 'AbortError');
        const chunk = file.slice(index * state.chunk_size, Math.min(file.size, (index + 1) * state.chunk_size));
        // Check resumed content against the server hash; never mix two different files.
        if (received.has(index) && window.crypto && crypto.subtle && hashes[index]) {
          const digest = await crypto.subtle.digest('SHA-256', await chunk.arrayBuffer());
          const hash = Array.from(new Uint8Array(digest), b => b.toString(16).padStart(2, '0')).join('');
          if (hash !== hashes[index]) throw new Error(t('chooseAgain'));
        } else {
          const form = new FormData(); form.append('chunk', chunk, file.name);
          await api('uploads/' + session.id + '/chunks/' + index, { method: 'POST', body: form, signal: controller.signal }, session.token);
        }
        sent += chunk.size;
        const percent = Math.min(99, Math.round(sent / file.size * 100));
        const speed = (sent / 1048576 / Math.max(0.1, (performance.now() - started) / 1000)).toFixed(2);
        progress(percent, file.name + ': ' + t('uploading') + ' ' + percent + '% (' + speed + ' MB/s)');
      }
    }
    do {
      if (cancelled) throw new DOMException(t('cancelled'), 'AbortError');
      progress(99, file.name + ': ' + t('processing'));
      state = (await api('uploads/' + session.id + '/complete', { method: 'POST', signal: controller.signal }, session.token)).data;
      if (!['processing','done'].includes(state.status)) throw new Error(t('error'));
    } while (state.status !== 'done');
    if (!Number.isInteger(state.attachment_id) || state.attachment_id < 1) throw new Error(t('error'));
    forget(file); progress(100, file.name + ': ' + t('done'));
  }
  async function runQueue() {
    if (active) return;
    active = true; let failures = 0;
    while (queue.length) {
      const file = queue.shift(); currentFile = file; cancelled = false; controller = new AbortController();
      if (cancel) cancel.hidden = false;
      try { await upload(file); }
      catch (error) {
        failures++;
        if (status) status.textContent = error.name === 'AbortError' ? t('cancelled') : (error.message || t('network')) + ' ' + t('chooseAgain');
      }
      session = null; await refreshLists();
    }
    active = false; if (cancel) cancel.hidden = true;
    if (bar) bar.style.display = 'none';
    if (failures && status) status.textContent += ' ' + t('failedFiles');
    if (input) input.value = '';
  }
  if (drop && input) {
    drop.addEventListener('click', () => input.click());
    drop.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); input.click(); } });
    ['dragenter','dragover','dragleave','drop'].forEach(name => drop.addEventListener(name, event => { event.preventDefault(); event.stopPropagation(); drop.classList.toggle('filehub-highlight', name === 'dragenter' || name === 'dragover'); }));
    drop.addEventListener('drop', event => { queue.push(...Array.from(event.dataTransfer.files)); runQueue(); });
    input.addEventListener('change', () => { queue.push(...Array.from(input.files)); runQueue(); });
  }
  if (cancel) cancel.addEventListener('click', async () => {
    cancelled = true; queue.length = 0; if (controller) controller.abort();
    const current = session, file = currentFile;
    if (current) {
      cancel.disabled = true;
      try { const result = await api('uploads/' + current.id, { method: 'DELETE' }, current.token); if (file) forget(file); if (status) status.textContent = t(result.data.status === 'done' ? 'done' : 'cancelled'); }
      catch (error) { if (status) status.textContent = error.message; }
      finally { cancel.disabled = false; }
    }
  });

  const listStates = new WeakMap();
  function stateFor(container) {
    if (!listStates.has(container)) listStates.set(container, { page: 1, search: '', request: null, timers: new Set() });
    return listStates.get(container);
  }
  function downloadUrl(raw) {
    try { const url = new URL(raw); return url.origin === base.origin && (url.searchParams.has('rest_route') ? /^\/filehub\/v1\/download\/\d+$/.test(url.searchParams.get('rest_route')) : url.pathname.startsWith(endpoint('download/').pathname)) && ['http:','https:'].includes(url.protocol) ? url.href : ''; }
    catch (_) { return ''; }
  }
  async function preview(item) {
    if (item.size_bytes > 8 * 1048576 || !['image/jpeg','image/png','image/gif','image/webp','application/pdf'].includes(item.mime_type)) throw new Error(t('previewLimit'));
    const url = downloadUrl(item.download_url); if (!url) throw new Error(t('error'));
    const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce } });
    if (!response.ok || Number(response.headers.get('Content-Length')) > 8 * 1048576) throw new Error(t('previewLimit'));
    const blob = await response.blob(); if (blob.size > 8 * 1048576 || blob.type !== item.mime_type) throw new Error(t('previewLimit'));
    const objectUrl = URL.createObjectURL(blob); const dialog = node('dialog', undefined, 'filehub-preview');
    const close = node('button', t('close'), 'button'); close.type = 'button';
    const media = node(item.mime_type === 'application/pdf' ? 'iframe' : 'img');
    if (media.tagName === 'IFRAME') { media.setAttribute('sandbox', ''); media.title = item.file_name; } else media.alt = item.file_name;
    media.src = objectUrl; dialog.append(close, media); document.body.append(dialog);
    dialog.addEventListener('close', () => { URL.revokeObjectURL(objectUrl); dialog.remove(); }, { once: true });
    close.addEventListener('click', () => dialog.close()); dialog.showModal();
  }
  function render(container, items, pages) {
    const state = stateFor(container); state.timers.forEach(clearTimeout); state.timers.clear(); container.replaceChildren();
    const all = container.getAttribute('data-scope') === 'all';
    const wrap = node('div', undefined, 'filehub-table-wrap'); const table = node('table', undefined, 'filehub-table');
    const head = node('thead'), tr = node('tr');
    ['file','size','driver',...(all ? ['owner'] : []),'downloads','actions'].forEach(key => tr.append(node('th', t(key))));
    head.append(tr); const body = node('tbody'); table.append(head, body); wrap.append(table); container.append(wrap);
    if (!items.length) { const row = node('tr'), cell = node('td', t('empty')); cell.colSpan = all ? 6 : 5; row.append(cell); body.append(row); }
    items.forEach(item => {
      const row = node('tr'); row.append(node('td', item.file_name || item.title), node('td', item.file_size), node('td', item.driver));
      if (all) row.append(node('td', item.author_name + ' (#' + item.author_id + ')'));
      row.append(node('td', item.download_count)); const actions = node('td'); row.append(actions); body.append(row);
      const href = downloadUrl(item.download_url);
      if (href) { const link = node('a', t('download'), 'filehub-action-btn filehub-btn-download'); link.href = href; link.target = '_blank'; link.rel = 'noopener noreferrer'; actions.append(link); }
      if (href && item.size_bytes <= 8 * 1048576 && ['image/jpeg','image/png','image/gif','image/webp','application/pdf'].includes(item.mime_type)) {
        const button = node('button', t('preview'), 'filehub-action-btn'); button.type = 'button'; actions.append(button);
        button.addEventListener('click', async () => { button.disabled = true; try { await preview(item); } catch (error) { button.textContent = error.message; } finally { button.disabled = false; } });
      }
      if (item.can_delete) {
        const button = node('button', t('delete'), 'filehub-action-btn filehub-btn-delete'); button.type = 'button'; actions.append(button);
        let confirming = false, timer = null;
        button.addEventListener('click', async () => {
          if (!confirming) { confirming = true; button.textContent = t('confirm'); timer = setTimeout(() => { confirming = false; button.textContent = t('delete'); state.timers.delete(timer); }, 3000); state.timers.add(timer); return; }
          clearTimeout(timer); state.timers.delete(timer); button.disabled = true;
          try { await api('files/' + item.id, { method: 'DELETE' }); await refreshLists(); }
          catch (error) { button.textContent = error.message; button.disabled = false; }
        });
      }
    });
    const nav = node('div', undefined, 'filehub-pagination');
    for (const [key, target] of [['previous',state.page-1],['next',state.page+1]]) {
      const button = node('button', t(key), 'button'); button.type = 'button'; button.disabled = target < 1 || target > pages;
      button.addEventListener('click', () => { state.page = target; fetchList(container); }); nav.append(button);
    }
    nav.setAttribute('aria-label', String(state.page) + '/' + Math.max(1,pages)); container.append(nav);
  }
  async function fetchList(container) {
    const state = stateFor(container); if (state.request) state.request.abort();
    const request = new AbortController(); state.request = request;
    const query = new URLSearchParams({ scope: container.getAttribute('data-scope') || 'own', per_page: container.getAttribute('data-per-page') || '25', page: String(state.page), search: state.search });
    try {
      const { data, response } = await api('files?' + query.toString(), { signal: request.signal });
      if (state.request !== request) return;
      if (!Array.isArray(data)) throw new Error(t('error'));
      const pages = Number(response.headers.get('X-WP-TotalPages')) || 0;
      if (state.page > Math.max(1,pages)) { state.page = Math.max(1,pages); return fetchList(container); }
      render(container, data, pages);
    } catch (error) {
      if (error.name === 'AbortError' || state.request !== request) return;
      const message = node('p', error.message || t('network')); const retry = node('button', t('retry'), 'button'); retry.type = 'button';
      retry.addEventListener('click', () => fetchList(container)); container.replaceChildren(message, retry);
    }
  }
  function refreshLists() { return Promise.all(Array.from(document.querySelectorAll('.filehub-file-list'), fetchList)); }
  document.querySelectorAll('.filehub-search-input').forEach(search => {
    let timer = null;
    search.addEventListener('input', () => {
      clearTimeout(timer); timer = setTimeout(() => {
        const scope = search.closest('.filehub-uploader, .filehub-manager'); const container = scope && scope.querySelector('.filehub-file-list');
        if (!container) return; const state = stateFor(container); state.page = 1; state.search = search.value; fetchList(container);
      }, 250);
    });
  });
  function wireForm(id, statusId, path, fields) {
    const form = document.getElementById(id), message = document.getElementById(statusId); if (!form) return;
    form.addEventListener('submit', async event => {
      event.preventDefault(); const button = form.querySelector('[type="submit"]'); if (button.disabled) return;
      button.disabled = true; if (message) { message.setAttribute('role','status'); message.textContent = t(path === 'register' ? 'registering' : 'updating'); }
      const values = {}; fields.forEach(([key, field]) => { const el = document.getElementById(field); values[key] = el ? el.value : ''; });
      try {
        const { data } = await api(path, json(values), '', 0);
        if (message) message.textContent = data.message || t('saved');
        if (data.display_name) { const title = document.getElementById('filehub-profile-display-name'); if (title) title.textContent = data.display_name; }
        if (data.reload) { if (message) message.textContent = t('refresh'); window.location.reload(); }
        if (path === 'register' || id === 'filehub-password-form') form.reset();
      } catch (error) { if (message) message.textContent = error.message || t('network'); }
      finally { button.disabled = false; }
    });
  }
  wireForm('filehub-displayname-form','filehub-displayname-status','update-profile',[['display_name','filehub_display_name']]);
  wireForm('filehub-password-form','filehub-password-status','update-profile',[['current_password','filehub_current_password'],['new_password','filehub_new_password'],['confirm_password','filehub_confirm_password']]);
  wireForm('filehub-register-form','filehub-register-status','register',[['username','filehub_reg_username'],['email','filehub_reg_email'],['first_name','filehub_reg_first_name'],['last_name','filehub_reg_last_name'],['password','filehub_reg_password'],['confirm_password','filehub_reg_confirm_password']]);
  refreshLists();
});

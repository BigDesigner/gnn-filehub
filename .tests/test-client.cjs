// Execute the actual client asset in a minimal DOM. No framework/runtime dependency is shipped.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { webcrypto } = require('node:crypto');
const source = fs.readFileSync('gnn-filehub/assets/js/filehub-public.js', 'utf8');
class Element {
  constructor(tag = 'div') { this.tagName = tag.toUpperCase(); this.children = []; this.attrs = {}; this.listeners = {}; this.style = {}; this.textContent = ''; this.hidden = false; this.disabled = false; this.value = ''; this.classList = { toggle() {}, contains() { return false; } }; }
  append(...elements) { this.children.push(...elements); }
  replaceChildren(...elements) { this.children = elements; }
  setAttribute(key, value) { this.attrs[key] = value; }
  getAttribute(key) { return this.attrs[key] || null; }
  addEventListener(name, callback) { this.listeners[name] = callback; }
  querySelectorAll() { return []; }
  querySelector() { return null; }
  set innerHTML(value) { throw new Error('Unsafe dynamic HTML sink used'); }
}
const tick = () => new Promise(resolve => setTimeout(resolve, 5));
async function scenario(apiUrl) {
  const callbacks = [], requests = [], store = new Map();
  const list = new Element(); list.setAttribute('data-scope', 'own');
  const ids = {};
  for (const id of ['filehub-dropzone','filehub-file-input','filehub-status-text','filehub-progress-bar','filehub-progress-fill','filehub-upload-cancel']) ids[id] = new Element();
  const document = { readyState: 'loading', documentElement: new Element(), body: new Element(), addEventListener(name, callback) { callbacks.push(callback); }, getElementById(id) { return ids[id] || null; }, querySelectorAll(selector) { return selector === '.filehub-file-list' ? [list] : []; }, createElement(tag) { return new Element(tag); } };
  const id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', token = 'a'.repeat(64);
  let completed = 0, chunks = 0;
  const fetch = async (url, options) => {
    const u = new URL(url), route = u.searchParams.get('rest_route') || u.pathname;
    requests.push({ url: u.href, route, method: options.method || 'GET' });
    assert.equal(u.origin, 'https://site.invalid');
    let data;
    if (route === '/filehub/v1/files' || route === '/sub/wp-json/filehub/v1/files') {
      data = [{ id: 7, file_name: '<svg onload=alert(1)>', title: 'unsafe', file_size: '1 B', driver: '<img onerror=alert(1)>', author_name: 'owner', author_id: 1, download_count: 0, download_url: 'javascript:alert(1)', can_delete: true, size_bytes: 1, mime_type: 'text/plain' }];
    } else if (route.endsWith('/uploads')) {
      data = { success: true, upload_id: id, upload_token: token, status: 'receiving', total_chunks: 3, chunk_size: 4, received: [] };
    } else if (route.includes('/chunks/')) {
      assert.equal(options.headers['X-FileHub-Token'], token); assert(options.body.get('chunk')); chunks++;
      data = { success: true, status: 'receiving' };
    } else if (route.endsWith('/complete')) {
      completed++;
      data = completed === 1 ? { success: true, status: 'processing', transferred: 8 } : { success: true, status: 'done', attachment_id: 77 };
    } else throw new Error('Wrong API path: ' + route);
    return { ok: true, status: 200, json: async () => data, headers: { get(name) { return name === 'X-WP-TotalPages' ? '1' : null; } } };
  };
  const config = { api_url: apiUrl, nonce: 'synthetic', user_id: 1, strings: {} };
  const context = { document, window: { filehub_vars: config, crypto: webcrypto }, crypto: webcrypto, fetch, URL, URLSearchParams, FormData, Blob, File, AbortController, DOMException, Uint8Array, performance, console, setTimeout, clearTimeout, setInterval() {}, sessionStorage: { getItem(key) { return store.get(key) || null; }, setItem(key, value) { store.set(key, value); }, removeItem(key) { store.delete(key); } } };
  vm.runInNewContext(source, context);
  callbacks[callbacks.length - 1](); await tick();
  const collect = element => [element, ...element.children.flatMap(collect)];
  assert(collect(list).some(el => el.textContent === '<svg onload=alert(1)>'));
  assert(!collect(list).some(el => el.tagName === 'A'), 'Unsafe download URL created a link');
  assert(requests[0].route.endsWith('/filehub/v1/files'));
  ids['filehub-file-input'].files = [new File(['abcdefghijkl'], 'safe.txt', { lastModified: 123 })];
  ids['filehub-file-input'].listeners.change();
  for (let i = 0; i < 100 && completed < 2; i++) await tick();
  await tick();
  assert.equal(chunks, 3); assert.equal(completed, 2);
  assert(ids['filehub-status-text'].textContent.includes('done'), ids['filehub-status-text'].textContent);
  assert.equal(store.size, 0, 'Committed upload resume token was not cleared');
  assert.equal(ids['filehub-upload-cancel'].hidden, true);
  return { apiUrl, checks: 7 };
}
(async () => {
  const scenarios = [];
  scenarios.push(await scenario('https://site.invalid/sub/wp-json/filehub/v1/'));
  scenarios.push(await scenario('https://site.invalid/index.php?rest_route=/filehub/v1/'));
  const result = { mode: 'actual JS asset, minimal DOM and HTTP contract stub', passed: 14, scenarios };
  fs.writeFileSync('.tests/runtime/client-results.json', JSON.stringify(result, null, 2));
  console.log(JSON.stringify(result, null, 2));
})().catch(error => { console.error(error); process.exitCode = 1; });

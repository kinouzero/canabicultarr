import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = (await readFile(new URL('../../pwa/sw.js', import.meta.url), 'utf8'))
  .replace('__PUSH_MESSAGES__', JSON.stringify({ en: { opened: 'Generic opened', recovered: 'Generic recovered', test: 'Test received' }, fr: { opened: 'Ouverte', recovered: 'Rétablie', test: 'Test reçu' } }))
  .replace('__OFFLINE_VERSION__', 'fixture-v1')
  .replace('__OFFLINE_INTEGRITY__', 'sha256-' + Buffer.alloc(32).toString('base64'));
const origin = 'https://app.example.test';
const prefix = 'canabicultarr-public-offline-';
const current = prefix + 'fixture-v1';
function harness() {
  const handlers = new Map();
  const stores = new Map();
  const writes = [];
  const requests = [];
  const notifications = [];
  const opened = [];
  let windows = [];
  let closed = 0;
  let network = () => new Response('<html>Public bilingual fallback</html>', { headers: { 'Content-Type': 'text/html' } });
  const context = {
    URL, Request, Response,
    self: { location: { origin }, addEventListener: (type, handler) => handlers.set(type, handler),
      registration: { showNotification: async (title, options) => { notifications.push({ title, ...options }); } },
      clients: { matchAll: async () => windows, openWindow: async path => { opened.push(path); } },
    },
    caches: {
      keys: async () => [...stores.keys()],
      delete: async key => stores.delete(key),
      open: async key => {
        if (!stores.has(key)) stores.set(key, new Map());
        return {
          put: async (url, response) => { writes.push(url); stores.get(key).set(url, response.clone()); },
          match: async url => stores.get(key).get(url)?.clone(),
        };
      },
    },
    fetch: async request => { requests.push(request); return network(request); },
  };
  vm.runInNewContext(source, context);
  return {
    stores, writes, requests, notifications, opened,
    async push(value, malformed = false) {
      let work;
      handlers.get('push')({ data: { json: () => { if (malformed) throw Error('Bad payload'); return value; } }, waitUntil: promise => { work = promise; } });
      await work;
    },
    async click(clients) {
      windows = clients; let work;
      handlers.get('notificationclick')({ notification: { close: () => { closed++; } }, waitUntil: promise => { work = promise; } });
      await work; return closed;
    },
    message(type) {
      const replies = [];
      handlers.get('message')({ data: { type }, ports: [{ postMessage: value => replies.push(value) }] });
      return replies;
    },
    network: fn => { network = fn; },
    async lifecycle(type) { let work; handlers.get(type)({ waitUntil: promise => { work = promise; } }); await work; },
    fetch(path, overrides = {}) {
      let result;
      handlers.get('fetch')({ request: { url: new URL(path, origin).href, method: 'GET', mode: 'navigate', ...overrides }, respondWith: promise => { result = promise; } });
      return result;
    },
  };
}

test('installation fetches only the public fallback without credentials, with integrity and no redirects', async () => {
  const worker = harness(); await worker.lifecycle('install');
  assert.equal(worker.requests.length, 1);
  const request = worker.requests[0];
  assert.equal(request.url, origin + '/offline.html'); assert.equal(request.credentials, 'omit');
  assert.equal(request.redirect, 'error'); assert.equal(request.cache, 'reload'); assert.match(request.integrity, /^sha256-/);
  assert.deepEqual(worker.writes, [origin + '/offline.html']);
});

test('private API, authentication, downloads, assets and foreign URLs bypass interception', () => {
  const worker = harness();
  for (const path of ['/api/v1/auth/oidc/callback?code=fictional', '/api/v1/auth/user', '/api/v1/documents/fictional/download', '/api/v1/reports/sensors/fictional/export', '/assets/main.js', '/offline.html', 'https://foreign.example.test/']) {
    assert.equal(worker.fetch(path), undefined, path);
  }
  assert.equal(worker.fetch('/', { method: 'POST' }), undefined);
  assert.equal(worker.fetch('/', { mode: 'cors' }), undefined);
  assert.equal(worker.requests.length, 0);
});

test('online navigations including error responses remain untouched and never enter Cache Storage', async () => {
  const worker = harness(); await worker.lifecycle('install');
  for (const status of [200, 401, 403, 500, 503]) {
    worker.network(() => new Response('Fictional private response', { status }));
    const response = await worker.fetch('/?notice=fictional'); assert.equal(response.status, status);
    assert.equal(await response.text(), 'Fictional private response');
  }
  assert.deepEqual(worker.writes, [origin + '/offline.html']);
});

test('lost navigation network returns only public offline HTML with no-store, CSP and 503', async () => {
  const worker = harness(); await worker.lifecycle('install');
  worker.network(() => { throw new TypeError('Network unavailable'); });
  const response = await worker.fetch('/');
  assert.equal(response.status, 503); assert.equal(response.headers.get('Cache-Control'), 'no-store');
  assert.match(response.headers.get('Content-Security-Policy'), /default-src 'none'/);
  assert.equal(response.headers.get('X-Content-Type-Options'), 'nosniff');
  assert.equal(response.headers.get('Referrer-Policy'), 'no-referrer');
  assert.equal(await response.text(), '<html>Public bilingual fallback</html>');
  assert.deepEqual(worker.writes, [origin + '/offline.html']);
});

test('missing fallback is a network error rather than an invented app response', async () => {
  const worker = harness(); worker.network(() => { throw new TypeError('Offline'); });
  assert.equal((await worker.fetch('/')).type, 'error');
});

test('failed public fallback download rejects worker installation without caching an error page', async () => {
  for (const [status, type] of [[503, 'text/html'], [200, 'application/json']]) {
    const worker = harness(); worker.network(() => new Response('Not a public fallback', { status, headers: { 'Content-Type': type } }));
    await assert.rejects(worker.lifecycle('install'), /Public offline fallback unavailable/);
    assert.deepEqual(worker.writes, []);
  }
});

test('activation deletes only obsolete caches owned by this offline feature', async () => {
  const worker = harness(); await worker.lifecycle('install');
  worker.stores.set(prefix + 'obsolete', new Map()); worker.stores.set('unrelated-application-cache', new Map());
  await worker.lifecycle('activate');
  assert.deepEqual([...worker.stores.keys()].sort(), [current, 'unrelated-application-cache'].sort());
});


test('push displays localized generic content without private names, readings or supplied URLs', async () => {
  const worker = harness();
  await worker.push({ event: 'recovered', locale: 'fr', id: '00000000-0000-4000-8000-000000000001', title: 'Private name', body: 'Secret reading', url: 'https://evil.example.test/' });
  assert.equal(worker.notifications.length, 1);
  assert.equal(worker.notifications[0].title, 'Canabicultarr');
  assert.equal(worker.notifications[0].body, 'Rétablie');
  assert.equal(worker.notifications[0].renotify, false);
  assert.doesNotMatch(JSON.stringify(worker.notifications), /Private name|Secret reading|evil/);
  assert.deepEqual(worker.writes, []);
});

test('malformed push payload still shows safe generic text and a fixed tag', async () => {
  const worker = harness(); await worker.push(null, true);
  assert.equal(worker.notifications[0].body, 'Generic opened');
  assert.equal(worker.notifications[0].tag, 'canabicultarr-opened-general');
});

test('notification click focuses an existing root app without navigating away from its draft', async () => {
  const worker = harness(); let focused = 0;
  const closed = await worker.click([{ url: origin + '/', focus: async () => { focused++; } }]);
  assert.equal(closed, 1); assert.equal(focused, 1); assert.deepEqual(worker.opened, []);
});

test('notification click never focuses API or foreign windows and opens only the app root', async () => {
  const worker = harness();
  const bad = () => { throw Error('Unexpected focus'); };
  await worker.click([{ url: origin + '/api/v1/auth/oidc/callback', focus: bad }, { url: 'https://foreign.example.test/', focus: bad }]);
  assert.deepEqual(worker.opened, ['/']);
});

test('capabilities handshake returns only the supported push version', () => {
  const worker = harness();
  assert.equal(JSON.stringify(worker.message('canabicultarr.push-capabilities')), '[{"pushVersion":1}]');
  assert.deepEqual(worker.message('unknown'), []);
});

test('connection test pushes display test content without inventing an alert', async () => {
 const worker = harness();
 await worker.push({ event: 'test', locale: 'fr' });
 assert.equal(worker.notifications[0].body, 'Test reçu');
});

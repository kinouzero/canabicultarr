/* Only the build-generated public fallback may enter this worker's cache. */
const CACHE_PREFIX = 'canabicultarr-public-offline-';
const CACHE_NAME = CACHE_PREFIX + '__OFFLINE_VERSION__';
const OFFLINE_URL = new URL('/offline.html', self.location.origin).href;

self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const request = new Request(OFFLINE_URL, {
      credentials: 'omit', cache: 'reload', redirect: 'error',
      integrity: '__OFFLINE_INTEGRITY__',
    });
    const response = await fetch(request);
    if (!response.ok || !response.headers.get('Content-Type')?.includes('text/html')) {
      throw new Error('Public offline fallback unavailable.');
    }
    const cache = await caches.open(CACHE_NAME);
    await cache.put(OFFLINE_URL, response);
  })());
});

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter(key => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME).map(key => caches.delete(key)));
  })());
});

self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || request.mode !== 'navigate' ||
      url.origin !== self.location.origin || url.pathname !== '/') return;

  event.respondWith((async () => {
    try {
      return await fetch(request);
    } catch {
      const cache = await caches.open(CACHE_NAME);
      const fallback = await cache.match(OFFLINE_URL);
      if (!fallback) return Response.error();
      return new Response(fallback.body, { status: 503, headers: {
        'Content-Type': 'text/html; charset=UTF-8',
        'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff',
        'Content-Security-Policy': "default-src 'none'; script-src '__OFFLINE_SCRIPT_HASH__'; style-src 'unsafe-inline'; img-src blob:; base-uri 'none'; form-action 'none'",
        'Referrer-Policy': 'no-referrer',
      } });
    }
  })());
});

const PUSH_MESSAGES = __PUSH_MESSAGES__;
self.addEventListener('message', event => {
  if (event.data?.type === 'canabicultarr.push-capabilities' && event.ports?.[0]) event.ports[0].postMessage({ pushVersion: 1 });
});
self.addEventListener('push', event => {
  let data = {};
  try { data = event.data?.json() ?? {}; } catch { /* Always show a safe generic notification. */ }
  const locale = data?.locale === 'fr' ? 'fr' : 'en';
  const kind = data?.event === 'test' ? 'test' : data?.event === 'recovered' ? 'recovered' : 'opened';
  const id = typeof data?.id === 'string' && /^[a-f0-9-]{36}$/i.test(data.id) ? data.id : 'general';
  event.waitUntil(self.registration.showNotification('Canabicultarr', {
    body: PUSH_MESSAGES[locale][kind], icon: '/icons/app-192.png',
    tag: 'canabicultarr-' + kind + '-' + id, renotify: false,
  }));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const existing = windows.find(client => {
      const url = new URL(client.url);
      return url.origin === self.location.origin && url.pathname === '/';
    });
    if (existing) return existing.focus();
    return self.clients.openWindow('/');
  })());
});

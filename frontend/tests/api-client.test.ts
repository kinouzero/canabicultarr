import { afterEach, test, mock } from 'node:test';
import assert from 'node:assert/strict';
import { apiRequest, apiResponse, ApiError } from '../src/api/auth.ts';

afterEach(() => { mock.restoreAll(); Reflect.deleteProperty(globalThis, 'document'); });

test('JSON responses preserve records, pagination and empty successful responses', async () => {
 const result = { data: [{ id: 'fictional' }], meta: { current_page: 1, last_page: 2 } };
 mock.method(globalThis, 'fetch', async () => Response.json(result));
 assert.deepEqual(await apiRequest('plants'), result.data);
 assert.deepEqual(await apiRequest('plants', undefined, { raw: true }), result);
 mock.restoreAll();
 mock.method(globalThis, 'fetch', async () => new Response(null, { status: 204 }));
 assert.equal(await apiRequest('auth/csrf'), undefined);
});

test('JSON and binary requests expose validation failures and handle non-JSON errors', async () => {
 mock.method(globalThis, 'fetch', async () => Response.json({ error: { code: 'plants.errors.conflict' }, errors: { version: ['plants.validation.version'] } }, { status: 409 }));
 for (const request of [() => apiRequest('plants/fictional'), () => apiResponse('documents/fictional/preview')]) {
  await assert.rejects(request, error => error instanceof ApiError && error.status === 409 && error.code === 'plants.errors.conflict' && error.fields.version?.[0] === 'plants.validation.version');
 }
 mock.restoreAll();
 mock.method(globalThis, 'fetch', async () => new Response('Unavailable', { status: 503 }));
 await assert.rejects(() => apiRequest('plants'), error => error instanceof ApiError && error.status === 503 && error.code === 'auth.errors.requestFailed');
});

test('writes send CSRF credentials and preserve JSON versus multipart bodies', async () => {
 Object.defineProperty(globalThis, 'document', { configurable: true, value: { cookie: 'XSRF-TOKEN=fictional%3Dtoken' } });
 const writes: RequestInit[] = [];
 let initializations = 0;
 mock.method(globalThis, 'fetch', async (input: RequestInfo | URL, options?: RequestInit) => {
  assert.equal(options?.credentials, 'same-origin'); assert.equal(options?.cache, 'no-store');
  if (String(input).endsWith('/auth/csrf')) { initializations++; return new Response(null, { status: 204 }); }
  writes.push(options!); return Response.json({ data: { id: 'fictional' } });
 });
 await apiRequest('plants/fictional', { version: 1 }, { method: 'PATCH' });
 const headers = new Headers(writes[0]!.headers);
 assert.equal(writes[0]!.method, 'PATCH'); assert.equal(writes[0]!.body, '{"version":1}');
 assert.equal(headers.get('X-XSRF-TOKEN'), 'fictional=token'); assert.equal(headers.get('Content-Type'), 'application/json');
 const form = new FormData(); form.append('title', 'Fictional file'); await apiRequest('documents', form);
 assert.equal(writes[1]!.body, form); assert.equal(new Headers(writes[1]!.headers).has('Content-Type'), false);
 assert.equal(initializations, 0);
});

test('failed CSRF initialization prevents the write', async () => {
 Object.defineProperty(globalThis, 'document', { configurable: true, value: { cookie: '' } });
 let requests = 0;
 mock.method(globalThis, 'fetch', async () => { requests++; return new Response(null, { status: 429 }); });
 await assert.rejects(() => apiRequest('plants', {}), error => error instanceof ApiError && error.code === 'auth.errors.rateLimited');
 assert.equal(requests, 1);
});

test('missing or malformed CSRF cookies initialize once and rejected writes are never replayed', async () => {
 for (const cookie of ['', 'XSRF-TOKEN=%invalid']) {
  const document = { cookie }; Object.defineProperty(globalThis, 'document', { configurable: true, value: document });
  const calls: string[] = [];
  const signal = new AbortController().signal;
  mock.method(globalThis, 'fetch', async (input: RequestInfo | URL, options?: RequestInit) => {
   calls.push(String(input)); assert.equal(options?.signal, signal);
   if (String(input).endsWith('/auth/csrf')) { document.cookie = 'XSRF-TOKEN=fresh%3Dtoken'; return new Response(null, { status: 204 }); }
   assert.equal(new Headers(options?.headers).get('X-XSRF-TOKEN'), 'fresh=token');
   return Response.json({ error: { code: 'auth.errors.sessionExpired' } }, { status: 419 });
  });
  await assert.rejects(() => apiRequest('plants/one/actions', {}, { signal }), error => error instanceof ApiError && error.status === 419);
  assert.deepEqual(calls, ['/api/v1/auth/csrf', '/api/v1/plants/one/actions']); mock.restoreAll();
 }
});

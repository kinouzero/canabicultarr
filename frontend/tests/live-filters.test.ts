import assert from 'node:assert/strict';
import { setTimeout as delay } from 'node:timers/promises';
import { test } from 'node:test';
import { effectScope, nextTick, ref } from 'vue';
import { useLiveFilters } from '../src/lib/live-filters.ts';

test('live filters combine typing, apply selected filters immediately and cancel pending duplicates', async () => {
 const scope = effectScope();
 const search = ref(''); const status = ref('active');
 const calls: string[][] = [];
 scope.run(() => useLiveFilters({ search, filters: status, apply: () => calls.push([search.value, status.value]) }));
 try {
  search.value = 'a'; await nextTick();
  search.value = 'ab'; await nextTick();
  assert.deepEqual(calls, []);
  await delay(350);
  assert.deepEqual(calls, [['ab', 'active']]);
  search.value = 'abc'; await nextTick();
  status.value = 'archived'; await nextTick();
  assert.deepEqual(calls, [['ab', 'active'], ['abc', 'archived']]);
  await delay(350);
  assert.equal(calls.length, 2);
 } finally { scope.stop(); }
});

test('explicit submission flushes pending text and leaving the view cancels delayed requests', async () => {
 const scope = effectScope(); const search = ref(''); const calls: string[] = [];
 const apply = scope.run(() => useLiveFilters({ search, apply: () => calls.push(search.value) }))!;
 search.value = 'first'; await nextTick(); apply();
 assert.deepEqual(calls, ['first']);
 search.value = 'second'; await nextTick(); scope.stop();
 await delay(350);
 assert.deepEqual(calls, ['first']);
});


test('restoring route criteria cancels pending typing without a delayed page reset', async () => {
 const scope = effectScope(); const search = ref(''); const status = ref('active');
 let restoring = false; const calls: string[][] = [];
 scope.run(() => useLiveFilters({ search, filters: status, when: () => !restoring, apply: () => calls.push([search.value, status.value]) }));
 try {
  search.value = 'pending'; await nextTick();
  restoring = true; search.value = 'restored'; status.value = 'archived'; await nextTick();
  restoring = false; await delay(350);
  assert.deepEqual(calls, []);
  search.value = 'next'; await nextTick(); await delay(350);
  assert.deepEqual(calls, [['next', 'archived']]);
 } finally { scope.stop(); }
});

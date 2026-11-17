import assert from 'node:assert/strict';
import { test } from 'node:test';
import { recordUrl, type QrRecordType } from '../src/lib/record-link.ts';

test('QR destinations identify every supported record without carrying list state or credentials', () => {
 const targets: Record<QrRecordType, [string, string, string]> = {
  plant: ['/plants', 'plant', 'record-one'], strain: ['/strains', 'strain', 'record-one'],
  site: ['/sites', 'location', 'site:record-one'], room: ['/sites', 'location', 'room:record-one'], zone: ['/sites', 'location', 'zone:record-one'],
  asset: ['/hardware', 'asset', 'record-one'], sensor: ['/measurements', 'sensor', 'record-one'],
  stock: ['/inventory', 'stock', 'record-one'], file: ['/files', 'file', 'record-one'], event: ['/journal', 'event', 'record-one'],
 };
 for (const origin of ['http://localhost:8420', 'http://localhost:18420', 'https://garden.example.test:8443']) {
 for (const [type, [path, key, id]] of Object.entries(targets)) {
  const result = new URL(recordUrl({ type: type as QrRecordType, id: 'record-one', name: '<script>secret</script>' }, `${origin}/plants?q=private&token=secret#secret`));
  assert.equal(result.origin, new URL(origin).origin); assert.equal(result.pathname, path);
  assert.deepEqual([...result.searchParams], [[key, id]]); assert.equal(result.hash, '');
 }
 }
});

test('QR destinations reject unsafe identifiers, schemes, credentials and unknown record kinds', () => {
 const valid = { type: 'plant' as const, id: 'record-one', name: 'Plant' };
 for (const origin of ['javascript:alert(1)', 'file:///tmp/test', 'https://user:password@example.test']) assert.throws(() => recordUrl(valid, origin));
 for (const id of ['', '../private', 'other?token=secret', 'record#fragment', 'a'.repeat(101)]) assert.throws(() => recordUrl({ ...valid, id }, 'https://example.test'));
 assert.throws(() => recordUrl({ ...valid, type: 'unknown' as never }, 'https://example.test'));
});

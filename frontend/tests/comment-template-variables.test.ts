import { test } from 'node:test';
import assert from 'node:assert/strict';
// Instance date settings import the browser appearance preference.
globalThis.window = { matchMedia: () => ({ matches: false }) } as unknown as Window & typeof globalThis;
const { commentVariables, expandCommentTemplate } = await import('../src/lib/commentTemplateVariables.ts');
test('record paths include arrays, falsy values and dates without evaluating code', () => {
 const values = commentVariables({ name: 'Plant A', strain: { name: 'Strain A', thc: '12%' }, day: 0, enabled: false, notes: null, devices: [{ name: 'Pump', id: 'abc' }] }, 'en-US', new Date('2026-11-08T12:00:00Z'));
 const expanded = expandCommentTemplate('{{ plant.name }} / {{ plant.strain.thc }} / {{ plant.devices.0.name }} / {{ plant.day }} / {{ plant.enabled }} / {{ plant.notes }} / {{ date.iso }}', values);
 assert.equal(expanded.text, 'Plant A / 12% / Pump / 0 / false /  / 2026-11-08T12:00:00.000Z'); assert.deepEqual(expanded.missing, []);
 assert.equal(values['plant.devices'], 'Pump');
 assert.deepEqual(expandCommentTemplate('{{ plant.unknown }}', values).missing, ['plant.unknown']);
 assert.equal(expandCommentTemplate('{{ constructor.constructor() }}', values).text, '{{ constructor.constructor() }}');
 assert.deepEqual(expandCommentTemplate('{{ __proto__.x }}', values).missing, []);
 assert.equal(Object.hasOwn(commentVariables(JSON.parse('{"__proto__":{"polluted":true}}'), 'en'), 'plant.__proto__.polluted'), false);
});

test('action drafts and nested historical attachments expose all values and array counts', () => {
 const action = { kind: 'watered', occurredAt: '2026-11-10T14:00:00Z', withProducts: false, harvestWeightGrams: 0, maintenance: { type: 'Custom pruning' }, attachments: [{ title: 'Photo', mediaType: 'image/jpeg' }] };
 const values = commentVariables({ events: [{ comment: 'Previous care', snapshot: { lifecycle_action: { target: 'growth' } }, attachments: [{ originalName: 'care.pdf' }] }], measurements: [{ value: '30', unit: '%' }], devices: [], photos: [] }, 'en', new Date('2026-11-10T15:00:00Z'), action);
 const result = expandCommentTemplate('{{ action.withProducts }} / {{ action.harvestWeightGrams }} / {{ action.maintenance.type }} / {{ action.attachments.0.mediaType }} / {{ plant.events.0.attachments.0.originalName }} / {{ plant.events.0.snapshot.lifecycle_action.target }} / {{ plant.events.length }} / {{ plant.devices.length }}', values);
 assert.equal(result.text, 'false / 0 / Custom pruning / image/jpeg / care.pdf / growth / 1 / 0');
 assert.deepEqual(result.missing, []);
 assert.equal(values['plant.measurements.0.value'], '30');
 assert.deepEqual(expandCommentTemplate('{{ action.secret }}', values).missing, ['action.secret']);
 assert.equal(Object.hasOwn(commentVariables({}, 'en', new Date(), JSON.parse('{"constructor":{"secret":"blocked"}}')), 'action.constructor.secret'), false);
});

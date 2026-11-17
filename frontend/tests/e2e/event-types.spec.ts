import { revealTabbedControl } from './navigation';
import { test, expect } from '../coverage/fixture';
import { insightRoutes } from './insights-fixture';
import { openModule } from './navigation';
import type { EventType } from '../../src/api/event-types';

test('administrators create custom types and the journal records their frozen metadata', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 const types: EventType[] = [];
 const entries: any[] = [];
 const writes: any[] = [];
 const typeId = 'f0000000-0000-4000-8000-000000000001';
 await page.route('**/api/v1/**', route => {
  const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
  const data = (value: unknown, status = 200) => route.fulfill({ status, json: { data: value } });
  const list = (rows: unknown[]) => route.fulfill({ json: { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length, per_page: 20 } } });
  if (path.endsWith('/auth/user')) return data({ id: 'insight-reader', name: 'Journal administrator', email: 'admin@example.test', permissions: ['administration.view', 'users.manage', 'plants.manage', 'sensors.manage'] });
  if (path === '/api/v1/event-types') {
   if (request.method() === 'POST') { const input = request.postDataJSON(); writes.push(input); types.push({ id: typeId, ...input, version: 1 }); return data(types[0], 201); }
   const state = url.searchParams.get('state'); const search = (url.searchParams.get('search') ?? '').toLowerCase();
   const visible = types.filter(type => (!state || state === 'all' || type.is_archived === (state === 'archived')) && type.name.toLowerCase().includes(search));
   return route.fulfill({ json: { data: visible, meta: { current_page: 1, last_page: 1, total: visible.length, per_page: 20 }, canManage: true, icons: ['journal', 'eye', 'pot'], contexts: ['none', 'site', 'room', 'zone', 'asset', 'sensor'] } });
  }
  if (path === `/api/v1/event-types/${typeId}`) { const input = request.postDataJSON(); Object.assign(types[0]!, input, { version: 2 }); return data(types[0]); }
  if (path === '/api/v1/events') {
   if (request.method() === 'POST') {
    const input = request.postDataJSON(); writes.push(input);
    const type = types.find(row => row.id === input.event_type_id)!;
    const record = { ...input, id: 'custom-entry', eventType: { id: type.id, name: type.name, icon: type.icon }, occurredAt: input.occurred_at, author: { id: 'insight-reader', name: 'Journal administrator' }, createdAt: input.occurred_at, updatedAt: input.occurred_at, version: 1, canEdit: true };
    entries.push(record); return data(record, 201);
   }
   return list(entries);
  }
  return route.fallback();
 });
 await page.reload(); await openModule(page, 'catalogs');
 await expect(page.getByRole('tab', { name: 'Types d’entretien', exact: true })).toBeVisible();
 await page.getByRole('tab', { name: 'Types d’événements', exact: true }).click();
 await page.getByRole('button', { name: 'Créer un type d’événement', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Créer un type d’événement', exact: true });
 await (await revealTabbedControl(editor.getByLabel('Nom', { exact: true }))).fill('Pest inspection');
 await (await revealTabbedControl(editor.getByLabel('Icône', { exact: true }).and(editor.locator('select')))).selectOption('eye');
 await editor.getByRole('button', { name: 'Créer', exact: true }).click();
 await expect(editor).toHaveCount(0);
 await expect(page.getByTestId('event-type-settings')).toContainText('Pest inspection');
 await page.setViewportSize({ width: 390, height: 844 });
 const overviewWidth = await page.getByTestId('event-type-settings').evaluate(element => ({ content: element.scrollWidth, available: element.clientWidth }));
 expect(overviewWidth.content).toBeLessThanOrEqual(overviewWidth.available + 1);
 await expect(page.getByRole('tab', { name: 'Types d’entretien', exact: true })).toBeVisible();
 await page.setViewportSize({ width: 1280, height: 720 });
 await openModule(page, 'events');
 await page.getByRole('button', { name: 'Ajouter une entrée', exact: true }).click();
 const entry = page.getByRole('dialog', { name: 'Nouvelle entrée', exact: true });
 await (await revealTabbedControl(entry.locator('#event-category'))).selectOption(typeId);
 await (await revealTabbedControl(entry.locator('#event-title'))).fill('Morning inspection');
 await (await revealTabbedControl(entry.locator('#event-body'))).fill('No pests observed.');
 await entry.getByRole('button', { name: 'Créer', exact: true }).click();
 await expect(entry).toHaveCount(0);
 await expect(page.locator('.events-card')).toContainText('Pest inspection');
 expect(writes[1]).toMatchObject({ category: 'custom', event_type_id: typeId, body: 'No pests observed.' });
 await openModule(page, 'catalogs');
 await page.getByRole('tab', { name: 'Types d’événements', exact: true }).click();
 await page.getByRole('button', { name: 'Modifier le type d’événement', exact: true }).click();
 const edit = page.getByRole('dialog', { name: 'Modifier le type d’événement', exact: true });
 await (await revealTabbedControl(edit.getByLabel('État', { exact: true }).and(edit.locator('select')))).selectOption('true');
 await edit.getByRole('button', { name: 'Enregistrer', exact: true }).click();
 await expect(edit).toHaveCount(0);
 await openModule(page, 'events');
 await expect(page.locator('.events-card')).toContainText('Pest inspection');
 await (await revealTabbedControl(page.locator('#events-category-filter'))).selectOption(typeId);
 expect(entries[0].eventType.name).toBe('Pest inspection');
 expect(errors).toEqual([]);
});

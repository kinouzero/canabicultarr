import { test, expect } from '../coverage/fixture';
import { insightRoutes } from './insights-fixture';
import { openModule } from './navigation';

const pageOf = (data: unknown[], page = 1, total = data.length, perPage = 50) => ({ data, meta: { current_page: page, last_page: Math.max(1, Math.ceil(total / perPage)), total, per_page: perPage } });

test('hardware summaries open all linked plants in bounded pages without opening the hardware', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 const requests: number[] = [];
 const plants = Array.from({ length: 25 }, (_, i) => ({ id: `plant-${i}`, name: `Fictional plant ${i}`, location: null }));
 const device = { id: 'device-one', name: 'Shared hardware', category: 'sensor', status: 'active', locations: [], plants: plants.slice(0, 3), plantsCount: 25, channelsCount: 0, enabledChannelsCount: 0, assetTag: null, manufacturer: null, model: null, serialNumber: null, description: null };
 await page.route('**/api/v1/assets**', route => {
  const url = new URL(route.request().url());
  if (url.pathname.endsWith('/plants')) { const target = Number(url.searchParams.get('page') ?? 1); requests.push(target); return route.fulfill({ json: pageOf(plants.slice((target - 1) * 20, target * 20), target, 25, 20) }); }
  return route.fulfill({ json: pageOf([device]) });
 });
 await openModule(page, 'assets');
 await expect(page.getByText('Fictional plant 2', { exact: false }).first()).toBeVisible();
 await expect(page.getByText('Fictional plant 3', { exact: false })).toHaveCount(0);
 await page.getByRole('button', { name: 'Toutes les plantes liées (25)', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Toutes les plantes liées (25)', exact: true });
 await expect(dialog).toBeVisible(); await expect(dialog.getByText('Fictional plant 19', { exact: true })).toBeVisible();
 await dialog.getByRole('button', { name: 'Page suivante', exact: true }).click();
 await expect(dialog.getByText('Fictional plant 24', { exact: true })).toBeVisible();
 await expect(dialog.getByText('Fictional plant 0', { exact: true })).toHaveCount(0);
 await page.keyboard.press('Escape'); await expect(dialog).toHaveCount(0);
 await expect(page.getByRole('dialog')).toHaveCount(0); expect(requests).toEqual([1, 2]); expect(errors).toEqual([]);
});

test('file browser loads branches and destination choices progressively and preserves deep links', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 await page.route('**/api/v1/auth/user', route => route.fulfill({ json: { data: { id: 'folder-reader', name: 'Fictional user', email: 'folder@example.test', isActive: true, oidcLinked: false, permissions: ['administration.view'], permissionRoles: [] } } }));
 const root = { id: '00000000-0000-4000-8000-000000000001', name: 'Manuals', parentId: null as string | null, version: 1, canEdit: true, hasChildren: true };
 const child = { ...root, id: '00000000-0000-4000-8000-000000000002', name: 'Photos', parentId: root.id, hasChildren: false };
 const extraRoots = Array.from({ length: 50 }, (_, i) => ({ ...root, id: `10000000-0000-4000-8000-${String(i).padStart(12, '0')}`, name: `Other ${i}`, hasChildren: false }));
 const folders = [root, ...extraRoots, child]; const reads: string[] = [];
 await page.route('**/api/v1/document-folders**', route => {
  const url = new URL(route.request().url()); reads.push(url.pathname + url.search);
  if (url.pathname.endsWith('/document-folders')) { const siblings = folders.filter(row => row.parentId === (url.searchParams.get('parent_id') || null)); const target = Number(url.searchParams.get('page') ?? 1); return route.fulfill({ json: pageOf(siblings.slice((target - 1) * 50, target * 50), target, siblings.length) }); }
  if (url.pathname.endsWith('/paths')) return route.fulfill({ json: { data: [root, child] } });
  const selected = folders.find(row => url.pathname.endsWith('/' + row.id))!;
  return route.fulfill({ json: { data: selected, ancestors: selected.id === root.id ? [root] : [root, child] } });
 });
 await page.route('**/api/v1/documents?*', route => route.fulfill({ json: pageOf([{ id: 'file-one', title: 'Nested manual', originalName: 'manual.txt', description: null, format: 'text', mediaType: 'text/plain', sizeBytes: 15, version: 1, isArchived: false, folderId: child.id, subject: null, uploader: { id: 'fictional', name: 'Fictional' }, canEdit: false, createdAt: '2026-11-16T09:00:00Z', updatedAt: '2026-11-16T09:00:00Z' }]) }));
 await page.goto('/files?folder=all');
 await expect(page.getByText('Manuals / Photos', { exact: true })).toBeVisible();
 expect(reads.filter(url => url.includes('/paths?'))).toHaveLength(1);
 await page.locator('.folder-breadcrumb').getByRole('button', { name: 'Bibliothèque', exact: true }).click();
 await expect(page.locator('.folder-grid').getByText('Manuals', { exact: true })).toBeVisible();
 expect(reads.every(url => !url.includes(root.id))).toBe(true);
 const pager = page.locator('.folder-grid + .collection-pagination');
 await expect(page.locator('.folder-grid .folder-tile')).toHaveCount(50);
 await pager.getByRole('button', { name: 'Page suivante', exact: true }).click();
 await expect(page.locator('.folder-grid .folder-tile')).toHaveCount(1);
 await expect(page.locator('.folder-grid')).toContainText('Other 49');
 await pager.getByRole('button', { name: 'Page précédente', exact: true }).click();
 await expect(page.locator('.folder-grid .folder-tile')).toHaveCount(50);
 await page.locator('.folder-grid').getByRole('button', { name: 'Manuals', exact: true }).click();
 await expect(page.locator('.folder-grid').getByText('Photos', { exact: true })).toBeVisible();
 expect(reads.some(url => url.includes('parent_id=' + root.id))).toBe(true);
 await page.locator('.folder-grid').getByRole('button', { name: 'Photos', exact: true }).click();
 await expect.poll(() => new URL(page.url()).searchParams.get('folder')).toBe(child.id);
 await page.reload();
 await expect(page.locator('.folder-breadcrumb')).toContainText('Manuals');
 await expect(page.locator('.folder-breadcrumb')).toContainText('Photos');
 expect(errors).toEqual([]);
});

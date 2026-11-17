import { revealTabbedControl } from './navigation';
import { test, expect, type Page } from '../coverage/fixture';
import { insightRoutes } from './insights-fixture';
import { openModule } from './navigation';

const sortingControl = (page: Page) => page.locator('select').and(page.getByLabel('Trier par', { exact: true }));

test('collection sorts reach list queries and persist separately across navigation, reloads and accounts', async ({ page }) => {
 const { requests, errors, preferences } = await insightRoutes(page);
 let account = 'sorting-account-a'; preferences.account = account;
 await page.route('**/api/v1/auth/user', route => route.fulfill({ json: { data: { id: account, name: 'Fictional sort administrator', email: 'sorting@example.test', permissions: ['administration.view'], isActive: true, oidcLinked: false } } }));
 await page.reload();
 const choices = [
  { module: 'plants', path: '/plants', sort: 'due_asc' },
  { module: 'assets', path: '/assets', sort: 'created_desc' },
  { module: 'inventory', path: '/inventory/items', sort: 'quantity_desc' },
  { module: 'events', path: '/events', sort: 'occurred_asc' },
  { module: 'measurements', path: '/measurements/overview', sort: 'observed_desc' },
  { module: 'users', path: '/users', sort: 'email_desc' },
  { module: 'profiles', path: '/permission-roles', sort: 'users_desc' },
  { module: 'catalog', path: '/strains', sort: 'yield_desc' },
 ];
 for (const choice of choices) {
  await openModule(page, choice.module);
  const select = sortingControl(page);
  await expect(select).toBeVisible();
  await (await revealTabbedControl(select)).selectOption(choice.sort);
  await expect.poll(() => requests.filter(url => url.pathname.endsWith(choice.path)).at(-1)?.searchParams.get('sort')).toBe(choice.sort);
 }
 for (const [name, value] of Object.entries({ plants: 'due_asc', hardware: 'created_desc', inventory: 'quantity_desc', journal: 'occurred_asc', measurements: 'observed_desc', users: 'email_desc', 'access-profiles': 'users_desc', strains: 'yield_desc' })) {
  await expect.poll(() => preferences.values('sorting-account-a')[`sorting:${name}:sort`], { message: `${name} sort must reach account storage` }).toBe(value);
 }
 await page.evaluate(() => localStorage.clear());
 await page.reload();
 for (const choice of choices) {
  await openModule(page, choice.module);
  await expect(sortingControl(page)).toHaveValue(choice.sort);
 }
 account = 'sorting-account-b'; preferences.account = account;
 await page.reload(); await openModule(page, 'plants');
 await expect(sortingControl(page)).toHaveValue('name_asc');
 account = 'sorting-account-a'; preferences.account = account;
 await page.reload(); await openModule(page, 'plants');
 await expect(sortingControl(page)).toHaveValue('due_asc');
 await page.setViewportSize({ width: 390, height: 844 });
 await expect(sortingControl(page)).toHaveAttribute('data-select-mode', 'custom');
 expect(errors).toEqual([]);
});

test('existing dashboard and file sorts survive reload and invalid stored choices use the default', async ({ page }) => {
 const { requests, errors, preferences } = await insightRoutes(page);
 await (await revealTabbedControl(page.locator('#dashboard-sort'))).selectOption('name_desc');
 await page.reload();
 await expect(page.locator('#dashboard-sort')).toHaveValue('name_desc');
 await page.route('**/api/v1/auth/user', route => route.fulfill({ json: { data: { id: 'insight-reader', name: 'Fictional file administrator', email: 'files@example.test', permissions: ['administration.view'], isActive: true, oidcLinked: false } } }));
 await page.reload(); await openModule(page, 'documents');
 await (await revealTabbedControl(page.locator('#documents-sort'))).selectOption('size');
 await expect.poll(() => requests.filter(url => url.pathname.endsWith('/documents')).at(-1)?.searchParams.get('sort')).toBe('size');
 await expect.poll(() => preferences.values()['sorting:files:sort']).toBe('size');
 await page.reload(); await openModule(page, 'documents');
 await expect(page.locator('#documents-sort')).toHaveValue('size');
 preferences.values()['sorting:plants:sort'] = 'unsupported';
 await page.reload(); await openModule(page, 'plants');
 await expect(sortingControl(page)).toHaveValue('name_asc');
 expect(errors).toEqual([]);
});

test('reset links clear filters and remembered sort while returning to the first result page', async ({ page }) => {
 const { requests, errors, preferences } = await insightRoutes(page);
 await openModule(page, 'plants');
 await (await revealTabbedControl(page.locator('.plant-toolbar input'))).fill('Fictional query');
 await (await revealTabbedControl(sortingControl(page))).selectOption('due_desc');
 await expect.poll(() => requests.filter(url => url.pathname.endsWith('/plants')).at(-1)?.searchParams.get('sort')).toBe('due_desc');
 await page.getByRole('button', { name: 'Réinitialiser les filtres', exact: true }).click();
 await expect(page.locator('.plant-toolbar input')).toHaveValue('');
 await expect(sortingControl(page)).toHaveValue('name_asc');
 await expect.poll(() => requests.filter(url => url.pathname.endsWith('/plants')).at(-1)?.searchParams.get('search')).toBe('');
 expect(requests.filter(url => url.pathname.endsWith('/plants')).at(-1)?.searchParams.get('page')).toBe('1');
 await expect.poll(() => preferences.values()['sorting:plants:sort']).toBe('name_asc');
 await page.reload(); await openModule(page, 'plants');
 await expect(sortingControl(page)).toHaveValue('name_asc');
 await openModule(page, 'measurements');
 await (await revealTabbedControl(sortingControl(page))).selectOption('observed_asc');
 await page.getByRole('button', { name: 'Réinitialiser les filtres', exact: true }).click();
 await expect(sortingControl(page)).toHaveValue('name_asc');
 expect(errors).toEqual([]);
});

test('result totals stay in pagination across pages, empty searches and a single page', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 const requests: URL[] = [];
 await page.route('**/api/v1/plants?*', route => {
  const url = new URL(route.request().url());
  requests.push(url);
  const total = url.searchParams.get('search') ? 0 : 45;
  return route.fulfill({ json: { data: [], meta: { current_page: Number(url.searchParams.get('page') || 1), last_page: total ? 3 : 1, total, per_page: 20 } } });
 });
 await openModule(page, 'plants');
 const pagination = page.getByRole('navigation', { name: 'Pages des plantes', exact: true });
 await expect(pagination.getByRole('status')).toHaveText('45 résultats');
 await pagination.getByRole('button', { name: 'Suivant', exact: true }).click();
 await expect(pagination).toContainText('2 / 3');
 await expect(pagination.getByRole('status')).toHaveText('45 résultats');
 await (await revealTabbedControl(page.locator('.plant-toolbar input'))).fill('no match');
 await expect(pagination.getByRole('status')).toHaveText('Aucun résultat');
 await expect(pagination).toContainText('1 / 1');
 await expect(pagination.getByRole('button', { name: 'Suivant', exact: true })).toBeDisabled();
 await page.getByRole('button', { name: 'Réinitialiser les filtres', exact: true }).click();
 await expect(pagination.getByRole('status')).toHaveText('45 résultats');
 await expect(page.locator('#plant-search')).toHaveValue('');
 await expect.poll(() => requests.filter(url => url.pathname.endsWith('/plants')).at(-1)?.searchParams.get('search')).toBe('');
 expect(new URL(page.url()).searchParams.get('q') || '').toBe('');
 expect(errors).toEqual([]);
});

test('page lengths reset pagination and persist per list and account on the server', async ({ page }) => {
 const { errors, preferences } = await insightRoutes(page);
 let latest: URL | undefined;
 await page.route('**/api/v1/plants?*', route => {
  latest = new URL(route.request().url());
  const size = Number(latest.searchParams.get('per_page') || 20);
  return route.fulfill({ json: { data: [], meta: { current_page: Number(latest.searchParams.get('page') || 1), last_page: Math.ceil(45 / size), total: 45, per_page: size } } });
 });
 await openModule(page, 'plants');
 const pagination = page.getByRole('navigation', { name: 'Pages des plantes', exact: true });
 const size = pagination.locator('select').and(pagination.getByLabel('Par page', { exact: true }));
 await expect(size).toHaveValue('20');
 await pagination.getByRole('button', { name: 'Suivant', exact: true }).click();
 await expect(pagination).toContainText('2 / 3');
 await (await revealTabbedControl(size)).selectOption('10');
 await expect(pagination).toContainText('1 / 5');
 expect(latest?.searchParams.get('page')).toBe('1');
 expect(latest?.searchParams.get('per_page')).toBe('10');
 await (await revealTabbedControl(size)).selectOption('100');
 await expect(pagination).toContainText('1 / 1');
 await expect(pagination.getByRole('button', { name: 'Suivant', exact: true })).toBeDisabled();
 await expect.poll(() => preferences.values()['pagination:plants:size']).toBe(100);
 await page.evaluate(() => localStorage.clear());
 await page.reload(); await openModule(page, 'plants');
 await expect(size).toHaveValue('100');
 await openModule(page, 'catalog');
 await expect(page.locator('select').and(page.getByLabel('Par page', { exact: true }))).toHaveValue('20');
 preferences.account = 'another-reader';
 await page.route('**/api/v1/auth/user', route => route.fulfill({ json: { data: { id: 'another-reader', name: 'Another reader', email: 'other@example.test', role: 'member', permissions: [], isActive: true, oidcLinked: false } } }));
 await page.reload(); await openModule(page, 'plants');
 await expect(size).toHaveValue('20');
 preferences.values()['pagination:plants:size'] = 999;
 await page.reload(); await openModule(page, 'plants');
 await expect(size).toHaveValue('20');
 await page.setViewportSize({ width: 390, height: 844 });
 await expect(size).toHaveAttribute('data-select-mode', 'custom');
 await pagination.scrollIntoViewIfNeeded();
 expect(errors).toEqual([]);
});


test('remembered reference filters resolve their labels outside the first catalog page', async ({ page }) => {
 const { errors, preferences } = await insightRoutes(page);
 preferences.values()['measurements:site'] = 'selected-site-outside-page';
 preferences.values()['measurements:type'] = 'selected-type-outside-page';
 const calls: { kind: string; search: string }[] = [];
 for (const kind of ['sites', 'measurement-types']) await page.route(`**/api/v1/${kind}?**`, route => {
  const query = new URL(route.request().url()).searchParams.get('search') ?? ''; calls.push({ kind, search: query });
  const id = kind === 'sites' ? 'selected-site-outside-page' : 'selected-type-outside-page';
  const data = query === id ? [{ id, name: `Selected ${kind}`, unit: '%' }] : Array.from({ length: 20 }, (_, index) => ({ id: `${kind}-${index}`, name: `First page ${index}`, unit: '%' }));
  return route.fulfill({ json: { data, meta: { current_page: 1, last_page: query ? 1 : 100, per_page: 20, total: query ? 1 : 2000 } } });
 });
 await openModule(page, 'measurements');
 const workspace = page.getByTestId('measurement-workspace');
 await expect(workspace.locator('select').and(workspace.getByLabel('Site', { exact: true }))).toHaveValue('selected-site-outside-page');
 await expect(workspace.locator('select').and(workspace.getByLabel('Site', { exact: true })).locator('option:checked')).toHaveText('Selected sites');
 await expect(workspace.locator('select').and(workspace.getByLabel('Type de mesure', { exact: true })).locator('option:checked')).toContainText('Selected measurement-types');
 expect(calls.filter(row => row.kind === 'sites' && row.search === 'selected-site-outside-page')).toHaveLength(1); expect(calls.filter(row => row.kind === 'measurement-types')).toHaveLength(2); expect(errors).toEqual([]);
});

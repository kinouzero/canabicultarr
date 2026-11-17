import { createRouter, createWebHistory } from 'vue-router';
import type { AppPanel } from './navigation';
import { alertTabs, catalogTabs, connectionTabs, hardwareTabs, inventoryTabs, isTab, journalTabs, settingsTabs } from './tab-paths';

// Workspace rendering remains in App; the router owns URLs and browser history.
export const panelPaths: Record<Exclude<AppPanel, 'sensors'>, string> = {
 home: '/', plants: '/plants', catalog: '/strains', locations: '/sites', overview: '/culture',
 assets: '/hardware', measurements: '/measurements', alerts: '/alerts', events: '/journal',
 inventory: '/inventory', documents: '/files', ha: '/integrations/home-assistant', homebridge: '/integrations/homebridge',
 bluetooth: '/integrations/bluetooth', mqtt: '/integrations/mqtt', http: '/integrations/http', sources: '/diagnostics',
 instanceSettings: '/settings', users: '/users', profiles: '/access-profiles', cycleSettings: '/settings/lifecycle', catalogs: '/settings/catalogs',
 notifications: '/settings/notifications', accountNotifications: '/account/notifications', account: '/account', accountPreferences: '/account/preferences', accountAccess: '/account/access', security: '/account/security',
};
const tabRoutes = [
 { panel: 'instanceSettings', paths: [...Object.values(settingsTabs), ...Object.values(connectionTabs)] },
 { panel: 'catalogs', paths: Object.values(catalogTabs) },
 { panel: 'assets', paths: Object.values(hardwareTabs) },
 { panel: 'events', paths: Object.values(journalTabs) },
 { panel: 'alerts', paths: Object.values(alertTabs) },
 { panel: 'inventory', paths: Object.values(inventoryTabs) },
] as const;
const mainPaths = new Set<string>(Object.values(panelPaths));

export const router = createRouter({
 history: createWebHistory(),
 routes: [
  ...Object.entries(panelPaths).map(([panel, path]) => ({ path, name: panel, component: { render: () => null }, meta: { panel } })),
  ...tabRoutes.flatMap(({ panel, paths }) => [...new Set<string>(paths)]
   .filter(path => !mainPaths.has(path))
   .map(path => ({ path, component: { render: () => null }, meta: { panel } }))),
  { path: '/:pathMatch(.*)*', redirect: '/' },
 ],
});

// Normalize previously shared tab links while retaining filters and record references.
router.beforeEach(to => {
 const query = { ...to.query };
 let path = to.path;
 if (path === settingsTabs.general && isTab(settingsTabs, query.tab)) {
  path = settingsTabs[query.tab];
  delete query.tab;
  if (path === settingsTabs.connections && isTab(connectionTabs, query.connection)) {
   path = connectionTabs[query.connection];
   delete query.connection;
  }
 }
 if (path === settingsTabs.catalogs && isTab(catalogTabs, query.catalog)) {
  path = catalogTabs[query.catalog];
  delete query.catalog;
 }
 if (path === hardwareTabs.devices && isTab(hardwareTabs, query.view)) {
  path = hardwareTabs[query.view];
  delete query.view;
 }
 if (path === journalTabs.events && isTab(journalTabs, query.view)) {
  path = journalTabs[query.view];
  delete query.view;
 }
 if (path === alertTabs.episodes && isTab(alertTabs, query.view)) {
  path = alertTabs[query.view];
  delete query.view;
 }
 if (path === inventoryTabs.items && isTab(inventoryTabs, query.tab)) {
  path = inventoryTabs[query.tab];
  delete query.tab;
 }
 if (path !== to.path || Object.keys(query).length !== Object.keys(to.query).length) {
  return { path, query, hash: to.hash, replace: true };
 }
});

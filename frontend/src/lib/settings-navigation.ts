import type { RouteLocationNormalized } from 'vue-router';
import { catalogTabs, connectionTabs, settingsTabs, tabAtPath } from '../tab-paths';

export type SettingsTab = keyof typeof settingsTabs;

export function settingsTab(route: Pick<RouteLocationNormalized, 'path'>): SettingsTab | undefined {
 return tabAtPath(settingsTabs, route.path)
  ?? (tabAtPath(connectionTabs, route.path) ? 'connections' : undefined)
  ?? (tabAtPath(catalogTabs, route.path) ? 'catalogs' : undefined);
}

// These panels stay mounted when moving between settings tabs.
export function isSettingsTabChange(to: RouteLocationNormalized, from: RouteLocationNormalized): boolean {
 const next = settingsTab(to); const previous = settingsTab(from);
 return !!next && !!previous && next !== previous;
}

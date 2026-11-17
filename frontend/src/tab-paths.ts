const connections = '/settings/connections';
const catalogs = '/settings/catalogs';

export const settingsTabs = {
 general: '/settings',
 data: '/settings/data',
 themes: '/settings/themes',
 cycle: '/settings/lifecycle',
 catalogs,
 connections,
 notifications: '/settings/notifications',
 about: '/settings/about',
} as const;

export const connectionTabs = {
 storage: connections,
 authentication: `${connections}/authentication`,
 oidc: `${connections}/oidc`,
 home_assistant: `${connections}/home-assistant`,
 homebridge: `${connections}/homebridge`,
 mqtt: `${connections}/mqtt`,
 mail: `${connections}/email`,
 webhook: `${connections}/webhook`,
 push: `${connections}/push`,
} as const;

export const catalogTabs = {
 templates: catalogs,
 measurements: `${catalogs}/measurement-types`,
 maintenance: `${catalogs}/maintenance-types`,
 eventTypes: `${catalogs}/event-types`,
} as const;

export const hardwareTabs = { devices: '/hardware', channels: '/hardware/channels' } as const;
export const journalTabs = { events: '/journal', notifications: '/journal/notifications' } as const;
export const alertTabs = { episodes: '/alerts', rules: '/alerts/rules' } as const;
export const inventoryTabs = { items: '/inventory', storage: '/inventory/storage-locations' } as const;

export function tabAtPath<T extends string>(tabs: Record<T, string>, path: string): T | undefined {
 return (Object.keys(tabs) as T[]).find(tab => tabs[tab] === path);
}

export function isTab<T extends string>(tabs: Record<T, string>, value: unknown): value is T {
 return typeof value === 'string' && Object.hasOwn(tabs, value);
}

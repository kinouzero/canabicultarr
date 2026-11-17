export const delegatedPermissions = ['administration.view', 'plants.manage', 'strains.manage', 'locations.manage', 'assets.manage', 'sensors.manage', 'inventory.manage', 'alerts.manage', 'integrations.manage'] as const;
export type DelegatedPermission = typeof delegatedPermissions[number];
export function permissionLabel(permission: string): string { return `users.permissionLabels.${permission.split('.')[0]}`; }

export function permissionIcon(permission: string): string {
 return ({ administration: 'shield', plants: 'leaf', strains: 'strain', locations: 'location', assets: 'device', sensors: 'sensor', inventory: 'inventory', alerts: 'bell', integrations: 'plug' } as Record<string, string>)[permission.split('.')[0]!] ?? 'shield';
}

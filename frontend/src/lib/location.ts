import type { LocationContext } from '../api/types';

export const locationIcons = { site: 'building', room: 'home', zone: 'zone' } as const;

export function locationLabel(location: LocationContext | null | undefined, includeCodes = false): string {
 return location ? [location.site, location.room, location.zone].filter((node): node is NonNullable<typeof node> => !!node).map(node => node.name + (includeCodes && node.code ? ` [${node.code}]` : '')).join(' / ') : '';
}

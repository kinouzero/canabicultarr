export type QrRecordType = 'plant' | 'strain' | 'site' | 'room' | 'zone' | 'asset' | 'sensor' | 'stock' | 'file' | 'event';
export interface QrRecord { type: QrRecordType; id: string; name: string }

const destinations: Record<QrRecordType, readonly [string, string]> = {
 plant: ['/plants', 'plant'], strain: ['/strains', 'strain'],
 site: ['/sites', 'location'], room: ['/sites', 'location'], zone: ['/sites', 'location'],
 asset: ['/hardware', 'asset'], sensor: ['/measurements', 'sensor'],
 stock: ['/inventory', 'stock'], file: ['/files', 'file'], event: ['/journal', 'event'],
};

/** Labels carry only a stable record target; never copy current filters or authentication state. */
export function recordUrl(record: QrRecord, origin: string): string {
 const base = new URL(origin);
 const destination = destinations[record.type];
 if (!['http:', 'https:'].includes(base.protocol) || base.username || base.password || !destination || !/^[A-Za-z0-9_-]{1,100}$/.test(record.id)) throw new Error('Invalid record link');
 const [path, key] = destination;
 const url = new URL(path, base.origin);
 url.searchParams.set(key, ['site', 'room', 'zone'].includes(record.type) ? `${record.type}:${record.id}` : record.id);
 return url.href;
}

import type { StockLocationSnapshot } from '../api/inventory';

export function inventoryLocationLabel(location: StockLocationSnapshot | null | undefined): string {
 return location?.name ?? [location?.site?.name, location?.room?.name, location?.zone?.name].filter(Boolean).join(' / ');
}

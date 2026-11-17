import type { AssetCategory } from '../api/assets';

export const categoryIcons: Record<AssetCategory, string> = {
 sensor: 'sensor', socket: 'socket', humidifier: 'humidifier', dehumidifier: 'dehumidifier',
 light: 'light', fan: 'fan', pump: 'pump', other: 'equipment',
};
export function assetCategoryIcon(category?: string) {
 return categoryIcons[category as AssetCategory] ?? categoryIcons.other;
}

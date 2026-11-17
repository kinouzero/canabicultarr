import { computed } from 'vue';
import { useInsightPreferences } from './insight-preferences';
export const pageSizes = [10, 20, 50, 100] as const;
export function useCollectionPageSize(name: string, defaultSize = 20) {
 const preferences = useInsightPreferences(`pagination:${name}`, { size: defaultSize });
 return computed({
  get: () => pageSizes.some(size => size === preferences.size) ? preferences.size : defaultSize,
  set: (size: number) => { preferences.size = size; },
 });
}

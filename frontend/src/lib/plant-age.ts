import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Plant } from '../api/plants';

type PlantAge = Pick<Plant, 'phase' | 'day' | 'week'>;

export function usePlantAge(plant: () => PlantAge | null) {
 const { t } = useI18n({ useScope: 'global' });
 const label = (compact: boolean) => {
  const value = plant();
  if (!value) return '';
  if (value.day === null) return value.phase === 'planned' ? t('plants.phases.planned') : '';
  if (value.week === null) return t('plants.ageDay', { day: value.day });
  return t(compact ? 'plants.ageCompact' : 'plants.ageBadge', { week: value.week, day: value.day });
 };
 return { label: computed(() => label(false)), compact: computed(() => label(true)) };
}

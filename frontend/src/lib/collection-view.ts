import { computed, inject, toValue, type MaybeRefOrGetter } from 'vue';
import { insightPreferenceScope } from './insight-preferences';
import { preferenceStore, setUiPreference } from './ui-preferences';
export function useCollectionView(key: MaybeRefOrGetter<string>) {
 const scope = inject(insightPreferenceScope, undefined);
 const preferenceKey = () => `layout:${toValue(key)}:mode`;
 return computed({
  get: () => preferenceStore(scope?.value ?? '').values[preferenceKey()] === 'rows' ? 'rows' : 'cards',
  set: (value: 'cards' | 'rows') => setUiPreference(scope?.value ?? '', preferenceKey(), value),
 });
}

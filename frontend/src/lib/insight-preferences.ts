import { computed, inject, reactive, type ComputedRef, type InjectionKey } from 'vue';
import { preferenceStore, setUiPreference, type PreferenceValue } from './ui-preferences';
export const insightPreferenceScope: InjectionKey<ComputedRef<string>> = Symbol('insight-preference-scope');
export function useInsightPreferences<T extends Record<string, PreferenceValue>>(name: string, defaults: T): T {
 const scope = inject(insightPreferenceScope, undefined);
 const account = () => scope?.value ?? '';
 return reactive(Object.fromEntries(Object.entries(defaults).map(([field, original]) => [field, computed({
  get: () => {
   const stored = preferenceStore(account()).values[`${name}:${field}`];
   const valid = Array.isArray(original)
    ? Array.isArray(stored) && stored.length <= 100 && stored.every(item => typeof item === 'string')
    : typeof stored === typeof original && (typeof stored !== 'number' || Number.isFinite(stored));
   return valid ? stored : original;
  },
  set: (value: PreferenceValue) => setUiPreference(account(), `${name}:${field}`, value),
 })]))) as unknown as T;
}

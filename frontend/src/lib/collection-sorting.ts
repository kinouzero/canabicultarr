import { computed } from 'vue';
import { useInsightPreferences } from './insight-preferences';

export const sortOptions = {
 common: ['name_asc', 'name_desc', 'created_desc', 'created_asc'],
 plants: ['name_asc', 'name_desc', 'created_desc', 'created_asc', 'started_asc', 'started_desc', 'due_asc', 'due_desc'],
 channels: ['name_asc', 'name_desc', 'interval_asc', 'interval_desc', 'created_desc', 'created_asc'],
 measurementTypes: ['name_asc', 'name_desc', 'channels_desc', 'channels_asc', 'created_desc', 'created_asc'],
 inventory: ['name_asc', 'name_desc', 'quantity_desc', 'quantity_asc', 'reference_asc', 'reference_desc', 'created_desc', 'created_asc'],
 users: ['name_asc', 'name_desc', 'email_asc', 'email_desc', 'created_desc', 'created_asc'],
 profiles: ['name_asc', 'name_desc', 'users_desc', 'users_asc', 'created_desc', 'created_asc'],
 templates: ['name_asc', 'name_desc', 'updated_desc', 'updated_asc', 'created_desc', 'created_asc'],
 events: ['occurred_desc', 'occurred_asc', 'created_desc', 'created_asc', 'name_asc', 'name_desc'],
 measurements: ['name_asc', 'name_desc', 'observed_desc', 'observed_asc', 'created_desc', 'created_asc'],
 sources: ['name_asc', 'name_desc', 'observed_desc', 'observed_asc', 'accepted_desc', 'accepted_asc'],
 alerts: ['occurred_desc', 'occurred_asc', 'severity_desc', 'severity_asc'],
 maintenance: ['name_asc', 'name_desc'],
} as const;

// Sort choices use the shared account preferences stored in Redis.
export function useCollectionSort<const T extends readonly string[]>(name: string, options: T, defaultValue: T[number] = options[0]!) {
 const state = useInsightPreferences(`sorting:${name}`, { sort: defaultValue });
 return computed<T[number]>({
  get: () => options.includes(state.sort) ? state.sort : defaultValue,
  set: value => { state.sort = value; },
 });
}

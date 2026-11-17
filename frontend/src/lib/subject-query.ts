import { onScopeDispose, ref, watch, type Ref } from 'vue';
import { queryField } from './collection-query';
import { ApiError } from '../api/auth';
import { assetsApi } from '../api/assets';
import { sensorsApi } from '../api/sensors';
import { locationsApi } from '../api/locations';
import { eventsApi, type EventSubject } from '../api/events';
import type { DocumentSubject } from '../api/documents';

export async function resolveEventSubject(type: string, id: string): Promise<EventSubject> {
 if (['site', 'room', 'zone'].includes(type)) {
  const { context } = await locationsApi.context(type, id);
  return { type: context.type, id: context.id, name: context.zone?.name ?? context.room?.name ?? context.site.name, location: context, deviceName: null };
 }
 if (type === 'asset') {
  const row = await assetsApi.show(id);
  return { type, id: row.id, name: row.name, location: row.locations.length === 1 ? row.locations[0]! : null, deviceName: null };
 }
 if (type === 'sensor') {
  const row = await sensorsApi.get(id);
  return { type, id: row.id, name: row.name, location: row.device.locations.length === 1 ? row.device.locations[0]! : null, deviceName: row.device.name };
 }
 throw new ApiError(422, 'auth.errors.validationFailed');
}
export async function resolveDocumentSubject(type: string, id: string): Promise<DocumentSubject> {
 if (type === 'event') {
  const row = await eventsApi.show(id);
  return { type, id: row.id, name: row.title, location: row.subject?.location ?? null, deviceName: row.subject?.deviceName ?? null };
 }
 return resolveEventSubject(type, id);
}

/** Keep identifiers in URLs, then resolve their authorized display context before listing. */
export function useSubjectQuery<T extends { type: string; id: string }>(model: Ref<T | null>, resolve: (type: string, id: string) => Promise<T>) {
 const kind = ref(model.value?.type ?? ''); const id = ref(model.value?.id ?? '');
 let alive = true;
 onScopeDispose(() => { alive = false; });
 watch(model, value => { kind.value = value?.type ?? ''; id.value = value?.id ?? ''; }, { flush: 'sync' });
 return {
  fields: { subjectType: queryField.identifier(kind), subject: queryField.identifier(id) },
  clear: () => { model.value = null; kind.value = ''; id.value = ''; },
  resolve: async () => {
   if (!alive) return false;
   const target = `${kind.value}:${id.value}`;
   if (!kind.value && !id.value) { model.value = null; return true; }
   if (!kind.value || !id.value) throw new ApiError(422, 'auth.errors.validationFailed');
   if (model.value?.type === kind.value && model.value.id === id.value) return true;
   const result = await resolve(kind.value, id.value);
   if (!alive || target !== `${kind.value}:${id.value}`) return false;
   model.value = result;
   return true;
  },
 };
}

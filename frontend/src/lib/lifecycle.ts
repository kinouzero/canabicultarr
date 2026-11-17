import type { LocationContext } from '../api/types';
import { computed, shallowRef } from 'vue';
export interface LifecycleAction { id: string; name: string | null; icon: string; target: string; requires_harvest_weight: boolean; container_inventory_item_id?: string | null; substrates?: { inventory_item_id: string; quantity: string }[] }
export interface FertilizerPeriod { id: string; name: string | null; unit: 'days' | 'weeks' | 'percent'; start: number; end: number | null; products: { inventory_item_id: string; dose_per_liter: string }[] }
export interface LifecycleStage { target_location?: Pick<LocationContext, 'type' | 'id'> | null; id: string; name: string | null; icon: string; watering: boolean; maintenance: boolean; flush: boolean; actions: LifecycleAction[]; water_liters?: string | null; fertilizer_periods?: FertilizerPeriod[] }
export const lifecycleStages = shallowRef<LifecycleStage[]>([]);
export const phaseKeys = computed(() => lifecycleStages.value.map(stage => stage.id));
export function phaseDefinition(id: string) { return lifecycleStages.value.find(stage => stage.id === id); }
export function actionDefinition(id: string | null | undefined) { return lifecycleStages.value.flatMap(stage => stage.actions).find(action => action.id === id); }
export function phaseLabel(id: string, t: (key: string) => string, snapshot?: { name: string | null } | null) {
 const name = snapshot ? snapshot.name : phaseDefinition(id)?.name;
 if (name) return name;
 const key = id === 'flush' ? 'catalog.stages.flush' : `plants.phases.${id}`;
 const label = t(key); return label === key ? id : label;
}
export function actionLabel(id: string, t: (key: string) => string, snapshot?: { name: string | null } | null) {
 const name = snapshot ? snapshot.name : actionDefinition(id)?.name;
 if (name) return name;
 const key = `plants.actions.${id}`; const label = t(key); return label === key ? id : label;
}
export function applyLifecycle(stages: LifecycleStage[], icons: string[]) { if (!Array.isArray(stages) || !Array.isArray(icons)) throw new Error('Invalid lifecycle definition'); lifecycleStages.value = stages; }
export function resetLifecycle() { lifecycleStages.value = []; }

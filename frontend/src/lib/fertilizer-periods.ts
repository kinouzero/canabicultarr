import type { FertilizerPeriod } from './lifecycle';
import { validDose } from './plant-inventory';
export type FertilizerPeriodStatus = 'active' | 'upcoming' | 'completed' | 'outside' | 'unknown';
export interface FertilizerPeriodView extends Omit<FertilizerPeriod, 'products'> {
 products: (FertilizerPeriod['products'][number] & { name?: string | null; unit?: string | null })[];
 startDays: number | null; endDays: number | null;
 active?: boolean; status?: FertilizerPeriodStatus; startsAt?: string | null; endsAt?: string | null;
}
export interface FertilizerPlan { at: string; stageStartedAt: string | null; durationDays: number | null; flushStartsAt: string | null; periods: FertilizerPeriodView[] }
export function validFertilizerPeriods(periods: FertilizerPeriod[], duration: number | null): boolean {
 if (periods.length > 20 || new Set(periods.flatMap(period => period.products.map(product => product.inventory_item_id))).size > 20) return false;
 return periods.every(period => {
  const max = period.unit === 'percent' ? 100 : period.unit === 'weeks' ? 521 : 3650;
  return Number.isInteger(period.start) && period.start >= 0 && period.start < max
   && (period.end === null || Number.isInteger(period.end) && period.end > period.start && period.end <= max)
   && (period.unit !== 'percent' || duration !== null && duration > 0)
   && period.products.length > 0 && period.products.length <= 20 && period.products.every(product => validDose(product.dose_per_liter));
 });
}
/** Editor preview only; the server determines actual watering membership. */
export function previewFertilizerPeriods(periods: FertilizerPeriod[], duration: number | null, names: Map<string, string>, limit = duration): FertilizerPeriodView[] {
 return periods.map(period => {
  const scale = period.unit === 'percent' ? (duration ?? 0) / 100 : period.unit === 'weeks' ? 7 : 1;
  const startDays = period.start * scale;
  const endDays = period.end === null ? duration : period.end * scale;
  return { ...period, products: period.products.map(product => ({ ...product, name: names.get(product.inventory_item_id) })), startDays, endDays: limit === null ? endDays : Math.min(endDays ?? limit, limit) };
 });
}

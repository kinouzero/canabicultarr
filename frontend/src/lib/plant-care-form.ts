import { nextTick, onUnmounted, ref, watch } from 'vue';
import { ApiError } from '../api/auth';
import { validHarvestWeight } from './harvest-weight';

export function hasCareDraft(values: Record<string, string | number>, attachments: { id: string }[]) {
 return attachments.length > 0 || Object.values(values).some(value => value !== '');
}
interface CareValues {
 comment: string; occurredAt: string; choice?: string; type?: string;
 harvestWeight?: string | number; documentIds: string[];
}
const messages: Record<string, string> = {
 occurred_at: 'plants.errors.timeInvalid', comment: 'plants.validation.comment',
 with_products: 'plants.validation.wateringChoice', type: 'plants.validation.maintenanceType',
 harvest_weight_grams: 'plants.validation.harvestWeight', document_ids: 'plants.errors.attachmentUnavailable',
};
export function plantActionTab(field: string) {
 if (field === 'comment') return 'comment';
 if (field === 'document_ids') return 'attachments';
 return 'action';
}
// Map known API fields to local advice; never display raw server values.
export function usePlantCareValidation(values: () => CareValues, controls: () => Record<string, string>, weightRequired: () => boolean = () => false, revealField?: (field: string) => void) {
 const fields = ref<Record<string, string>>({});
 let active = true; onUnmounted(() => { active = false; });
 const sources = { occurred_at: () => values().occurredAt, comment: () => values().comment, with_products: () => values().choice, type: () => values().type, harvest_weight_grams: () => values().harvestWeight, document_ids: () => JSON.stringify(values().documentIds) };
 for (const [field, source] of Object.entries(sources)) watch(source, () => { delete fields.value[field]; });
 function clear() { fields.value = {}; }
 function attrs(field: string) { return { 'aria-invalid': !!fields.value[field], 'aria-describedby': fields.value[field] ? `${controls()[field]}-error` : undefined }; }
 async function focus() {
  const field = Object.keys(controls()).find(key => fields.value[key]);
  if (!field) return;
  revealField?.(field);
  await nextTick();
  if (active) document.getElementById(controls()[field])?.focus();
 }
 function validate() {
  clear(); const value = values();
  if (value.comment.length > 2000) fields.value.comment = messages.comment;
  if (value.occurredAt) { const date = new Date(value.occurredAt); if (!Number.isFinite(date.getTime()) || date.getUTCFullYear() < 2000 || date.getTime() > Date.now()) fields.value.occurred_at = messages.occurred_at; }
  if (value.choice !== undefined && !['with', 'without'].includes(value.choice)) fields.value.with_products = messages.with_products;
  if (value.type !== undefined && (!value.type.trim() || value.type.trim().length > 80)) fields.value.type = messages.type;
  if (weightRequired() && !validHarvestWeight(value.harvestWeight ?? '')) fields.value.harvest_weight_grams = messages.harvest_weight_grams;
  if (Object.keys(fields.value).length) { void focus(); return false; }
  return true;
 }
 function capture(cause: unknown) {
  if (!(cause instanceof ApiError) || cause.status !== 422) return;
  for (const key of Object.keys(cause.fields)) {
   const field = key.startsWith('document_ids.') ? 'document_ids' : key;
   if (Object.hasOwn(messages, field) && controls()[field]) fields.value[field] = messages[field];
  }
  const field = cause.code === 'plants.errors.timeInvalid' ? 'occurred_at' : cause.code === 'plants.validation.harvestWeight' ? 'harvest_weight_grams' : cause.code === 'plants.errors.attachmentUnavailable' ? 'document_ids' : '';
  if (field && controls()[field]) fields.value[field] = messages[field];
  void focus();
 }
 return { fields, attrs, clear, validate, capture };
}

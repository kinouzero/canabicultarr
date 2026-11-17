import { nextTick, onUnmounted, ref, watch } from 'vue';
import { ApiError } from '../api/auth';
interface FieldControl { id: string; message: string }
// Domain forms supply known fields and local advice; API messages are never rendered directly.
export function useFieldErrors(controls: () => Record<string, FieldControl>, values: () => Record<string, unknown>, revealField?: (field: string) => void) {
 const fields = ref<Record<string, string>>({}); let active = true;
 onUnmounted(() => { active = false; });
 watch(values, (current, previous) => {
  for (const field of Object.keys(fields.value)) if (JSON.stringify(current[field]) !== JSON.stringify(previous?.[field])) delete fields.value[field];
 }, { deep: true, flush: 'sync' });
 function clear() { fields.value = {}; }
 function set(field: string, message?: string) { if (Object.hasOwn(controls(), field)) fields.value[field] = message ?? controls()[field].message; }
 function attrs(field: string) { return { 'aria-invalid': !!fields.value[field], 'aria-describedby': fields.value[field] ? `${controls()[field]?.id}-error` : undefined }; }
 async function focus() {
  const first = Object.keys(controls()).find(field => fields.value[field]);
  if (first) revealField?.(first);
  await nextTick(); if (!active) return;
  for (const field of Object.keys(controls())) {
   if (!fields.value[field]) continue;
   const root = document.getElementById(controls()[field].id);
   if (!root || !root.getClientRects().length || root.matches(':disabled, [aria-disabled="true"]')) continue;
   if (root.matches('input,select,textarea,[contenteditable="true"],[tabindex]')) root.focus();
   else root.querySelector<HTMLElement>('input,select,textarea,[contenteditable="true"],[tabindex]')?.focus();
   break;
  }
 }
 function capture(cause: unknown, alias: (field: string) => string = value => value) {
  if (!(cause instanceof ApiError) || cause.status !== 422) return;
  const known = controls();
  for (const key of Object.keys(cause.fields)) {
   const path = alias(key);
   const field = Object.keys(known).sort((a, b) => b.length - a.length).find(value => path === value || path.startsWith(`${value}.`));
   if (field) set(field);
  }
  void focus();
 }
 return { fields, set, clear, attrs, focus, capture };
}

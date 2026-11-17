import { onMounted, onUnmounted, useId } from 'vue';

// Only the frontmost dialog handles Escape, even if focus was lost after a save.
export function useDialogEscape(visible: () => boolean, close: () => void) {
 const id = useId();
 function onKeydown(event: KeyboardEvent) {
  if (event.target instanceof HTMLElement && event.target.closest('[data-select-popup], select[aria-expanded="true"], [role="combobox"][aria-expanded="true"], .n-base-select-menu, .n-tree-select-menu')) return;
  if (!visible() || event.key !== 'Escape' || event.isComposing || event.defaultPrevented) return;
  const dialogs = [...document.querySelectorAll<HTMLElement>('[role="dialog"][aria-modal="true"]')]
   .filter(dialog => dialog.getClientRects().length)
   .sort((a, b) => (Number(getComputedStyle(a.closest('.n-modal-container') ?? a).zIndex) || 0) - (Number(getComputedStyle(b.closest('.n-modal-container') ?? b).zIndex) || 0));
  if (dialogs.at(-1)?.dataset.appDialogId !== id) return;
  event.preventDefault(); event.stopImmediatePropagation(); close();
 }
 onMounted(() => document.addEventListener('keydown', onKeydown, true));
 onUnmounted(() => document.removeEventListener('keydown', onKeydown, true));
 return { 'data-app-dialog-id': id };
}

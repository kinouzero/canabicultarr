import { computed, inject, ref, watchEffect, type Directive } from 'vue';
import { insightPreferenceScope } from './insight-preferences';
import { preferenceStore, setUiPreference } from './ui-preferences';

export function useDisclosureState(namespace: string) {
 const scope = inject(insightPreferenceScope, undefined);
 const prefix = `disclosures:${namespace}:`;
 const account = () => scope?.value ?? '';
 const states = computed<Record<string, boolean>>(() => Object.fromEntries(Object.entries(preferenceStore(account()).values).filter(([key, value]) => key.startsWith(prefix) && typeof value === 'boolean').map(([key, value]) => [key.slice(prefix.length), value as boolean])));
 function isOpen(key: string, fallback = false) { return Object.hasOwn(states.value, key) ? states.value[key]! : fallback; }
 function set(key: string, open: boolean) { setUiPreference(account(), prefix + key, open); }
 function replace(value: Record<string, boolean>) {
  for (const key of new Set([...Object.keys(states.value), ...Object.keys(value)])) set(key, value[key] ?? false);
 }
 return { states, isOpen, set, replace };
}

/** Keep native details/summary keyboard behavior and existing initial defaults. */
export function useRememberDisclosure(namespace: string): Directive<HTMLDetailsElement, string> {
 const preferences = useDisclosureState(namespace);
 const controls = new WeakMap<HTMLDetailsElement, { key: { value: string }; stop: () => void; toggle: (event: Event) => void; click: (event: Event) => void }>();
 return {
  mounted(element, binding) {
   if (!(element instanceof HTMLDetailsElement)) return;
   const key = ref(binding.value);
   const fallback = element.open;
   let observed = element.open;
   const stop = watchEffect(() => {
    observed = preferences.isOpen(key.value, fallback);
    if (element.open !== observed) element.open = observed;
   });
   const toggle = (event: Event) => {
    if (event.target !== element || !element.isConnected || element.open === observed) return;
    observed = element.open;
    preferences.set(key.value, observed);
   };
   const click = (event: Event) => {
    if ((event.target as Element).closest('summary')?.parentElement === element) queueMicrotask(() => {
     if (element.isConnected && element.open !== observed) { observed = element.open; preferences.set(key.value, observed); }
    });
   };
   element.addEventListener('toggle', toggle); element.addEventListener('click', click);
   controls.set(element, { key, stop, toggle, click });
  },
  updated(element, binding) { const control = controls.get(element); if (control) control.key.value = binding.value; },
  beforeUnmount(element) {
   const control = controls.get(element);
   if (control) {
    // A dialog can close before the browser dispatches its queued toggle event.
    preferences.set(control.key.value, element.open);
    control.stop(); element.removeEventListener('toggle', control.toggle); element.removeEventListener('click', control.click); controls.delete(element); }
  },
 };
}

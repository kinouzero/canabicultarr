import { ref } from 'vue';
export const appearanceChoices = ['system', 'light', 'dark'] as const;
export type AppearancePreference = typeof appearanceChoices[number];
function preference(value: string | null): AppearancePreference { return appearanceChoices.includes(value as AppearancePreference) ? value as AppearancePreference : 'system'; }
function storedPreference() { try { return preference(localStorage.getItem('appearance')); } catch { return 'system' as const; } }
export const appearancePreference = ref<AppearancePreference>(storedPreference());
export const appearanceIsDark = ref(false);
const system = window.matchMedia('(prefers-color-scheme: dark)');
let initialized = false;
function apply() {
 const dark = appearancePreference.value === 'dark' || appearancePreference.value === 'system' && system.matches;
 appearanceIsDark.value = dark;
 document.documentElement.classList.toggle('app-dark', dark);
 document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
}
export function setAppearance(value: AppearancePreference) {
 appearancePreference.value = preference(value);
 try { localStorage.setItem('appearance', appearancePreference.value); } catch { /* Keep the in-memory preference when browser storage is unavailable. */ }
 apply();
}
export function initializeAppearance() {
 if (initialized) return; initialized = true; apply();
 system.addEventListener('change', () => { if (appearancePreference.value === 'system') apply(); });
 window.addEventListener('storage', event => { if (event.key === 'appearance' || event.key === null) { appearancePreference.value = storedPreference(); apply(); } });
}

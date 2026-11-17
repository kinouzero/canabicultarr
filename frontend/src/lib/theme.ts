import { shallowRef, watch } from 'vue';
import { appearanceIsDark } from './appearance.ts';
export const paletteKeys = ["canvas", "surface", "subtle", "border", "text", "muted", "accent", "accentText", "focus", "success", "warning", "danger", "info", "overlay", "actionWater", "actionPlant", "actionHarvest", "actionMaintenance", "actionEdit", "actionMedia", "actionLink", "shadow", "photoOverlay", "photoText"] as const;
export const paletteGroups = {
 surfaces: ['canvas', 'surface', 'subtle', 'border'],
 typography: ['text', 'muted', 'accent', 'accentText', 'focus'],
 statuses: ['success', 'warning', 'danger', 'info'],
 actions: ['actionWater', 'actionPlant', 'actionHarvest', 'actionMaintenance', 'actionEdit', 'actionMedia', 'actionLink'],
 effects: ['overlay', 'shadow', 'photoOverlay', 'photoText'],
} as const satisfies Record<string, readonly PaletteKey[]>;
export type PaletteKey = typeof paletteKeys[number];
export type Palette = Record<PaletteKey, string>;
export const themePresets = {
 "botanical": {
  "light": {
   "canvas": "#f6f8f3",
   "surface": "#ffffff",
   "subtle": "#f0f4e9",
   "border": "#dce5d5",
   "text": "#2a4030",
   "muted": "#617154",
   "accent": "#607b45",
   "accentText": "#ffffff",
   "focus": "#648752",
   "success": "#24734b",
   "warning": "#956c16",
   "danger": "#b34545",
   "info": "#356d9d",
   "overlay": "#12241b",
   "actionWater": "#1767a6",
   "actionPlant": "#38723f",
   "actionHarvest": "#985020",
   "actionMaintenance": "#237463",
   "actionEdit": "#86570c",
   "actionMedia": "#7351a2",
   "actionLink": "#1767a6",
   "shadow": "#12241b",
   "photoOverlay": "#142d21",
   "photoText": "#ffffff"
  },
  "dark": {
   "canvas": "#111812",
   "surface": "#1b241d",
   "subtle": "#222e24",
   "border": "#364438",
   "text": "#e2eade",
   "muted": "#b1bfaa",
   "accent": "#577740",
   "accentText": "#ffffff",
   "focus": "#9bbf7f",
   "success": "#93d5ae",
   "warning": "#e7c87b",
   "danger": "#f3a5a0",
   "info": "#a1c8ef",
   "overlay": "#000000",
   "actionWater": "#79baff",
   "actionPlant": "#9ad28e",
   "actionHarvest": "#f1ac72",
   "actionMaintenance": "#77cdbb",
   "actionEdit": "#edc164",
   "actionMedia": "#c6a3ee",
   "actionLink": "#79baff",
   "shadow": "#000000",
   "photoOverlay": "#08110c",
   "photoText": "#ffffff"
  }
 },
 "ocean": {
  "light": {
   "canvas": "#f2f7fb",
   "surface": "#ffffff",
   "subtle": "#eaf2f9",
   "border": "#d0e0ed",
   "text": "#203b51",
   "muted": "#526f85",
   "accent": "#256c94",
   "accentText": "#ffffff",
   "focus": "#3684b1",
   "success": "#24734b",
   "warning": "#956c16",
   "danger": "#b34545",
   "info": "#356d9d",
   "overlay": "#12241b",
   "actionWater": "#1767a6",
   "actionPlant": "#38723f",
   "actionHarvest": "#985020",
   "actionMaintenance": "#237463",
   "actionEdit": "#86570c",
   "actionMedia": "#7351a2",
   "actionLink": "#1767a6",
   "shadow": "#12241b",
   "photoOverlay": "#142d21",
   "photoText": "#ffffff"
  },
  "dark": {
   "canvas": "#0e1922",
   "surface": "#172733",
   "subtle": "#203442",
   "border": "#365165",
   "text": "#e0edf5",
   "muted": "#a8c3d4",
   "accent": "#28779e",
   "accentText": "#ffffff",
   "focus": "#7fbfe2",
   "success": "#93d5ae",
   "warning": "#e7c87b",
   "danger": "#f3a5a0",
   "info": "#a1c8ef",
   "overlay": "#000000",
   "actionWater": "#79baff",
   "actionPlant": "#9ad28e",
   "actionHarvest": "#f1ac72",
   "actionMaintenance": "#77cdbb",
   "actionEdit": "#edc164",
   "actionMedia": "#c6a3ee",
   "actionLink": "#79baff",
   "shadow": "#000000",
   "photoOverlay": "#08110c",
   "photoText": "#ffffff"
  }
 },
 "terracotta": {
  "light": {
   "canvas": "#faf5f1",
   "surface": "#fffdfb",
   "subtle": "#f5ebe3",
   "border": "#e7d5c9",
   "text": "#51392f",
   "muted": "#806457",
   "accent": "#a65539",
   "accentText": "#ffffff",
   "focus": "#b86b4d",
   "success": "#24734b",
   "warning": "#956c16",
   "danger": "#b34545",
   "info": "#356d9d",
   "overlay": "#12241b",
   "actionWater": "#1767a6",
   "actionPlant": "#38723f",
   "actionHarvest": "#985020",
   "actionMaintenance": "#237463",
   "actionEdit": "#86570c",
   "actionMedia": "#7351a2",
   "actionLink": "#1767a6",
   "shadow": "#12241b",
   "photoOverlay": "#142d21",
   "photoText": "#ffffff"
  },
  "dark": {
   "canvas": "#211713",
   "surface": "#2d211b",
   "subtle": "#3a2b23",
   "border": "#594237",
   "text": "#f3e6dc",
   "muted": "#ccb2a0",
   "accent": "#a95f40",
   "accentText": "#ffffff",
   "focus": "#e8ac86",
   "success": "#93d5ae",
   "warning": "#e7c87b",
   "danger": "#f3a5a0",
   "info": "#a1c8ef",
   "overlay": "#000000",
   "actionWater": "#79baff",
   "actionPlant": "#9ad28e",
   "actionHarvest": "#f1ac72",
   "actionMaintenance": "#77cdbb",
   "actionEdit": "#edc164",
   "actionMedia": "#c6a3ee",
   "actionLink": "#79baff",
   "shadow": "#000000",
   "photoOverlay": "#08110c",
   "photoText": "#ffffff"
  }
 },
 "slate": {
  "light": {
   "canvas": "#f4f5f7",
   "surface": "#ffffff",
   "subtle": "#eceff3",
   "border": "#d8dde5",
   "text": "#293443",
   "muted": "#5b697d",
   "accent": "#586681",
   "accentText": "#ffffff",
   "focus": "#7284a2",
   "success": "#24734b",
   "warning": "#956c16",
   "danger": "#b34545",
   "info": "#356d9d",
   "overlay": "#12241b",
   "actionWater": "#1767a6",
   "actionPlant": "#38723f",
   "actionHarvest": "#985020",
   "actionMaintenance": "#237463",
   "actionEdit": "#86570c",
   "actionMedia": "#7351a2",
   "actionLink": "#1767a6",
   "shadow": "#12241b",
   "photoOverlay": "#142d21",
   "photoText": "#ffffff"
  },
  "dark": {
   "canvas": "#141820",
   "surface": "#1e2530",
   "subtle": "#293341",
   "border": "#3e4b5e",
   "text": "#e6ebf3",
   "muted": "#b3bfd0",
   "accent": "#596e91",
   "accentText": "#ffffff",
   "focus": "#a3b8db",
   "success": "#93d5ae",
   "warning": "#e7c87b",
   "danger": "#f3a5a0",
   "info": "#a1c8ef",
   "overlay": "#000000",
   "actionWater": "#79baff",
   "actionPlant": "#9ad28e",
   "actionHarvest": "#f1ac72",
   "actionMaintenance": "#77cdbb",
   "actionEdit": "#edc164",
   "actionMedia": "#c6a3ee",
   "actionLink": "#79baff",
   "shadow": "#000000",
   "photoOverlay": "#08110c",
   "photoText": "#ffffff"
  }
 }
} satisfies Record<string, { light: Palette; dark: Palette }>;
export type ThemePreset = keyof typeof themePresets;
export interface ThemeSettings { preset: ThemePreset | 'custom'; light: Palette; dark: Palette }
export function presetTheme(preset: ThemePreset = 'botanical'): ThemeSettings { return { preset, light: { ...themePresets[preset].light }, dark: { ...themePresets[preset].dark } }; }
export function readTheme(value: unknown): ThemeSettings {
 if (!value || typeof value !== 'object') return presetTheme();
 const candidate = value as ThemeSettings;
 if (!(candidate.preset === 'custom' || Object.hasOwn(themePresets, candidate.preset))) return presetTheme();
 const defaults = presetTheme(candidate.preset === 'custom' ? 'botanical' : candidate.preset);
 const light = { ...defaults.light, ...candidate.light }, dark = { ...defaults.dark, ...candidate.dark };
 if (![light, dark].every(palette => paletteKeys.every(key => /^#[0-9a-f]{6}$/i.test(palette[key])))) return presetTheme();
 return { preset: candidate.preset, light, dark };
}
function cachedTheme() { try { return readTheme(JSON.parse(localStorage.getItem('instance-theme') ?? 'null')); } catch { return presetTheme(); } }
export const instanceTheme = shallowRef<ThemeSettings>(cachedTheme());
const aliases: Record<PaletteKey, string[]> = {
 canvas: ['--app-canvas'], surface: ['--app-surface', '--theme-surface'], subtle: ['--app-subtle', '--theme-subtle'], border: ['--app-border', '--theme-border'],
 text: ['--app-text', '--theme-text'], muted: ['--app-muted', '--theme-muted'], accent: ['--theme-accent', '--app-accent', '--theme-accent-fill', '--theme-brand'], accentText: ['--theme-accent-text'],
 focus: ['--theme-focus'], success: ['--theme-success'], warning: ['--theme-warning'], danger: ['--theme-danger'], info: ['--theme-info'], overlay: ['--theme-overlay-color'],
 actionWater: ['--theme-action-water'], actionPlant: ['--theme-action-plant'], actionHarvest: ['--theme-action-harvest'], actionMaintenance: ['--theme-action-maintenance'], actionEdit: ['--theme-action-edit'], actionMedia: ['--theme-action-media'], actionLink: ['--theme-action-link'], shadow: ['--theme-shadow'], photoOverlay: ['--theme-photo-overlay'], photoText: ['--theme-photo-text'],
};
export function renderTheme(theme: ThemeSettings = instanceTheme.value, dark = appearanceIsDark.value) {
 const palette = theme[dark ? 'dark' : 'light']; const style = document.documentElement.style;
 for (const key of paletteKeys) for (const token of aliases[key]) style.setProperty(token, palette[key]);
 style.setProperty('--theme-overlay', `${palette.overlay}88`);
 for (const severity of ['danger', 'warning', 'info', 'success'] as const) style.setProperty(`--theme-${severity}-soft`, `color-mix(in srgb, ${palette[severity]} 14%, ${palette.surface})`);
 document.querySelector('meta[name="theme-color"]')?.setAttribute('content', palette.canvas);
}
export function applyTheme(value: unknown) {
 instanceTheme.value = readTheme(value); renderTheme();
 try { localStorage.setItem('instance-theme', JSON.stringify(instanceTheme.value)); } catch { /* Keep the current in-memory palette. */ }
}
export function initializeTheme() {
 renderTheme(); watch(appearanceIsDark, () => renderTheme(), { flush: 'sync' });
 window.addEventListener('storage', event => { if (event.key === 'instance-theme') { instanceTheme.value = cachedTheme(); renderTheme(); } });
}

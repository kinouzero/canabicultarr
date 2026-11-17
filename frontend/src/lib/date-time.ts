import { applyTheme, presetTheme, readTheme, type ThemeSettings } from './theme.ts';
import { applyBranding, type SiteBranding } from './branding.ts';
import { shallowRef } from 'vue';
import { defaultGeneticsColors, readGeneticsColors, type GeneticsColors } from './genetics-colors.ts';
export const dateFormats = ['d/m/Y', 'd.m.Y', 'd-m-Y', 'm/d/Y', 'Y-m-d', 'localized-short', 'localized', 'localized-long', 'localized-full'] as const;
export type DateFormat = typeof dateFormats[number];
export const dateFormatLabels: Record<DateFormat, string> = {
 'd/m/Y': 'dmy', 'd.m.Y': 'dmyDots', 'd-m-Y': 'dmyDashes', 'm/d/Y': 'mdy', 'Y-m-d': 'ymd',
 'localized-short': 'short', localized: 'localized', 'localized-long': 'long', 'localized-full': 'full',
};
const localizedDateStyles: Partial<Record<DateFormat, Intl.DateTimeFormatOptions['dateStyle']>> = {
 'localized-short': 'short', localized: 'medium', 'localized-long': 'long', 'localized-full': 'full',
};
export type TimeFormat = '24' | '12';
export interface DateTimeStyle { dateFormat: DateFormat; timeFormat: TimeFormat }
export interface InstanceDisplaySettings extends DateTimeStyle, SiteBranding { oidcProviderNameLocked?: boolean; oidcProviderIconLocked?: boolean; version: number; canManage: boolean; geneticsColors: GeneticsColors; theme: ThemeSettings }
const defaults: InstanceDisplaySettings = { oidcProviderName: 'Authentik', siteTitle: 'Canabicultarr', siteSubtitle: null, dateFormat: 'd/m/Y', timeFormat: '24', version: 0, canManage: false, theme: presetTheme(), geneticsColors: { ...defaultGeneticsColors } };
export const instanceDisplaySettings = shallowRef<InstanceDisplaySettings>({ ...defaults });
export function resetDisplaySettings() { instanceDisplaySettings.value = { ...defaults }; }
export function applyDisplaySettings(value: unknown) {
 if (!value || typeof value !== 'object') return;
 const candidate = value as Partial<InstanceDisplaySettings>;
 if (!dateFormats.includes(candidate.dateFormat as DateFormat) || !['24', '12'].includes(candidate.timeFormat ?? '') || !Number.isInteger(candidate.version) || candidate.version! < Math.max(1, instanceDisplaySettings.value.version)) return;
 applyBranding(candidate);
 applyTheme(candidate.theme);
 instanceDisplaySettings.value = { oidcProviderName: candidate.oidcProviderName ?? defaults.oidcProviderName, oidcProviderNameLocked: candidate.oidcProviderNameLocked === true, oidcProviderIcon: candidate.oidcProviderIcon ?? null, oidcProviderIconLocked: candidate.oidcProviderIconLocked === true, siteTitle: candidate.siteTitle ?? 'Canabicultarr', siteSubtitle: candidate.siteSubtitle ?? null, dateFormat: candidate.dateFormat!, timeFormat: candidate.timeFormat!, version: candidate.version!, canManage: candidate.canManage === true, geneticsColors: readGeneticsColors(candidate.geneticsColors), theme: readTheme(candidate.theme) };
}
const formatters = new Map<string, Intl.DateTimeFormat>();
function formatter(key: string, locale: string, options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat {
 let result = formatters.get(key);
 if (!result) { result = new Intl.DateTimeFormat(locale, options); formatters.set(key, result); }
 return result;
}
export function formatDateTime(value: string | Date, locale: string, part: 'date' | 'time' | 'datetime' = 'datetime', style: DateTimeStyle = instanceDisplaySettings.value): string {
 const date = value instanceof Date ? value : new Date(value);
 let day = '';
 if (part !== 'time') {
  const dateStyle = localizedDateStyles[style.dateFormat];
  if (dateStyle) day = formatter(`date:${locale}:${dateStyle}`, locale, { dateStyle }).format(date);
  else {
   const parts = Object.fromEntries(formatter('numeric', 'en-GB', { year: 'numeric', month: '2-digit', day: '2-digit', calendar: 'gregory', numberingSystem: 'latn' }).formatToParts(date).map(item => [item.type, item.value]));
   day = style.dateFormat === 'Y-m-d' ? `${parts.year}-${parts.month}-${parts.day}` : style.dateFormat === 'm/d/Y' ? `${parts.month}/${parts.day}/${parts.year}` : [parts.day, parts.month, parts.year].join(style.dateFormat === 'd.m.Y' ? '.' : style.dateFormat === 'd-m-Y' ? '-' : '/');
  }
 }
 const time = part === 'date' ? '' : formatter(`time:${locale}:${style.timeFormat}`, locale, { hour: '2-digit', minute: '2-digit', hourCycle: style.timeFormat === '24' ? 'h23' : 'h12' }).format(date);
 return [day, time].filter(Boolean).join(' ');
}

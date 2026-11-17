import { computed, shallowRef } from 'vue';
export interface SiteBranding { oidcProviderName: string; oidcProviderIcon?: string | null; siteTitle: string; siteSubtitle: string | null; version: number }
export const siteBranding = shallowRef<SiteBranding>({ oidcProviderName: 'Authentik', siteTitle: 'Canabicultarr', siteSubtitle: null, version: 0 });
export function applyBranding(value: unknown) {
 if (!value || typeof value !== 'object') return;
 const candidate = value as Partial<SiteBranding>;
 if (typeof candidate.siteTitle !== 'string' || !candidate.siteTitle.trim() || !Number.isInteger(candidate.version) || candidate.version! < Math.max(1, siteBranding.value.version)) return;
 siteBranding.value = { oidcProviderName: typeof candidate.oidcProviderName === 'string' && candidate.oidcProviderName.trim() ? candidate.oidcProviderName : siteBranding.value.oidcProviderName, oidcProviderIcon: typeof candidate.oidcProviderIcon === 'string' && /^[a-z0-9][a-z0-9-]{0,99}$/.test(candidate.oidcProviderIcon) ? candidate.oidcProviderIcon : null, siteTitle: candidate.siteTitle, siteSubtitle: typeof candidate.siteSubtitle === 'string' ? candidate.siteSubtitle : null, version: candidate.version! };
}

export const oidcProviderName = computed(() => siteBranding.value.oidcProviderName);

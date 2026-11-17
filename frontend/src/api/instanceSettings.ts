import type { ThemeSettings } from '../lib/theme';
import type { SiteBranding } from '../lib/branding';
import type { GeneticsColors } from '../lib/genetics-colors';
import { apiRequest } from './auth';
import type { DateTimeStyle, InstanceDisplaySettings } from '../lib/date-time';
export const instanceSettingsApi = {
 branding: () => apiRequest<SiteBranding>('branding'),
 get: () => apiRequest<InstanceDisplaySettings>('instance-settings'),
 save: (settings: DateTimeStyle & { geneticsColors: GeneticsColors; theme: ThemeSettings; oidcProviderName: string; oidcProviderNameLocked?: boolean; oidcProviderIcon?: string | null; oidcProviderIconLocked?: boolean; siteTitle: string; siteSubtitle: string | null }, version: number) => apiRequest<InstanceDisplaySettings>('instance-settings', { ...(settings.oidcProviderNameLocked ? {} : { oidc_provider_name: settings.oidcProviderName }), ...(settings.oidcProviderIconLocked ? {} : { oidc_provider_icon: settings.oidcProviderIcon || null }), site_title: settings.siteTitle, site_subtitle: settings.siteSubtitle, date_format: settings.dateFormat, time_format: settings.timeFormat, genetics_colors: settings.geneticsColors, theme: settings.theme, version }, { method: 'PATCH' }),
};

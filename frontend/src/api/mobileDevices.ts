import { apiRequest } from './auth';

export interface MobileDevice {
  id: string;
  label: string;
  paired_at: string;
  current: boolean;
}

export const mobileDevicesApi = {
  list: () => apiRequest<MobileDevice[]>('account/mobile-devices'),
  issue: () => apiRequest<{ code: string; expires_at: string }>('account/mobile-devices/pairing', {}),
  redeem: (code: string, label: string) => apiRequest<{ id: string }>('account/mobile-devices/redeem', { code, label }),
  remove: (id: string) => apiRequest<void>(`account/mobile-devices/${encodeURIComponent(id)}`, {}, { method: 'DELETE' }),
};

import { apiRequest } from './auth';

export interface PasswordResetInput { email: string; token: string; password: string; password_confirmation: string }
export const passwordRecoveryApi = {
  status: () => apiRequest<{ enabled: boolean }>('auth/password-recovery'),
  request: (email: string, locale: 'en' | 'fr') => apiRequest<void>('auth/forgot-password', { email, locale }),
  reset: (data: PasswordResetInput) => apiRequest<void>('auth/reset-password', data),
};

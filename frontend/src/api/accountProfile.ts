import { apiRequest, type AuthUser } from './auth';
export const accountProfileApi = {
 update: (input: { name: string; email: string; locale?: 'en' | 'fr' }) => apiRequest<AuthUser>('account/profile', input, { method: 'PATCH' }),
 resendEmail: (locale: 'en' | 'fr') => apiRequest<AuthUser>('account/email-change/resend', { locale }),
 cancelEmail: () => apiRequest<AuthUser>('account/email-change', undefined, { method: 'DELETE' }),
};

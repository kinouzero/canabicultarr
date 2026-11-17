import { apiRequest } from './auth';
export interface AccountSecurityStatus { localPasswordAvailable: boolean; databaseSessions: boolean; otherSessions: number | null }
export interface ChangeOwnPassword { current_password: string; password: string; password_confirmation: string }
export type SecurityResult = 'passwordChanged' | 'sessionsRevoked';
export const accountSecurityApi = {
  status: () => apiRequest<AccountSecurityStatus>('account/security'),
  changePassword: (data: ChangeOwnPassword) => apiRequest<void>('account/password', data),
  revokeSessions: (current_password: string) => apiRequest<void>('account/sessions/revoke', { current_password }),
};

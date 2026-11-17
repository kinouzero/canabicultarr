import { apiRequest, type AuthUser } from './auth';
import type { DelegatedPermission } from './permissions';

export type ManagedUser = Pick<AuthUser, 'id' | 'name' | 'email' | 'pendingEmail' | 'avatarUrl' | 'isActive' | 'permissionRoles' | 'locationAccess' | 'inventoryLocationAccess'> & { emailActivationRequired: boolean; permissionGrants: DelegatedPermission[]; permissionDenials: DelegatedPermission[]; permissions?: DelegatedPermission[]; oidcLinked?: boolean };

export interface UserInput {
  locale?: 'en' | 'fr';
  name: string;
  email: string;
  is_active: boolean;
  inventory_location_access?: string[] | null;
  location_access?: { type: 'site' | 'room' | 'zone'; id: string }[] | null;
  permission_role_ids: string[];
  permission_denials?: DelegatedPermission[];
  permission_grants?: DelegatedPermission[];
  password?: string;
  password_confirmation?: string;
}

export interface UserPage {
  summary?: { total: number; active: number; inactive: number; administrators: number };
  data: ManagedUser[];
  meta: { current_page: number; last_page: number; total: number; per_page: number };
}

export const usersApi = {
  list: (search = '', page = 1, filters: Record<string, string> = {}) => apiRequest<UserPage>(`users?${new URLSearchParams({ ...filters, search, page: String(page) })}`, undefined, { raw: true }),
  resendEmail: (id: string, locale: 'en' | 'fr') => apiRequest<ManagedUser>(`users/${encodeURIComponent(id)}/email-change/resend`, { locale }),
  cancelEmail: (id: string) => apiRequest<ManagedUser>(`users/${encodeURIComponent(id)}/email-change`, undefined, { method: 'DELETE' }),
  create: (input: UserInput) => apiRequest<ManagedUser>('users', input),
  update: (id: string, input: UserInput) => apiRequest<ManagedUser>(`users/${encodeURIComponent(id)}`, input, { method: 'PATCH' }),
};

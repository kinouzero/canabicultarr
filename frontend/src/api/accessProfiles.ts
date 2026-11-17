import { apiRequest } from './auth';
import type { DelegatedPermission } from './permissions';
import type { Page } from './types';
export interface AccessProfile { id: string; name: string; description: string | null; permissions: DelegatedPermission[]; isArchived: boolean; version: number; assignedUsers: number }
export interface AccessProfileInput { name: string; description: string | null; permissions: DelegatedPermission[]; is_archived: boolean; version?: number }
export const accessProfilesApi = {
  list: (search = '', page = 1, status: 'active' | 'archived' | 'all' = 'active', sort = 'name_asc', perPage = 20) => apiRequest<Page<AccessProfile>>(`permission-roles?${new URLSearchParams({ search, page: String(page), status, sort, per_page: String(perPage) })}`, undefined, { raw: true }),
  create: (input: AccessProfileInput) => apiRequest<AccessProfile>('permission-roles', input),
  update: (id: string, input: AccessProfileInput) => apiRequest<AccessProfile>(`permission-roles/${encodeURIComponent(id)}`, input, { method: 'PATCH' }),
};

const profileNames: Record<string, string> = { Administrator: 'administrator', Observer: 'observer', Grower: 'grower', 'Cultivation manager': 'cultivationManager', Technician: 'technician', 'Stock manager': 'stockManager', 'Monitoring manager': 'monitoringManager' };
export function accessProfileName(name: string, t: (key: string) => string): string { return profileNames[name] ? t(`users.profileNames.${profileNames[name]}`) : name; }

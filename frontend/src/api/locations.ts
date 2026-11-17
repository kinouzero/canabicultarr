import type { PlantDashboardPage } from './plants';
import type { Page, LocationContext } from './types';
import type { ReferenceOption } from './sensors';
import { apiRequest } from './auth';

export interface LocationNode {
  id: string;
  kind: 'site' | 'room' | 'zone';
  name: string;
  code?: string | null;
  description: string | null;
  photoUrl?: string | null;
  hasPhoto?: boolean;
  wateringAlertDays?: number | null;
  parentId: string | null;
  childrenCount: number;
  canSelect?: boolean;
  canManage?: boolean;
}
export type LocationPlant = PlantDashboardPage['data'][number];
export interface LocationOption extends ReferenceOption { context: LocationContext; ancestorSelection: { site: boolean; room: boolean } }
export interface LocationInput { name: string; code?: string; description: string | null; watering_alert_days?: number | null }

export const locationsApi = {
  detail: (kind: string, id: string) => apiRequest<LocationNode>(`locations/${encodeURIComponent(kind)}/${encodeURIComponent(id)}`),
  plants: (location: Pick<LocationNode, 'kind' | 'id'>, page = 1, perPage = 20, status = 'all') => apiRequest<Page<LocationPlant>>(`locations/${location.kind}/${encodeURIComponent(location.id)}/plants?${new URLSearchParams({ page: String(page), per_page: String(perPage), status })}`, undefined, { raw: true }),
  options: (search: string, page = 1) => apiRequest<Page<LocationOption>>(`locations/options?${new URLSearchParams({ search, page: String(page) })}`, undefined, { raw: true }),
  context: (kind: string, id: string) => apiRequest<LocationOption>(`locations/${encodeURIComponent(kind)}/${encodeURIComponent(id)}/context`),
  list: (path: string, search = '', page = 1, sort = 'name_asc', perPage = 20) => apiRequest<Page<LocationNode>>(`${path}?${new URLSearchParams({ search, page: String(page), sort, per_page: String(perPage) })}`, undefined, { raw: true }),
  remove: (path: string, id: string) => apiRequest<void>(`${path}/${encodeURIComponent(id)}`, undefined, { method: 'DELETE' }),
  photo: (path: string, id: string, photoId: string | null) => apiRequest<LocationNode>(`${path}/${encodeURIComponent(id)}`, { photo_document_id: photoId }, { method: 'PATCH' }),
  create: (path: string, input: LocationInput) => apiRequest<LocationNode>(path, input),
  update: (path: string, id: string, input: LocationInput) => apiRequest<LocationNode>(`${path}/${encodeURIComponent(id)}`, input, { method: 'PATCH' }),
};

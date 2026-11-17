import { documentsApi } from './documents';
import { apiRequest } from './auth';
import type { Page } from './types';
export interface LibraryPhoto { id: string; name: string; detail: string; url: string; disabled: boolean }
export interface RecordPhoto { id: string; name: string; url: string; createdAt: string | null; isCover: boolean }
export const photosApi = {
 async library(search: string, page: number) {
  const result = await documentsApi.list({ search, format: 'image', state: 'active' }, page);
  return { ...result, data: result.data.filter(row => row.format === 'image' && ['image/jpeg', 'image/png', 'image/webp'].includes(row.mediaType)).map(row => ({ id: row.id, name: row.title, detail: row.originalName, url: `/api/v1/documents/${encodeURIComponent(row.id)}/preview`, disabled: row.isArchived })) };
 },
 upload(file: File, name: string, subject: { type: string; id: string }, disk?: string) {
  const data = new FormData(); data.append('file', file); data.append('title', name); if (disk) data.append('disk', disk);
  data.append('subject[type]', subject.type); data.append('subject[id]', subject.id);
  return documentsApi.upload(data);
 },
 list: (kind: 'plant' | 'strain' | 'site' | 'room' | 'zone', id: string, page = 1) => apiRequest<Page<RecordPhoto>>(`${kind === 'plant' ? 'plants' : kind === 'strain' ? 'strains' : `locations/${kind}`}/${encodeURIComponent(id)}/photos?page=${page}`, undefined, { raw: true }),
};

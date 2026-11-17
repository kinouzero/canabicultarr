import { downloadBlob } from '../lib/download';
import { apiRequest, apiResponse } from './auth';
import type { Page } from './types';
import type { EventSubject } from './events';
export type DocumentSubject = Omit<EventSubject, 'type'> & { type: EventSubject['type'] | 'event' | 'strain' };
export interface LibraryDocument {
 disk?: string; folderId?: string | null; id: string; title: string; description: string | null; originalName: string; mediaType: string; format: 'pdf' | 'image' | 'text';
 sizeBytes: number; sha256: string; version: number; isArchived: boolean; subject: DocumentSubject | null;
 uploader: { id: string; name: string | null }; createdAt: string; updatedAt: string; canEdit: boolean;
}
export interface DocumentFolder { id: string; name: string; parentId: string | null; version: number; canEdit: boolean; hasChildren: boolean }
export interface DocumentFolderInput { name: string; parent_id: string | null; version?: number }
export interface DocumentInput { folder_id?: string | null; title: string; description: string | null; subject: Pick<DocumentSubject, 'type' | 'id'> | null; version: number; is_archived: boolean }
export const documentFoldersApi = {
 list: (parent: string | null = null, page = 1, signal?: AbortSignal) => apiRequest<Page<DocumentFolder>>(`document-folders?${new URLSearchParams({ parent_id: parent ?? '', page: String(page), per_page: '50' })}`, undefined, { raw: true, signal }),
 paths: (ids: string[], signal?: AbortSignal) => apiRequest<DocumentFolder[]>(`document-folders/paths?${new URLSearchParams(ids.map(id => ['ids[]', id]))}`, undefined, { signal }),
 show: (id: string, signal?: AbortSignal) => apiRequest<{ data: DocumentFolder; ancestors: DocumentFolder[] }>(`document-folders/${encodeURIComponent(id)}`, undefined, { raw: true, signal }),
 create: (input: DocumentFolderInput) => apiRequest<DocumentFolder>('document-folders', input),
 update: (id: string, input: DocumentFolderInput) => apiRequest<DocumentFolder>(`document-folders/${encodeURIComponent(id)}`, input, { method: 'PATCH' }),
 remove: (id: string, version: number) => apiRequest<void>(`document-folders/${encodeURIComponent(id)}`, { version }, { method: 'DELETE' }),
};
export const documentsApi = {
 storageOptions: () => apiRequest<{ default: string; disks: string[] }>('documents/storage-options'),
 list: (filters: Record<string, string>, page = 1) => apiRequest<Page<LibraryDocument>>(`documents?${new URLSearchParams({ ...filters, page: String(page) })}`, undefined, { raw: true }),
 upload: (data: FormData) => apiRequest<LibraryDocument>('documents', data),
 update: (id: string, data: Partial<DocumentInput> & { version: number }) => apiRequest<LibraryDocument>(`documents/${encodeURIComponent(id)}`, data, { method: 'PATCH' }),
 show: (id: string) => apiRequest<LibraryDocument>(`documents/${encodeURIComponent(id)}`),
 async preview(id: string, signal: AbortSignal, image = false) {
  const path = `documents/${encodeURIComponent(id)}/${image ? 'image-preview' : 'preview'}`;
  const response = await apiResponse(path, { signal, headers: { Range: 'bytes=0-0' } });
  if (response.headers.get('Content-Type')?.split(';')[0] === 'application/pdf') {
   await response.body?.cancel();
   return { pdfUrl: `/api/v1/${path}`, blob: null, truncated: false };
  }
  return { pdfUrl: null, blob: await response.blob(), truncated: response.headers.get('X-Preview-Truncated') === 'true' };
 },
 async download(entry: Pick<LibraryDocument, 'id' | 'originalName'>) {
  const response = await apiResponse(`documents/${encodeURIComponent(entry.id)}/download`, { headers: { Accept: 'application/octet-stream' } });
  downloadBlob(await response.blob(), entry.originalName);
 },
};

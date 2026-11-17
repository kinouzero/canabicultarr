import { apiRequest } from './auth';
import type { Page } from './types';
export interface CommentTemplate { id: string; name: string; body: string; isArchived: boolean; version: number }
export interface CommentTemplateInput { name: string; body: string; is_archived: boolean; version?: number }
export const commentTemplatesApi = {
 list: (search = '', page = 1, status = 'active', sort = 'name_asc', perPage = 20) => apiRequest<Page<CommentTemplate> & { canManage: boolean }>(`comment-templates?${new URLSearchParams({ search, page: String(page), status, sort, per_page: String(perPage) })}`, undefined, { raw: true }),
 create: (input: CommentTemplateInput) => apiRequest<CommentTemplate>('comment-templates', input),
 update: (id: string, input: CommentTemplateInput) => apiRequest<CommentTemplate>(`comment-templates/${encodeURIComponent(id)}`, input, { method: 'PATCH' }),
};

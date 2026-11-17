import { apiRequest } from './auth';
export interface PurgeRun { id: string; mode: 'manual' | 'policy'; days: number; cutoffAt: string; status: 'queued' | 'running' | 'completed' | 'cancelled' | 'failed'; deletedCount: number; createdAt: string; completedAt: string | null; errorCode: string | null }
export interface RetentionState { policy: { days: number | null; version: number; approvedAt: string | null }; runs: PurgeRun[] }
export interface RetentionPreview { token: string; mode: 'manual' | 'policy'; days: number; cutoffAt: string; count: number; countIsExact: boolean; expiresAt: string }
export const measurementRetentionApi = {
 get: () => apiRequest<RetentionState>('measurement-retention'),
 preview: (days: number, mode: 'manual' | 'policy', version: number) => apiRequest<RetentionPreview>('measurement-retention/preview', { days, mode, version }),
 confirm: (token: string) => apiRequest<PurgeRun>('measurement-retention/confirm', { token, confirmed: true }),
 disable: (version: number) => apiRequest<RetentionState>('measurement-retention', { version }, { method: 'PATCH' }),
 cancel: (id: string) => apiRequest<RetentionState>(`measurement-retention/runs/${encodeURIComponent(id)}/cancel`, {}),
};

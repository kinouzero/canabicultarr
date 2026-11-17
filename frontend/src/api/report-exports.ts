import { apiRequest, apiResponse, ApiError } from './auth';
import { downloadBlob } from '../lib/download';
export interface MeasurementExport {
 id: string; sensorId: string | null; format: 'csv' | 'pdf'; status: 'queued' | 'processing' | 'ready' | 'failed' | 'cancelled' | 'expired';
 canDownload: boolean; progress: number; processedRows: number; totalRows: number | null; bytes: number | null; errorCode: string | null; createdAt: string; expiresAt: string;
}
export const reportExportsApi = {
 list: (sensorId: string, signal?: AbortSignal) => apiRequest<MeasurementExport[]>(`reports/sensors/${encodeURIComponent(sensorId)}/exports`, undefined, { signal }),
 cultureList: (signal?: AbortSignal) => apiRequest<MeasurementExport[]>('culture/overview/exports', undefined, { signal }),
 createCulture: (input: Record<string, unknown>) => apiRequest<MeasurementExport>('culture/overview/exports', input),
 create: (sensorId: string, input: Record<string, string>) => apiRequest<MeasurementExport>(`reports/sensors/${encodeURIComponent(sensorId)}/exports`, input),
 cancel: (id: string) => apiRequest<void>(`report-exports/${encodeURIComponent(id)}/cancel`, {}),
 download: async (exported: MeasurementExport) => {
  const response = await apiResponse(`report-exports/${encodeURIComponent(exported.id)}/download`, { headers: { Accept: 'application/pdf, text/csv, application/json' } });
  if (!response.headers.get('Content-Type')?.startsWith(exported.format === 'pdf' ? 'application/pdf' : 'text/csv')) throw new ApiError(503, 'reportExports.errors.failed');
  downloadBlob(await response.blob(), `${exported.sensorId ? 'measurements' : 'culture'}_${exported.id}.${exported.format}`);
 },
};

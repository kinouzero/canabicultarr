import { downloadBlob } from '../lib/download';
import { apiRequest, apiResponse } from './auth';
import type { Sensor } from './sensors';
export interface ReportObservation { quality?: import('./measurements').MeasurementQuality; id: string; value: string; unit: string | null; observedAt: string; receivedAt: string }
export interface ReportStatistics { count: number; minimum: string | null; maximum: string | null; mean: string | null }
export interface ReportBucket extends ReportStatistics { startAt: string; endAt: string }
export interface SensorReport {
 sensor: Sensor; window: { from: string; to: string; asOf: string; groupBy: 'hour' | 'day' };
 statistics: ReportStatistics & { first: ReportObservation | null; last: ReportObservation | null; largestGapSeconds: number; gapCount: number | null; gapThresholdSeconds: number | null };
 buckets: ReportBucket[]; alerts: { overlapping: number; opened: number; resolved: number; acknowledged: number; activeAtEnd: number }; exportLimit: number;
}
async function exportReadings(id: string, filters: Record<string, string>) {
 const response = await apiResponse(`reports/sensors/${encodeURIComponent(id)}/export?${new URLSearchParams(filters)}`, { headers: { Accept: 'text/csv, application/json' } });
 const suggested = response.headers.get('Content-Disposition')?.match(/filename="?([A-Za-z0-9_.-]+)"?(?:;|$)/)?.[1] ?? `measurements_${id}.csv`;
 downloadBlob(await response.blob(), suggested);
}
export const reportsApi = {
 show: (id: string, filters: Record<string, string>) => apiRequest<SensorReport>(`reports/sensors/${encodeURIComponent(id)}?${new URLSearchParams(filters)}`),
 export: exportReadings,
};

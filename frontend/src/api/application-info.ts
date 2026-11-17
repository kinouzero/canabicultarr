import { apiRequest } from './auth';

export interface ApplicationInfo {
 version: string;
 repositoryUrl: string;
 runtime: { php: string; laravel: string };
 update: {
  status: 'available' | 'current' | 'noRelease' | 'inaccessible' | 'limited' | 'unavailable' | 'unknownVersion';
  latestVersion: string | null;
  releaseUrl: string | null;
  prerelease: boolean;
  checkedAt: string;
 };
}
export const applicationInfoApi = { get: () => apiRequest<ApplicationInfo>('application-info') };

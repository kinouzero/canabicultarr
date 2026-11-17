import type { ApplicationInfo } from '../../src/api/application-info';

export function applicationInfoFixture(status: ApplicationInfo['update']['status'] = 'noRelease'): ApplicationInfo {
 const latestVersion = status === 'available' ? '0.2.0' : status === 'current' ? '0.1.0' : null;
 return {
  version: '0.1.0', repositoryUrl: 'https://github.com/kinouzero/canabicultarr', runtime: { php: '8.5.12', laravel: '13.0.0' },
  update: { status, latestVersion, releaseUrl: latestVersion ? `https://github.com/kinouzero/canabicultarr/releases/tag/v${latestVersion}` : null, prerelease: false, checkedAt: new Date().toISOString() },
 };
}

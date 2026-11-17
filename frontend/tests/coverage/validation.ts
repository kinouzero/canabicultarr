import type { CoverageReport } from 'monocart-coverage-reports';

export function assertBrowserCoverage(
  result: Awaited<ReturnType<CoverageReport['generate']>>,
  minimum = Number(process.env.APP_COVERAGE_MIN_BYTES ?? 0),
) {
  if (!Number.isFinite(minimum) || minimum < 0 || minimum > 100) {
    throw new Error('APP_COVERAGE_MIN_BYTES must be a percentage between 0 and 100.');
  }
  if (!result?.files?.some(file => file.sourcePath.endsWith('src/App.vue') && (file.summary.bytes?.covered ?? 0) > 0)) {
    throw new Error('Application source maps missing: run npm run build:coverage and serve dist-coverage.');
  }
  const bytes = result.summary.bytes;
  if (!bytes || bytes.total === 0 || bytes.covered / bytes.total * 100 < minimum) {
    throw new Error(`Application browser byte coverage is below ${minimum}%. See coverage/browser/index.html.`);
  }
}

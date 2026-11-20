import { test as base, type Page } from '@playwright/test';
export * from '@playwright/test';

export async function withBrowserCoverage(page: Page, browserName: string, use: (page: Page) => Promise<void>) {
  if (process.env.APP_COVERAGE !== '1') { await use(page); return; }
  if (browserName !== 'chromium') throw new Error('Browser coverage requires Chromium.');
  await page.coverage.startJSCoverage({ resetOnNavigation: false });
  try { await use(page); }
  finally {
    if (!page.isClosed()) {
      const coverage = await page.coverage.stopJSCoverage();
      const { CoverageReport } = await import('monocart-coverage-reports');
      const { coverageOptions } = await import('./options');
      await new CoverageReport(coverageOptions).add(coverage);
    }
  }
}

export const test = base.extend({
  page: async ({ page, browserName }, use) => withBrowserCoverage(page, browserName, use),
});

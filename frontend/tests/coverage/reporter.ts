import { cp, mkdtemp, rm } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import type { Reporter } from '@playwright/test/reporter';
import { CoverageReport } from 'monocart-coverage-reports';
import { coverageOptions } from './options';
import { assertBrowserCoverage } from './validation.ts';

export default class BrowserCoverageReporter implements Reporter {
  private report = new CoverageReport(coverageOptions);

  onBegin() {
    if (process.env.APP_COVERAGE_APPEND !== '1') this.report.cleanCache();
  }

  async onEnd() {
    if (process.env.APP_COVERAGE_DEFER === '1') return;
    if (process.env.APP_COVERAGE_SHARD === '1') {
      // Export native raw data; enforce the floor only after all isolated shards merge.
      await new CoverageReport({ ...coverageOptions, all: undefined, reports: [['raw', { merge: true }]] }).generate();
      return;
    }
    // Monocart removes raw coverage after generation; retain it for focused append runs.
    const cache = join(String(coverageOptions.outputDir), '.cache');
    const backup = await mkdtemp(join(tmpdir(), 'canabicultarr-coverage-'));
    let result: Awaited<ReturnType<CoverageReport['generate']>>;
    try {
      await cp(cache, join(backup, 'cache'), { recursive: true });
      result = await this.report.generate();
    } finally {
      await cp(join(backup, 'cache'), cache, { recursive: true });
      await rm(backup, { recursive: true, force: true });
    }
    assertBrowserCoverage(result);
  }
}

import { readdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { CoverageReport, type CoverageReportOptions } from 'monocart-coverage-reports';
import { coverageOptions } from './options.ts';
import { assertBrowserCoverage } from './validation.ts';

export async function mergeBrowserCoverage(directories: string[], options: CoverageReportOptions = coverageOptions) {
  if (directories.length < 2) throw new Error('Provide at least two browser shard raw directories.');
  const inputDir = directories.map(directory => resolve(directory));
  for (const directory of inputDir) {
    if (!(await readdir(directory)).some(file => /^coverage-.*\.json$/.test(file))) {
      throw new Error(`Missing browser coverage in ${directory}.`);
    }
  }
  const result = await new CoverageReport({ ...options, inputDir }).generate();
  assertBrowserCoverage(result);
  return result;
}

import { resolve } from 'node:path';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import type { CoverageReportOptions } from 'monocart-coverage-reports';

const frontend = resolve(import.meta.dirname, '../..');
export const coverageOptions: CoverageReportOptions = {
  name: 'Canabicultarr browser coverage',
  outputDir: resolve(frontend, 'coverage/browser'),
  baseDir: frontend,
  reports: ['v8', 'json-summary', 'lcovonly', 'console-summary'],
  entryFilter: entry => /\/assets\/.*\.js(?:\?|$)/.test(entry.url),
  sourceFilter: path => !path.includes('node_modules') && (/(?:^|\/)src\//.test(path) || path.includes('dist-coverage/assets/')),
  outputFilter: file => /(?:^|\/)src\//.test(file.sourcePath) && !file.sourcePath.includes('node_modules') && /\.(ts|vue)$/.test(file.sourcePath),
  all: {
    dir: resolve(frontend, 'dist-coverage/assets'),
    filter: path => path.endsWith('.js') && existsSync(`${path}.map`),
    transformer: async entry => {
      entry.sourceMap = JSON.parse(await readFile(new URL(`${entry.url}.map`), 'utf8'));
    },
  },
};

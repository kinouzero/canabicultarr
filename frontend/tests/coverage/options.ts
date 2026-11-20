import { resolve } from 'node:path';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import type { CoverageReportOptions } from 'monocart-coverage-reports';

const frontend = resolve(import.meta.dirname, '../..');
const coverageAssets = resolve(frontend, 'dist-coverage/assets');
export const coverageOptions: CoverageReportOptions = {
  name: 'Canabicultarr browser coverage',
  outputDir: resolve(frontend, 'coverage/browser'),
  baseDir: frontend,
  reports: ['v8', 'json-summary', 'lcovonly', 'console-summary'],
  entryFilter: entry => /\/assets\/.*\.js(?:\?|$)/.test(entry.url),
  async onEntry(entry) {
    const url = new URL(entry.url);
    const asset = /^\/assets\/([\w.-]+\.js)$/.exec(url.pathname)?.[1];
    // The Node coverage collector cannot fetch maps from mocked HTTPS origins.
    if (url.protocol === 'https:' && asset && !entry.sourceMap) {
      const mapPath = resolve(coverageAssets, `${asset}.map`);
      if (existsSync(mapPath)) entry.sourceMap = JSON.parse(await readFile(mapPath, 'utf8'));
    }
  },
  sourceFilter: path => !path.includes('node_modules') && (/(?:^|\/)src\//.test(path) || path.includes('dist-coverage/assets/')),
  outputFilter: file => /(?:^|\/)src\//.test(file.sourcePath) && !file.sourcePath.includes('node_modules') && /\.(ts|vue)$/.test(file.sourcePath),
  all: {
    dir: coverageAssets,
    filter: path => path.endsWith('.js') && existsSync(`${path}.map`),
    transformer: async entry => {
      entry.sourceMap = JSON.parse(await readFile(new URL(`${entry.url}.map`), 'utf8'));
    },
  },
};

import assert from 'node:assert/strict';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { CoverageReport, type CoverageReportOptions } from 'monocart-coverage-reports';
import { mergeBrowserCoverage } from './coverage/merge.ts';
import { assertBrowserCoverage } from './coverage/validation.ts';

const source = 'const first = 1;\nconst second = 2;\n';
const sourceMap = (name: string) => ({ version: 3, sources: [`src/${name}.vue`], sourcesContent: [source], names: [], mappings: 'AAAA;AACA' });

test('browser shard coverage merges complementary execution and counts unloaded modules', async () => {
  const root = await mkdtemp(join(tmpdir(), 'canabicultarr-shards-'));
  try {
    const directories: string[] = [];
    for (const index of [0, 1]) {
      const directory = join(root, String(index));
      const report = new CoverageReport({
        outputDir: directory, logging: 'off', reports: [['raw', { merge: true }]],
      });
      await report.add([{
        url: 'http://localhost/assets/app.js', source, sourceMap: sourceMap('App'),
        functions: [{ functionName: '', isBlockCoverage: true, ranges: [
          { startOffset: 0, endOffset: source.length, count: 1 },
          { startOffset: index === 0 ? 17 : 0, endOffset: index === 0 ? source.length : 17, count: 0 },
        ] }],
      }]);
      await report.generate();
      directories.push(join(directory, 'raw'));
    }
    const unloaded = join(root, 'unloaded');
    await mkdir(unloaded);
    await writeFile(join(unloaded, 'unused.js'), source);
    const options: CoverageReportOptions = {
      outputDir: join(root, 'merged'), logging: 'off', reports: ['json-summary'],
      all: { dir: unloaded, filter: path => path.endsWith('.js'), transformer: async entry => { entry.sourceMap = sourceMap('Unloaded'); } },
    };
    const result = await mergeBrowserCoverage(directories, options);
    const app = result!.files.find(file => file.sourcePath.endsWith('src/App.vue'))!;
    assert.equal(app.summary.bytes!.covered, app.summary.bytes!.total);
    assert.equal(result!.files.find(file => file.sourcePath.endsWith('src/Unloaded.vue'))!.summary.bytes!.covered, 0);
    assert.throws(() => assertBrowserCoverage(result, 90), /below 90%/);
    assert.throws(() => assertBrowserCoverage(result, -1), /between 0 and 100/);
    assert.throws(() => assertBrowserCoverage(result, NaN), /between 0 and 100/);
    assert.throws(() => assertBrowserCoverage(undefined), /source maps missing/);
    await assert.rejects(mergeBrowserCoverage([directories[0]!], options), /at least two/);
    await assert.rejects(mergeBrowserCoverage([...directories, unloaded], options), /Missing browser coverage/);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

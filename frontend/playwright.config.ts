import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  // Shards run on isolated runners; retain one database-writing worker per shard.
  fullyParallel: process.env.APP_COVERAGE_SHARD === '1',
  workers: 1,
  retries: 0,
  timeout: 60_000,
  reporter: process.env.APP_COVERAGE === '1' ? [['list'], ['./tests/coverage/reporter.ts']] : 'list',
  outputDir: 'test-results',
  use: {
    baseURL: 'http://127.0.0.1:18080',
    channel: 'chromium',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    launchOptions: {
      executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH,
    },
  },
});

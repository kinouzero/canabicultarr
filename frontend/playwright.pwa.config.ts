import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/pwa',
  testMatch: '**/*.spec.ts',
  workers: 1,
  retries: 0,
  timeout: 30_000,
  reporter: process.env.APP_COVERAGE === '1' ? [['list'], ['./tests/coverage/reporter.ts']] : 'list',
  outputDir: 'test-results-pwa',
  use: {
    baseURL: 'http://127.0.0.1:18081',
    // Full Chromium honors notification permissions; the headless shell does not.
    channel: 'chromium',
    serviceWorkers: 'allow',
    trace: 'retain-on-failure', screenshot: 'only-on-failure', video: 'off',
    launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH },
  },
  webServer: {
    command: 'npm run preview -- --host 127.0.0.1 --port 18081 --strictPort',
    url: 'http://127.0.0.1:18081',
    reuseExistingServer: false,
    timeout: 30_000,
  },
});

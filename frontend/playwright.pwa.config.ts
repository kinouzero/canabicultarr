import { defineConfig } from '@playwright/test';

const coverage = process.env.APP_COVERAGE === '1';
const browserName = process.env.PLAYWRIGHT_PWA_BROWSER === 'webkit' ? 'webkit' : 'chromium';

export default defineConfig({
  testDir: './tests/pwa',
  testMatch: '**/*.spec.ts',
  grep: browserName === 'webkit' ? /@cross-engine/ : undefined,
  workers: 1,
  retries: 0,
  timeout: 30_000,
  reporter: coverage ? [['list'], ['./tests/coverage/reporter.ts']] : 'list',
  outputDir: 'test-results-pwa',
  use: {
    baseURL: 'http://127.0.0.1:18081',
    // Full Chromium honors notification permissions; the headless shell does not.
    browserName,
    channel: browserName === 'chromium' ? 'chromium' : undefined,
    serviceWorkers: 'allow',
    trace: 'retain-on-failure', screenshot: 'only-on-failure', video: 'off',
    launchOptions: browserName === 'chromium' ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {},
  },
  webServer: {
    command: 'npm run preview -- --host 127.0.0.1 --port 18081 --strictPort' + (coverage ? ' --outDir dist-coverage' : ''),
    url: 'http://127.0.0.1:18081',
    reuseExistingServer: false,
    timeout: 30_000,
  },
});

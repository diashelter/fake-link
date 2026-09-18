import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  testMatch: ['links-query.spec.ts'],
  workers: 1,
  timeout: 90_000,
  forbidOnly: !!process.env.CI,
  outputDir: '.artifacts/test-results',
  globalTeardown: './global-teardown.ts',
  reporter: [['list']],
  use: {
    baseURL: process.env.E2E_APP_ORIGIN,
    ignoreHTTPSErrors: true,
    trace: 'off',
    video: 'off',
    screenshot: 'off',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: {
          args: [
            '--host-resolver-rules=MAP app.localhost:443 nginx:443, MAP go.localhost:443 nginx:443',
          ],
        },
      },
    },
  ],
});

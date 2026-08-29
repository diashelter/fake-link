import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  workers: 1,
  forbidOnly: !!process.env.CI,
  outputDir: '.artifacts/test-results',
  reporter: [
    ['list'],
    ['html', { outputFolder: '.artifacts/html', open: 'never' }],
  ],
  use: {
    baseURL: process.env.E2E_APP_ORIGIN,
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    video: 'retain-on-failure',
    screenshot: 'only-on-failure',
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

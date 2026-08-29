import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  // Explicit order: smoke first, then journey (registers user), then the rest.
  testMatch: [
    '_smoke.spec.ts',
    'journey.spec.ts',
    'bearer-absence.spec.ts',
    'csrf-origin-returnurl.spec.ts',
    'session-lifecycle.spec.ts',
    'a11y.spec.ts',
    'guards.spec.ts',
  ],
  workers: 1,
  forbidOnly: !!process.env.CI,
  outputDir: '.artifacts/test-results',
  globalTeardown: './global-teardown.ts',
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

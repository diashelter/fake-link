import { defineConfig } from 'vitest/config';
import path from 'node:path';

export default defineConfig({
  test: {
    environment: 'node',
    include: ['**/*.test.ts', '**/*.test.tsx'],
    exclude: ['**/node_modules/**', '**/.next/**', '**/coverage/**'],
    setupFiles: ['./vitest.setup.ts'],
    testTimeout: 15_000,
    environmentMatchGlobs: [
      ['**/*.test.tsx', 'jsdom'],
      ['**/*.test.ts', 'node'],
    ],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json-summary', 'lcov'],
      include: [
        'modules/**/*.{ts,tsx}',
        'app/register/**/*.{ts,tsx}',
        'app/terms/**/*.{ts,tsx}',
        'app/api/bff/auth/register/**/*.{ts,tsx}',
        'app/verify-email/**/*.{ts,tsx}',
        'app/api/bff/auth/email/**/*.{ts,tsx}',
        'app/page.tsx',
      ],
      exclude: [
        'modules/**/*.test.{ts,tsx}',
        'modules/**/index.ts',
        'modules/**/test/**',
        'app/**/*.test.{ts,tsx}',
      ],
      thresholds: {
        lines: 75,
        branches: 75,
        functions: 75,
        statements: 75,
        'modules/auth/bff/**': {
          lines: 80,
          branches: 80,
          functions: 80,
          statements: 80,
        },
      },
    },
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, '.'),
    },
  },
});

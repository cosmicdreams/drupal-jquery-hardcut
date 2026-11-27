import { resolve } from 'node:path';
import { defineConfig } from '@playwright/test';

const suite = './tests/Drupal/Playwright';
const output = resolve(__dirname, '../playwright_output');

export default defineConfig({
  testDir: '.',
  testMatch: [
    'tests/Drupal/Playwright/specs/**/*.spec.ts',
    'modules/*/tests/src/Playwright/**/*.spec.ts',
    'themes/*/tests/src/Playwright/**/*.spec.ts',
  ].map((pattern) => resolve(__dirname, pattern)),
  testIgnore: ['**/node_modules/**', '**/.yarn/**'],
  globalSetup: `${suite}/global-setup.ts`,
  globalTeardown: `${suite}/global-teardown.ts`,
  timeout: 60_000,
  forbidOnly: Boolean(process.env.CI),
  outputDir: resolve(output, 'traces'),
  reporter: [
    ['list'],
    ['junit', { outputFile: resolve(output, 'junit.xml') }],
    [
      'html',
      {
        outputFolder: resolve(__dirname, '../playwright-report'),
        open: 'never',
      },
    ],
  ],
  use: {
    browserName: 'chromium',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
});

import { resolve } from 'node:path';
import { defineConfig } from '@playwright/test';

const suite = './tests/Drupal/Playwright';
const output = resolve(__dirname, '../playwright_output');

export default defineConfig({
  testDir: '.',
  testMatch: [
    '**/core/tests/Drupal/Playwright/specs/**/*.spec.ts',
    '**/core/modules/**/tests/src/Playwright/**/*.spec.ts',
    '**/core/profiles/**/tests/src/Playwright/**/*.spec.ts',
    '**/core/themes/**/tests/src/Playwright/**/*.spec.ts',
  ],
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

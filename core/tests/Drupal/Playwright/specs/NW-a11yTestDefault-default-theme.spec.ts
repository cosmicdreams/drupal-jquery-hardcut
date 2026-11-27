import { expect, test } from '../fixtures/base.fixture';

/**
 * Ports a11yTestDefault.js.
 *
 * @see https://www.drupal.org/i/3293469
 * @see https://www.drupal.org/i/3318396
 * @see https://www.drupal.org/i/3584694
 * @see https://www.drupal.org/i/3618449
 */
const defaultTheme = process.env.DRUPAL_TEST_DEFAULT_THEME;
test.use({
  siteOptions: { profile: 'playwright_a11y_testing' },
  // If a default theme other than Stark is requested, install it.
  siteThemes:
    defaultTheme && defaultTheme !== 'stark' ? { default: defaultTheme } : {},
});

const cases = [
  { name: 'Homepage', path: './' },
  { name: 'Login', path: 'user/login' },
];
// @todo Remove the disabled 'region' rule in https://drupal.org/i/3318396.
const options = { rules: { region: { enabled: false } } };

for (const { name, path } of cases) {
  test(
    `NW-a11yTestDefault-default-theme scans ${name}`,
    { tag: ['@core', '@local', '@a11y', '@a11y-default'] },
    async ({ a11yDefaultPage }) => {
      await test.step(`Open ${name} as a visitor`, async () => {
        await a11yDefaultPage.openForAccessibilityScan(path);
      });

      await test.step('Scan the body for axe violations', async () => {
        const { violations } = await a11yDefaultPage.scanAccessibility(
          'body',
          options,
        );
        expect(violations).toEqual([]);
      });
    },
  );
}

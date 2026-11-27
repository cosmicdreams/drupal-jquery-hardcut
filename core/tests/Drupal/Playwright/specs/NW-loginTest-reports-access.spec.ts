import { expect, loginSetup, test } from '../fixtures/base.fixture';

/**
 * Test login.
 *
 * @see https://www.drupal.org/project/drupal/issues/296693
 * @see https://www.drupal.org/project/drupal/issues/2973879
 * @see https://www.drupal.org/project/drupal/issues/2981652
 * @see https://www.drupal.org/project/drupal/issues/3070521
 * @see https://www.drupal.org/project/drupal/issues/3174884
 * @see https://www.drupal.org/project/drupal/issues/3265617
 */
test.use({ siteOptions: { setupFile: loginSetup } });

test(
  'NW-loginTest-reports-access',
  { tag: ['@core', '@local'] },
  async ({ reportsLoginPage }) => {
    await test.step('Open Reports as the user granted access to site reports', async () => {
      await reportsLoginPage.open('admin/reports');
      await expect(reportsLoginPage.body).toBeVisible();
      await expect(reportsLoginPage.heading).toContainText('Reports');
    });

    await test.step('Check for JavaScript deprecation warnings', async () => {
      expect(await reportsLoginPage.deprecationWarnings()).toEqual([]);
    });
  },
);

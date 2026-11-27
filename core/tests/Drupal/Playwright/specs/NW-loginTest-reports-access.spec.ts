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
  async ({ adminPage, reportsUserPage }) => {
    await test.step('Verify the administrator session on the fixture site', async () => {
      await adminPage.open('user');
      await expect.poll(() => adminPage.hasSession()).toBe(true);
    });

    await test.step('Open Reports as the user granted access to site reports', async () => {
      await reportsUserPage.open('admin/reports');
      await expect(reportsUserPage.body).toBeVisible();
      await expect(reportsUserPage.heading).toContainText('Reports');
    });

    await test.step('Check for JavaScript deprecation warnings', async () => {
      expect(await reportsUserPage.deprecationWarnings()).toEqual([]);
    });
  },
);

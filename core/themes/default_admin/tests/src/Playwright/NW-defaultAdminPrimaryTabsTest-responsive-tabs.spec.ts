import {
  expect,
  test,
} from '../../../../../tests/Drupal/Playwright/fixtures/base.fixture';

/**
 * Ports defaultAdminPrimaryTabsTest.js.
 *
 * @see https://www.drupal.org/i/3244621
 * @see https://www.drupal.org/i/3265617
 * @see https://www.drupal.org/i/3432632
 * @see https://www.drupal.org/i/3490621
 * @see https://www.drupal.org/i/3500425
 * @see https://www.drupal.org/i/3582098
 */
test.use({
  siteOptions: {
    profile: 'minimal',
    setupFile: 'core/tests/Drupal/TestSite/PlaywrightPrimaryTabsTestSetup.php',
  },
});
test.describe(
  'Default Admin primary tabs',
  {
    tag: ['@core', '@local', '@default-admin'],
  },
  () => {
    test('NW-defaultAdminPrimaryTabsTest-responsive-tabs displays tabs at desktop width', async ({
      primaryTabsTestPage,
    }) => {
      const tabs = primaryTabsTestPage.primaryTabs;
      await test.step('Verify the active and inactive tabs and hidden toggle on desktop', async () => {
        await primaryTabsTestPage.openArticle(1600);
        await expect(tabs.root).toBeVisible();
        await expect(tabs.activeTab).toBeVisible();
        await expect(tabs.inactiveTab).toBeVisible();
        await expect(tabs.toggle).toHaveCount(1);
        await expect(tabs.toggle).toBeHidden();
      });
    });

    test('NW-defaultAdminPrimaryTabsTest-responsive-tabs expands and collapses tabs at mobile width', async ({
      primaryTabsTestPage,
    }) => {
      const tabs = primaryTabsTestPage.primaryTabs;
      await test.step('Verify the initial collapsed state on mobile', async () => {
        await primaryTabsTestPage.openArticle(699);
        await expect(tabs.root).toBeVisible();
        await expect(tabs.activeTab).toBeVisible();
        await expect(tabs.inactiveTab).toHaveCount(1);
        await expect(tabs.inactiveTab).toBeHidden();
        await expect(tabs.toggle).toBeVisible();
        await expect(tabs.toggleIcon).toBeVisible();
        await expect(tabs.toggle).toHaveAttribute('aria-expanded', 'false');
      });

      await test.step('Open the primary tabs', async () => {
        await tabs.toggleButton.click();
        await expect(tabs.inactiveTab).toBeVisible();
        await expect(tabs.toggleIcon).toBeVisible();
        await expect(tabs.toggle).toHaveAttribute('aria-expanded', 'true');
      });

      await test.step('Close the primary tabs', async () => {
        await tabs.toggleButton.click();
        await expect(tabs.inactiveTab).toHaveCount(1);
        await expect(tabs.inactiveTab).toBeHidden();
        await expect(tabs.toggleIcon).toBeVisible();
        await expect(tabs.toggle).toHaveAttribute('aria-expanded', 'false');
      });
    });
  },
);

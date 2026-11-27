import {
  expect,
  test,
} from '../../../../../tests/Drupal/Playwright/fixtures/base.fixture';

/**
 * Ports the Navigation module's a11yTest.js.
 *
 * Wide viewport ("desktop") version.
 * Narrow viewport ("mobile") version.
 *
 * @see https://www.drupal.org/i/3393400
 * @see https://www.drupal.org/i/3551528
 * @see https://www.drupal.org/i/3582101
 */
test.use({
  siteOptions: { profile: 'playwright_a11y_testing', modules: ['navigation'] },
});

test(
  'NW-a11yTest-navigation-sidebar scans the account edit page at both widths',
  { tag: ['@core', '@local', '@navigation', '@a11y', '@a11y-admin'] },
  async ({ navigationPage }) => {
    const { navigation } = navigationPage;
    await test.step('Scan the account edit page at the wide viewport', async () => {
      // The original loaded the page at CI's 800 × 600 headless window before
      // resizing, so the sidebar initialized collapsed and stored that
      // preference. Seed the same collapsed preference.
      await navigationPage.resize(1220, 800);
      await navigationPage.startWithCollapsedSidebar();
      await navigationPage.open('user/1/edit');
      await expect(navigation.desktopToggle).toHaveAttribute(
        'aria-expanded',
        'false',
      );
      const { violations } = await navigationPage.scanAccessibility('body', {});
      expect.soft(violations).toEqual([]);
    });

    await test.step('Scan the account edit page with the narrow sidebar open', async () => {
      await navigationPage.resize(1000, 800);
      await expect(navigation.mobileToggle).toBeVisible();
      await expect(navigation.mobileToggle).toHaveAccessibleName(
        'Expand sidebar',
      );
      await navigation.openOnMobile();
      await expect(navigation.create).toBeVisible();
      await navigationPage.waitForSidebarAnimation();
      const { violations } = await navigationPage.scanAccessibility('body', {});
      expect(violations).toEqual([]);
    });
  },
);

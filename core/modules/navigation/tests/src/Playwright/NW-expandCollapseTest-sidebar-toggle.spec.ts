import {
  expect,
  test,
} from '../../../../../tests/Drupal/Playwright/fixtures/base.fixture';

/**
 * Ports expandCollapseTest.js.
 *
 * Historical comment on the initialization wait:
 * This pause required to wait for first init event.
 *
 * Historical comments on the two focus assertions:
 * eslint-disable-next-line no-unused-expressions
 * eslint-disable-next-line no-unused-expressions
 * @see https://www.drupal.org/i/3393400
 * @see https://www.drupal.org/i/3425081
 * @see https://www.drupal.org/i/3504722
 * @see https://www.drupal.org/i/3572628
 */
test.use({ siteOptions: { modules: ['navigation', 'big_pipe'] } });

test(
  'NW-expandCollapseTest-sidebar-toggle after a BigPipe cache clear',
  { tag: ['@core', '@local', '@navigation'] },
  async ({ navigationPage }) => {
    const { navigation, document } = navigationPage;
    await test.step('Clear caches at the wide viewport', async () => {
      await navigationPage.resize(1220, 800);
      await navigationPage.startWithCollapsedSidebar();
      await navigationPage.open('admin/config/development/performance');
      const submission = await navigationPage.submitCacheClear();
      expect(submission.status()).toBe(303);
    });

    await test.step('Verify the sidebar initializes collapsed', async () => {
      await expect(document).toHaveAttribute(
        'data-once',
        /\badmin-toolbar-document-triggers-listener\b/,
      );
      await expect(navigation.desktopExpandedToggle).toHaveCount(0);
      await expect(navigation.desktopToggle).toHaveAttribute(
        'aria-expanded',
        'false',
      );
      await expect(document).toHaveAttribute('data-admin-toolbar', 'collapsed');
    });

    await test.step('Expand the sidebar', async () => {
      await navigation.desktopToggle.click();
      await expect(navigation.desktopExpandedToggle).toHaveCount(1);
      await expect(document).toHaveAttribute('data-admin-toolbar', 'expanded');
    });

    await test.step('Collapse the sidebar', async () => {
      await navigation.desktopExpandedToggle.click();
      await expect(navigation.desktopExpandedToggle).toHaveCount(0);
      await expect(navigation.desktopToggle).toHaveAttribute(
        'aria-expanded',
        'false',
      );
      await expect(document).toHaveAttribute('data-admin-toolbar', 'collapsed');
    });
  },
);

test(
  'NW-expandCollapseTest-sidebar-toggle Escape closes the narrow sidebar and restores focus',
  { tag: ['@core', '@local', '@navigation'] },
  async ({ navigationPage }) => {
    const { navigation } = navigationPage;
    await test.step('Open the performance page at the narrow viewport', async () => {
      await navigationPage.resize(1000, 800);
      await navigationPage.open('admin/config/development/performance');
      await expect(navigation.configuration).toHaveCount(1);
      await expect(navigation.configuration).toBeHidden();
    });

    await test.step('Open the sidebar and check focus leaves the trigger', async () => {
      await navigation.openOnMobile();
      await expect(navigation.configuration).toBeVisible();
      await expect(navigation.mobileToggle).toHaveCount(1);
      await expect(navigation.mobileToggle).not.toBeFocused();
    });

    await test.step('Press Escape and verify the sidebar closes and focus returns', async () => {
      await navigationPage.pressEscape();
      await expect(navigation.configuration).toBeHidden();
      await expect(navigation.mobileToggle).toBeFocused();
    });
  },
);

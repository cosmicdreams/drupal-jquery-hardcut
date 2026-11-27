import {
  expect,
  test,
} from '../../../../../tests/Drupal/Playwright/fixtures/base.fixture';

/**
 * Ports navigationMobileFocusTrapTest.js.
 *
 * Historical animation helper documentation:
 * Waits for the sidebar animation to complete by checking that the
 * data-admin-toolbar-animating attribute is removed from the html element.
 *
 * `@param {object} browser - Nightwatch Browser object`
 * Historical inline comments, in source order:
 * eslint-disable-next-line func-names, prefer-arrow-callback
 * Set mobile viewport width (less than 1024px)
 * Verify sidebar is initially closed on mobile
 * Click burger button to open sidebar
 * Wait for animation to complete
 * Verify focus trap is active - elements outside toolbar should have inert attribute
 * eslint-disable-next-line func-names, prefer-arrow-callback
 * Get all elements that should be inert (everything except toolbar and overlay)
 * Check if these elements have the inert attribute
 * Verify elements inside the toolbar can still receive focus
 * eslint-disable-next-line func-names, prefer-arrow-callback
 * Verify overlay is not inert
 * eslint-disable-next-line func-names, prefer-arrow-callback
 * Try to focus an element that should be inert and verify it can't be focused
 * eslint-disable-next-line func-names, prefer-arrow-callback
 * Find an inert element (like the main content)
 * Try to find a focusable element within the inert container
 * Try to focus it
 * Check if it actually got focus
 * Only run this assertion if we found a focusable element
 * Close the sidebar
 * Wait for animation to complete
 * Verify inert attributes are removed when sidebar closes
 * eslint-disable-next-line func-names, prefer-arrow-callback
 * Open sidebar
 * Wait for animation to complete
 * Verify inert is active
 * eslint-disable-next-line func-names, prefer-arrow-callback
 * Click overlay to close using JavaScript since the element might be behind the sidebar
 * eslint-disable-next-line func-names, prefer-arrow-callback
 * Wait for animation to complete
 * Verify inert is removed
 * eslint-disable-next-line func-names, prefer-arrow-callback
 * Resize to desktop width
 * Wait for sidebar to be visible
 * Verify no inert attributes on desktop
 * eslint-disable-next-line func-names, prefer-arrow-callback
 * Resize back to mobile
 * Wait for mobile state - burger button should be visible
 * Open sidebar on mobile
 * Wait for animation to complete
 * Now inert should be active
 * eslint-disable-next-line func-names, prefer-arrow-callback
 * @see https://www.drupal.org/i/3443571
 */
test.use({ siteOptions: { modules: ['navigation'] } });

test(
  'NW-navigationMobileFocusTrapTest-sidebar-inert traps focus until the close button is used',
  { tag: ['@core', '@local', '@navigation'] },
  async ({ navigationPage }) => {
    const { navigation, skipLink } = navigationPage;
    await test.step('Open the homepage on mobile with the sidebar closed', async () => {
      await navigationPage.resize(375, 800);
      await navigationPage.open('./');
      await expect(navigationPage.body).toBeVisible();
      await expect(navigation.mobileToggle).toHaveAttribute(
        'aria-expanded',
        'false',
      );
      await navigationPage.focusSkipLink();
      await expect(skipLink).toBeFocused();
    });

    await test.step('Open the sidebar and verify which elements become inert', async () => {
      await navigation.openOnMobile();
      await expect(navigation.mobileToggle).toHaveAttribute(
        'aria-expanded',
        'true',
      );
      await navigationPage.waitForSidebarAnimation();
      await expect
        .poll(() => navigation.outsideElementsMissing('inert'))
        .toBe(0);
      await expect
        .poll(() =>
          navigation.outsideElementsMissing('data-admin-toolbar-inert'),
        )
        .toBe(0);
      await expect
        .poll(() => navigation.outsideElements.count())
        .toBeGreaterThan(0);
      await expect(navigation.sidebar).not.toHaveAttribute('inert', /.*/);
      await expect(navigation.overlay).not.toHaveAttribute('inert', /.*/);
    });

    await test.step('Verify the skip link cannot receive focus while inert', async () => {
      await navigationPage.focusSkipLink();
      await expect(skipLink).not.toBeFocused();
    });

    await test.step('Close the sidebar and verify the inert markers clear', async () => {
      await navigation.closeButton.click();
      await expect(navigation.mobileToggle).toHaveAttribute(
        'aria-expanded',
        'false',
      );
      await navigationPage.waitForSidebarAnimation();
      await expect(navigation.inertMarkers).toHaveCount(0);
    });
  },
);

test(
  'NW-navigationMobileFocusTrapTest-sidebar-inert releases the focus trap when the overlay is clicked',
  { tag: ['@core', '@local', '@navigation'] },
  async ({ navigationPage }) => {
    const { navigation } = navigationPage;
    await test.step('Open the sidebar on the mobile homepage', async () => {
      await navigationPage.resize(375, 800);
      await navigationPage.open('./');
      await expect(navigationPage.body).toBeVisible();
      await navigation.openOnMobile();
      await expect(navigation.mobileToggle).toHaveAttribute(
        'aria-expanded',
        'true',
      );
      await navigationPage.waitForSidebarAnimation();
      await expect
        .poll(() => navigation.inertMarkers.count())
        .toBeGreaterThan(0);
    });

    await test.step('Click the overlay and verify the focus trap clears', async () => {
      await navigation.clickOverlay();
      await expect(navigation.mobileToggle).toHaveAttribute(
        'aria-expanded',
        'false',
      );
      await navigationPage.waitForSidebarAnimation();
      await expect(navigation.inertMarkers).toHaveCount(0);
    });
  },
);

test(
  'NW-navigationMobileFocusTrapTest-sidebar-inert applies only at mobile widths',
  { tag: ['@core', '@local', '@navigation'] },
  async ({ navigationPage }) => {
    const { navigation } = navigationPage;
    await test.step('Load the homepage on mobile and resize to desktop', async () => {
      await navigationPage.resize(375, 800);
      await navigationPage.open('./');
      await expect(navigation.mobileToggle).toHaveAttribute(
        'data-once',
        /\badmin-toolbar-trigger\b/,
      );
      await navigationPage.resize(1280, 800);
      await expect(navigation.sidebar).toBeVisible();
      await expect(navigation.inertMarkers).toHaveCount(0);
    });

    await test.step('Resize back to mobile and open the sidebar', async () => {
      await navigationPage.resize(375, 800);
      await expect(navigation.mobileToggle).toBeVisible();
      await navigation.openOnMobile();
      await expect(navigation.mobileToggle).toHaveAttribute(
        'aria-expanded',
        'true',
      );
      await navigationPage.waitForSidebarAnimation();
      await expect
        .poll(() => navigation.inertMarkers.count())
        .toBeGreaterThan(0);
    });
  },
);

import {
  expect,
  test,
} from '../../../../../tests/Drupal/Playwright/fixtures/base.fixture';

/**
 * Verify that Drupal.displace() attribute is properly added by JavaScript.
 * @see https://www.drupal.org/i/3526180
 */
test.use({ siteOptions: { modules: ['navigation', 'big_pipe'] } });

test(
  'NW-navigationDisplaceTest-sidebar-offset adds the left offset to the placeholder',
  { tag: ['@core', '@local', '@navigation'] },
  async ({ navigationPage }) => {
    await test.step('Open the administration page at a wide viewport', async () => {
      await navigationPage.resize(1220, 800);
      await navigationPage.open('admin/');
    });

    await test.step('Verify the sidebar placeholder has the left displacement attribute', async () => {
      await expect(
        navigationPage.navigation.displacePlaceholder,
      ).toHaveAttribute('data-offset-left', /.*/);
    });
  },
);

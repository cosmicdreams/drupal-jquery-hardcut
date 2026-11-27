import { expect, test } from '../fixtures/base.fixture';

/**
 * Test umami profile.
 *
 * @see https://www.drupal.org/project/drupal/issues/3017176
 * @see https://www.drupal.org/project/drupal/issues/3174884
 */
test.use({
  siteOptions: {
    profile: 'demo_umami',
    setupFile: 'core/tests/Drupal/TestSite/TestSiteInstallTestScript.php',
  },
});

test(
  'NW-installProfileTest-umami-branding',
  { tag: ['@core', '@local'] },
  async ({ umamiPage }) => {
    await test.step('Open the test page on the Umami installation', async () => {
      await umamiPage.open('test-page');
      await expect(umamiPage.body).toBeVisible();
    });

    await test.step('Check that Umami renders its branding block', async () => {
      await expect(umamiPage.branding.block).toBeAttached();
    });
  },
);

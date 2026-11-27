import { expect, test } from '../fixtures/base.fixture';

/**
 * Test page with langcode.
 *
 * @see https://www.drupal.org/project/drupal/issues/3017176
 * @see https://www.drupal.org/project/drupal/issues/3122002
 * @see https://www.drupal.org/project/drupal/issues/3174884
 */
test.use({
  siteOptions: {
    langcode: 'fr',
    setupFile:
      'core/tests/Drupal/TestSite/TestSiteMultilingualInstallTestScript.php',
  },
});

test(
  'NW-langcodeTest-french-document',
  { tag: ['@core', '@local'] },
  async ({ drupalPage }) => {
    await test.step('Open the test page after installing with French translations', async () => {
      await drupalPage.open('test-page');
    });

    await test.step('Check the document language', async () => {
      await expect(drupalPage.document).toHaveAttribute('lang', 'fr');
    });
  },
);

import { expect, test } from '../fixtures/base.fixture';

/**
 * The javascript that creates dropbuttons is not present on the /page at
 * initial load.  If the once data property is added then the JS was loaded
 * and triggered on the inserted content.
 *
 * The test_htmx library currently marks .ajax-content with htmx-init.
 * @see \Drupal\test_htmx\Controller\HtmxTestAttachmentsController
 * @see core/modules/system/tests/modules/test_htmx/js/reveal-merged-settings.js
 * @see https://www.drupal.org/project/drupal/issues/3521173
 * @see https://www.drupal.org/project/drupal/issues/3522597
 * @see https://www.drupal.org/project/drupal/issues/3532159
 * @see https://www.drupal.org/project/drupal/issues/3555916
 * @see https://www.drupal.org/project/drupal/issues/3560659
 */
test.use({ siteOptions: { profile: 'minimal', modules: ['test_htmx'] } });

test(
  'NW-htmxTest-load-assets',
  { tag: ['@core', '@htmx', '@local'] },
  async ({ htmxAttachmentsPage }) => {
    const { attachments } = htmxAttachmentsPage;

    // Load the route htmx will use for the request on click and confirm the
    // markup we will be looking for is present in the source markup.
    await test.step('Open the response route directly', async () => {
      await htmxAttachmentsPage.openFixture('replace');
      await expect(htmxAttachmentsPage.body).toBeVisible();
      await expect(attachments.initializedContent).toBeAttached();
    });

    // Now load the page with the htmx enhanced button and verify the absence
    // of the markup to be inserted. Click the button
    // and check for inserted javascript and markup.
    // Also verifies a target without a swap strategy.
    await test.step('Open the fixture before the swap', async () => {
      await htmxAttachmentsPage.openFixture('page');
      await expect(htmxAttachmentsPage.body).toBeVisible();
      await attachments.expectAssetsAbsent();
      await expect(htmxAttachmentsPage.replaceButton).toBeVisible();
      await expect
        .soft(htmxAttachmentsPage.replaceButton)
        .not.toHaveAttribute('data-hx-swap');
    });

    await test.step('Request the replacement content', async () => {
      await htmxAttachmentsPage.replaceButton.click();
    });

    await test.step('Verify inserted markup, Drupal behavior and loaded assets', async () => {
      await attachments.expectVisibleInitializedContent();
      await attachments.expectAssetsPresent();
    });
  },
);

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
  'NW-htmxTest-delete-detach',
  { tag: ['@core', '@htmx', '@local'] },
  async ({ htmxAttachmentsPage }) => {
    const { attachments } = htmxAttachmentsPage;

    await test.step('Open the fixture with initialized content and a delete button', async () => {
      await htmxAttachmentsPage.openFixture('delete');
      await expect(htmxAttachmentsPage.body).toBeVisible();
      await attachments.expectVisibleInitializedContent();
      await expect(htmxAttachmentsPage.deleteButton).toBeVisible();
    });

    await test.step('Delete the initialized content', async () => {
      await htmxAttachmentsPage.deleteButton.click();
    });

    await test.step('Verify the behavior detached from the deleted content', async () => {
      await expect.soft(attachments.content).toHaveCount(0);
      await expect.soft(attachments.initializedContent).toHaveCount(0);
      await expect(htmxAttachmentsPage.body).toHaveAttribute(
        'data-htmx-behavior-test',
        'detached',
      );
    });
  },
);

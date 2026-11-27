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

const swapStrategies = [
  ['inner-html', 'innerHTML'],
  ['outer-html', 'outerHTML'],
  ['text-content', 'textContent'],
  ['beforebegin', 'beforebegin'],
  ['afterbegin', 'afterbegin'],
  ['beforeend', 'beforeend'],
  ['afterend', 'afterend'],
  ['inner-morph', 'innerMorph'],
  ['outer-morph', 'outerMorph'],
  ['outer-sync', 'outerSync'],
] as const;

// Verify each swap method.
swapStrategies.forEach(([route, strategy]) => {
  test(
    `NW-htmxTest-swap-strategies-${route}`,
    { tag: ['@core', '@htmx', '@local'] },
    async ({ htmxAttachmentsPage }) => {
      const { attachments, replaceButton } = htmxAttachmentsPage;

      // Load the page with the htmx enhanced button and verify the absence
      // of the markup to be inserted. Click the button
      // and check for inserted javascript and markup.
      await test.step('Open the fixture for the named swap strategy', async () => {
        await htmxAttachmentsPage.openFixture(`${route}/swap`);
        await expect(htmxAttachmentsPage.body).toBeVisible();
        await attachments.expectAssetsAbsent();
        await expect(replaceButton).toBeVisible();
        await expect
          .soft(replaceButton)
          .toHaveAttribute('data-hx-swap', new RegExp(`^${strategy}(?:\\s|$)`));
      });

      await test.step('Request the replacement content', async () => {
        await replaceButton.click();
      });

      if (strategy === 'textContent') {
        await test.step('Verify the target received text without the response element', async () => {
          await expect.soft(attachments.target).toHaveText('Initial Content');
          await expect(attachments.content).toHaveCount(0);
        });
      } else {
        await test.step('Verify inserted markup, Drupal behavior and loaded assets', async () => {
          await attachments.expectVisibleInitializedContent();
          await attachments.expectAssetsPresent();
        });
      }
    },
  );
});

import {
  expect,
  test,
} from '../../../../../tests/Drupal/Playwright/fixtures/base.fixture';

/**
 * Ports defaultAdminDrupalMessageTest.js.
 *
 * @see https://www.drupal.org/i/3223264
 * @see https://www.drupal.org/i/3364713
 * @see https://www.drupal.org/i/3582098
 */
test.use({
  siteOptions: { modules: ['js_message_test'] },
  siteThemes: { default: 'default_admin' },
});
test.describe(
  'Default Admin message placement',
  {
    tag: ['@core', '@local', '@default-admin'],
  },
  () => {
    test('NW-defaultAdminDrupalMessageTest-message-placement uses the default area', async ({
      messageTestPage,
    }) => {
      const messages = messageTestPage.defaultMessages;

      await test.step('Load system messages in the default area', async () => {
        await messageTestPage.openWithSystemMessages();
        await expect(messageTestPage.content).toBeVisible();
        await expect(messages.root).toHaveCount(1);

        // We should load 3 messages on page load from \Drupal::messenger().
        await expect(messages.items).toHaveCount(3);

        // We should have one message of each type.
        await expect(messages.status).toHaveCount(1);
        await expect(messages.warning).toHaveCount(1);
        await expect(messages.error).toHaveCount(1);
      });

      await test.step('Add browser messages to the default area', async () => {
        // Trigger new messages via JavaScript.
        await expect(messages.ready).toBeVisible();
        await messages.addMessages();

        // We should have 6 total messages.
        await expect(messages.items).toHaveCount(6);

        // We should have 2 messages of each type.
        await expect(messages.status).toHaveCount(2);
        await expect(messages.warning).toHaveCount(2);
        await expect(messages.error).toHaveCount(2);
      });
    });

    test('NW-defaultAdminDrupalMessageTest-message-placement uses the custom area', async ({
      messageTestPage,
    }) => {
      const messages = messageTestPage.customMessages;

      await test.step('Load system messages while the custom area stays empty', async () => {
        await messageTestPage.openWithSystemMessages();
        await expect(messageTestPage.content).toBeVisible();
        await expect(messages.root).toHaveCount(1);

        // We should load 3 messages on page load from \Drupal::messenger(),
        // all in the default area.
        await expect(messages.items).toHaveCount(0);
      });

      await test.step('Add browser messages to the custom area', async () => {
        // Trigger new messages via JavaScript.
        await expect(messages.ready).toBeVisible();
        await messages.addMessages();

        // We should have 6 total messages across both areas, with 3 here.
        await expect(messages.items).toHaveCount(3);

        // We should have 2 messages of each type across both areas, with 1 here.
        await expect(messages.status).toHaveCount(1);
        await expect(messages.warning).toHaveCount(1);
        await expect(messages.error).toHaveCount(1);
      });
    });
  },
);

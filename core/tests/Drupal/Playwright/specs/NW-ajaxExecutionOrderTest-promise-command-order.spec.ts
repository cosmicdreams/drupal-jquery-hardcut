import { expect, test } from '../fixtures/base.fixture';

/**
 * Test Execution Order.
 * @see https://www.drupal.org/project/drupal/issues/1988968
 * @see https://www.drupal.org/project/drupal/issues/3264940
 * @see https://www.drupal.org/project/drupal/issues/3413135
 */
test.use({ siteOptions: { modules: ['ajax_test'] } });

test(
  'NW-ajaxExecutionOrderTest-promise-command-order',
  { tag: ['@core', '@ajax', '@local'] },
  async ({ ajaxPromiseFormPage }) => {
    await test.step('Open the promise command form', async () => {
      await ajaxPromiseFormPage.open('ajax-test/promise-form');
      await expect(ajaxPromiseFormPage.body).toBeVisible();
    });

    await test.step('Execute the commands and check their insertion order', async () => {
      await ajaxPromiseFormPage.executeCommands.click();
      await expect(ajaxPromiseFormPage.output).toBeVisible();
      await expect(ajaxPromiseFormPage.output).toContainText('12345');
    });
  },
);

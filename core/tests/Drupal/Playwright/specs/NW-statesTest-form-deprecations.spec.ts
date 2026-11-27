import { expect, test } from '../fixtures/base.fixture';

/**
 * Covers visibility and deprecations on the full Form API States fixture.
 *
 * Historical comment on the spinner interaction:
 *
 * Emulate usage of the spinner browser widget on number inputs
 * on modern browsers.
 * @see https://www.drupal.org/project/drupal/issues/2995570
 * @see https://www.drupal.org/project/drupal/issues/3070521
 * @see https://www.drupal.org/project/drupal/issues/3174884
 * @see https://www.drupal.org/project/drupal/issues/3181634
 * @see https://www.drupal.org/project/drupal/issues/3264940
 */
test.use({ siteOptions: { modules: ['form_test'] } });

test(
  'NW-statesTest-form-deprecations',
  { tag: ['@core', '@local'] },
  async ({ statesTestPage }) => {
    const { body, conditionalTextfield, spinnerDependentItem } = statesTestPage;

    await test.step('Open the States form', async () => {
      const response = await statesTestPage.open(
        'form-test/javascript-states-form',
      );
      expect(response?.ok()).toBe(true);
      await expect(body).toBeVisible();
      await expect(conditionalTextfield).toHaveCount(1);
      await expect(conditionalTextfield).toBeHidden();
    });

    await test.step('Check that form attachment logs no deprecations', async () => {
      expect(statesTestPage.warnings).toEqual([]);
    });

    await test.step('Reveal the dependent item with the number spinner', async () => {
      await expect(spinnerDependentItem).toHaveCount(1);
      await expect(spinnerDependentItem).toBeHidden();
      await statesTestPage.incrementNumberTrigger();
      await expect(spinnerDependentItem).toBeVisible();
    });
  },
);
